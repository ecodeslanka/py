'use strict';
const { app, BrowserWindow, ipcMain, dialog, Menu, shell, nativeImage } = require('electron');
const path = require('path');
const fs = require('fs');
const os = require('os');
const { createBackend } = require('./backend');

let win = null;
let backend = null;

if (!app.requestSingleInstanceLock()) { app.quit(); }
app.on('second-instance', () => { if (win) { if (win.isMinimized()) win.restore(); win.focus(); } });

// ---- Electron-only actions (dialogs, printing) ----
const M = {};

M.pickLogo = async () => {
  const r = await dialog.showOpenDialog(win, {
    title: 'Choose company logo', properties: ['openFile'],
    filters: [{ name: 'Images', extensions: ['png', 'jpg', 'jpeg', 'bmp', 'gif'] }],
  });
  if (r.canceled || !r.filePaths[0]) return null;
  let img = nativeImage.createFromPath(r.filePaths[0]);
  if (img.isEmpty()) throw new Error('Could not read that image');
  const { width } = img.getSize();
  if (width > 500) img = img.resize({ width: 500, quality: 'best' });
  return img.toDataURL(); // stored inside the data file as PNG
};

M.pickBackupDir = async () => {
  const r = await dialog.showOpenDialog(win, { title: 'Choose backup folder', properties: ['openDirectory', 'createDirectory'] });
  return r.canceled ? null : r.filePaths[0];
};

M.pickRestoreFile = async () => {
  const r = await dialog.showOpenDialog(win, {
    title: 'Choose a backup file to restore', defaultPath: backend.getBackupDir(),
    properties: ['openFile'], filters: [{ name: 'POS backup', extensions: ['json'] }],
  });
  return r.canceled ? null : r.filePaths[0];
};

M.openBackupDir = async () => {
  const dir = backend.getBackupDir();
  fs.mkdirSync(dir, { recursive: true });
  const err = await shell.openPath(dir);
  if (err) throw new Error(err);
  return true;
};

// only our own website can be opened from inside the app
M.openUrl = async (url) => {
  if (url !== 'https://ecodes.lk') throw new Error('Blocked link');
  await shell.openExternal(url);
  return true;
};

M.appInfo = () => ({ version: app.getVersion(), dataFile: backend.file, platform: process.platform });

M.printers = async () => {
  const list = await win.webContents.getPrintersAsync();
  return list.map((p) => ({ name: p.name, displayName: p.displayName || p.name, isDefault: !!p.isDefault }));
};

// Print an HTML receipt. paper: '58mm' | '80mm' | 'A4'
M.print = async (html, opts = {}) => {
  const tmp = path.join(os.tmpdir(), `pos-print-${Date.now()}.html`);
  fs.writeFileSync(tmp, html, 'utf8');
  const w = new BrowserWindow({ show: false, webPreferences: { sandbox: true, javascript: true } });
  try {
    await w.loadFile(tmp);
    await new Promise((r) => setTimeout(r, 150)); // let images decode
    const options = { silent: !!opts.silent, printBackground: true, margins: { marginType: 'none' } };
    if (opts.printer) options.deviceName = opts.printer;
    if (opts.paper === 'A4') {
      options.pageSize = 'A4';
    } else {
      const mm = opts.paper === '58mm' ? 58 : 80;
      const hpx = await w.webContents.executeJavaScript('Math.ceil(document.documentElement.scrollHeight)');
      options.pageSize = { width: mm * 1000, height: Math.max(60000, Math.ceil(hpx * 264.58) + 8000) };
    }
    await new Promise((resolve, reject) => {
      w.webContents.print(options, (ok, reason) => (ok || reason === 'cancelled' ? resolve() : reject(new Error('Print failed: ' + reason))));
    });
    return true;
  } finally {
    w.destroy();
    fs.unlink(tmp, () => {});
  }
};

function createWindow() {
  win = new BrowserWindow({
    width: 1366, height: 820, minWidth: 1024, minHeight: 640,
    backgroundColor: '#0f172a', title: 'Easy POS', show: false,
    webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false, sandbox: true },
  });
  Menu.setApplicationMenu(null);
  win.once('ready-to-show', () => { win.maximize(); win.show(); });
  win.loadFile(path.join(__dirname, 'src', 'index.html'));
  win.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
  win.webContents.on('will-navigate', (e) => e.preventDefault());
  win.webContents.on('before-input-event', (e, input) => {
    if (input.type !== 'keyDown') return;
    if (input.key === 'F11') { win.setFullScreen(!win.isFullScreen()); e.preventDefault(); }
    if (input.control && input.shift && input.key.toLowerCase() === 'i' && !app.isPackaged) win.webContents.toggleDevTools();
  });
  win.on('closed', () => { win = null; });
}

ipcMain.handle('api', async (_e, name, args) => {
  try {
    if (M[name]) {
      const data = await M[name](...(args || []));
      return { ok: true, data };
    }
    const data = backend.call(name, args);
    return { ok: true, data };
  } catch (err) {
    return { ok: false, error: err.message || String(err) };
  }
});

app.whenReady().then(() => {
  try {
    backend = createBackend({
      dataDir: path.join(app.getPath('userData'), 'data'),
      defaultBackupDir: path.join(app.getPath('documents'), 'EasyPOS-Backups'),
    });
  } catch (e) {
    dialog.showErrorBox('Easy POS', e.message);
    app.quit();
    return;
  }
  backend.startupBackup();
  createWindow();
});

let exiting = false;
app.on('before-quit', () => { if (!exiting && backend) { exiting = true; backend.exitBackup(); } });
app.on('window-all-closed', () => app.quit());
