const { app, BrowserWindow, ipcMain, shell, session } = require('electron');
const fs = require('fs');
const path = require('path');

const defaultConfig = {
  serverUrl: 'http://localhost/Travel-Agency',
  windowTitle: 'Travel Agency Operations',
  sourceDevice: 'Windows Launcher'
};

function readConfig() {
  const configPath = path.join(__dirname, 'launcher-config.json');
  if (!fs.existsSync(configPath)) {
    return defaultConfig;
  }

  try {
    return { ...defaultConfig, ...JSON.parse(fs.readFileSync(configPath, 'utf8')) };
  } catch {
    return defaultConfig;
  }
}

const config = readConfig();

function serverUrl(pathname = '/') {
  return new URL(pathname, config.serverUrl.replace(/\/+$/, '/') + '/').toString();
}

async function cookieHeaderFor(url) {
  const cookies = await session.defaultSession.cookies.get({ url });
  return cookies.map((cookie) => `${cookie.name}=${cookie.value}`).join('; ');
}

async function serverRequest(pathname, options = {}) {
  const url = serverUrl(pathname);
  const headers = {
    Accept: 'application/json',
    ...(options.headers || {})
  };
  const cookieHeader = await cookieHeaderFor(url);
  if (cookieHeader !== '') {
    headers.Cookie = cookieHeader;
  }

  const response = await fetch(url, {
    ...options,
    headers,
    redirect: 'manual'
  });

  if (response.status >= 300 && response.status < 400) {
    return { ok: false, needsLogin: true, status: response.status };
  }

  const text = await response.text();
  let payload = null;
  try {
    payload = text !== '' ? JSON.parse(text) : null;
  } catch {
    payload = { raw: text };
  }

  return {
    ok: response.ok,
    status: response.status,
    payload
  };
}

function createWindow() {
  const win = new BrowserWindow({
    width: 1280,
    height: 820,
    minWidth: 1024,
    minHeight: 680,
    title: config.windowTitle,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false
    }
  });

  win.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });

  win.webContents.on('did-fail-load', () => {
    win.loadFile(path.join(__dirname, 'offline.html'));
  });

  win.loadURL(config.serverUrl);
}

ipcMain.handle('launcher:config', async () => ({
  serverUrl: config.serverUrl,
  windowTitle: config.windowTitle,
  sourceDevice: config.sourceDevice
}));

ipcMain.handle('launcher:openOnline', async () => {
  const focused = BrowserWindow.getFocusedWindow();
  if (focused) {
    await focused.loadURL(config.serverUrl);
  }
});

ipcMain.handle('launcher:snapshot', async () => serverRequest('/offline/snapshot'));

ipcMain.handle('launcher:syncDrafts', async (_event, payload) => serverRequest('/offline/drafts/sync', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    _token: payload.csrfToken,
    source_device: config.sourceDevice,
    drafts: payload.drafts
  })
}));

app.whenReady().then(createWindow);

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

app.on('activate', () => {
  if (BrowserWindow.getAllWindows().length === 0) {
    createWindow();
  }
});
