'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('api', async (name, ...args) => {
  const res = await ipcRenderer.invoke('api', name, args);
  if (!res.ok) throw new Error(res.error);
  return res.data;
});
