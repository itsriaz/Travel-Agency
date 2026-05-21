const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('travelLauncher', {
  config: () => ipcRenderer.invoke('launcher:config'),
  openOnline: () => ipcRenderer.invoke('launcher:openOnline'),
  snapshot: () => ipcRenderer.invoke('launcher:snapshot'),
  syncDrafts: (payload) => ipcRenderer.invoke('launcher:syncDrafts', payload)
});
