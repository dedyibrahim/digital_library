const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('pustaka', {
    state: () => ipcRenderer.invoke('state'),
    login: data => ipcRenderer.invoke('login', data),
    folder: () => ipcRenderer.invoke('folder'),
    toggle: () => ipcRenderer.invoke('toggle'),
    sync: () => ipcRenderer.invoke('sync'),
    logout: () => ipcRenderer.invoke('logout'),
    openFolder: () => ipcRenderer.invoke('open-folder'),
    openWeb: () => ipcRenderer.invoke('open-web'),
    autoStart: value => ipcRenderer.invoke('auto-start', value),
    onStatus: callback => { ipcRenderer.on('status', (_event, state) => callback(state)); },
});
