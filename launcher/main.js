const { app, BrowserWindow, ipcMain, shell, session } = require('electron');
const fs = require('fs');
const path = require('path');

const defaultConfig = {
  serverUrl: 'https://noble.gt.tc',
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

function isInternalUrl(url) {
  try {
    return new URL(url).origin === new URL(config.serverUrl).origin;
  } catch {
    return false;
  }
}

function createAppWindow(initialUrl, parentWindow = null) {
  const win = new BrowserWindow({
    width: parentWindow ? 1180 : 1280,
    height: parentWindow ? 780 : 820,
    minWidth: 1024,
    minHeight: 680,
    title: config.windowTitle,
    parent: parentWindow || undefined,
    autoHideMenuBar: true,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false
    }
  });

  win.webContents.setWindowOpenHandler(({ url }) => {
    if (isInternalUrl(url)) {
      createAppWindow(url, win);
      return { action: 'deny' };
    }

    shell.openExternal(url);
    return { action: 'deny' };
  });

  win.webContents.on('did-fail-load', () => {
    if (!parentWindow) {
      win.loadFile(path.join(__dirname, 'offline.html'));
    }
  });

  win.loadURL(initialUrl);

  return win;
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
  return createAppWindow(config.serverUrl);
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
