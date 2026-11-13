# Easy POS – Windows desktop point-of-sale

Offline desktop POS (Electron). No database server needed – all data lives in **one file** and is backed up automatically.

## Features
- **Fast POS screen** – item grid, barcode scanner / search box, bill panel, hold & recall bills, discount (amount or %), tax, quick cash.
- **Full keyboard control** – `F2` search · `F3` customer · `F4` discount · `↑↓` select line · `+ −` qty · `*` type qty · `Del` remove · `F6` hold · `F7` recall · `F8` clear · `F9` pay · `F10` quick cash + print · `Ctrl+P` reprint · `Alt+1…8` pages · `F11` full screen · `F1` help.
- **Items** – barcode, category, unit, **cost price** and **selling price** (live margin), stock, low-stock alert, add-stock.
- **Customers & credit** – create customers (also inside the POS), credit limit, opening balance, sell on credit, receive payments, running ledger, receivables report.
- **Printing** – 58 mm and 80 mm thermal receipts, A4 invoice, choose printer, silent print, preview, reprint.
- **Reports** – profit summary (sales, cost, gross profit, expenses, **net profit**), item profit, daily chart, by cashier, stock value, customer credit. CSV export. Expenses page feeds net profit.
- **Company settings** – name, address, email, contact no., website, **logo upload**, advertisement line and invoice footer printed on every bill.
- **Users** – default login **admin / admin** (change it in Settings). Roles: Administrator, Cashier (cashier can't see cost/profit or admin pages).
- **Backups** – automatic (every N minutes when data changed, at start-up, and on exit) into a folder you choose (default `Documents\EasyPOS-Backups`), keeps the last N, one-click restore.

## Build the Windows installer (.exe)
Windows installer builds must run on Windows (or with Wine).

**Option A – GitHub (no setup):** push, open *Actions → Build POS Windows installer → Run workflow*, download the `EasyPOS-Setup` artifact (`EasyPOS-Setup-1.0.0.exe`).

**Option B – on a Windows PC:** install [Node.js 20+](https://nodejs.org), then
```bash
cd pos-desktop
npm install
npm run dist
```
The installer appears in `pos-desktop/dist/`. It lets the user choose the install folder and creates Desktop/Start-menu shortcuts.

## Run for development
```bash
npm install
npm start
npm test      # backend logic tests
```

## Where is my data?
`%APPDATA%\Easy POS\data\pos-data.json` (open it from Settings → Backup). To move to another PC: restore a backup file from Settings → Backup → *Restore from file…*.
