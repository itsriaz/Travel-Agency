const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('travelLauncher', {
  config: () => ipcRenderer.invoke('launcher:config'),
  openOnline: () => ipcRenderer.invoke('launcher:openOnline'),
  hardRefresh: () => ipcRenderer.invoke('launcher:hardRefresh'),
  snapshot: () => ipcRenderer.invoke('launcher:snapshot'),
  syncDrafts: (payload) => ipcRenderer.invoke('launcher:syncDrafts', payload),
  saveLedgerPdfAndOpenWhatsApp: (payload) => ipcRenderer.invoke('launcher:saveLedgerPdfAndOpenWhatsApp', payload)
});
