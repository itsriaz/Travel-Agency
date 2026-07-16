const { app, BrowserWindow, ipcMain, shell, session } = require('electron');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const defaultConfig = {
  serverUrl: 'https://noble.gt.tc',
  windowTitle: 'Travel Agency Operations',
  sourceDevice: 'Windows Launcher',
  launcherToken: '',
  launcherTokenHeader: 'X-Travel-Launcher-Token',
  launcherKeyId: 'travel-launcher-1',
  launcherPrivateKeyPem: '',
  launcherPrivateKeyPath: '',
  launcherSignatureHeader: 'X-Travel-Launcher-Signature',
  launcherTimestampHeader: 'X-Travel-Launcher-Timestamp',
  launcherNonceHeader: 'X-Travel-Launcher-Nonce',
  launcherKeyIdHeader: 'X-Travel-Launcher-Key-Id'
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

async function hardRefreshWindow(win) {
  if (!win || win.isDestroyed()) {
    return;
  }

  try {
    await win.webContents.session.clearCache();
  } catch {
    // Cache clear should not block the reload path.
  }

  if (!win.isDestroyed()) {
    win.webContents.reloadIgnoringCache();
  }
}

function normalizedPem(value) {
  return String(value || '').replace(/\\n/g, '\n').trim();
}

function resolvePrivateKey() {
  const inlinePem = normalizedPem(config.launcherPrivateKeyPem);
  if (inlinePem) {
    return inlinePem;
  }

  const configuredPath = String(config.launcherPrivateKeyPath || '').trim();
  if (!configuredPath) {
    return '';
  }

  const absolutePath = path.isAbsolute(configuredPath)
    ? configuredPath
    : path.join(__dirname, configuredPath);

  if (!fs.existsSync(absolutePath)) {
    return '';
  }

  return normalizedPem(fs.readFileSync(absolutePath, 'utf8'));
}

const launcherPrivateKey = resolvePrivateKey();

function launcherTokenHeaders() {
  if (!config.launcherToken) {
    return {};
  }

  return {
    [config.launcherTokenHeader || 'X-Travel-Launcher-Token']: config.launcherToken
  };
}

function launcherSignatureHeaders(targetUrl, method = 'GET') {
  if (!launcherPrivateKey || !config.launcherKeyId) {
    return {};
  }

  const requestUrl = new URL(targetUrl);
  const requestTarget = `${requestUrl.pathname}${requestUrl.search || ''}` || '/';
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const nonce = crypto.randomBytes(16).toString('hex');
  const payload = [
    config.launcherKeyId,
    String(method || 'GET').toUpperCase(),
    requestTarget,
    timestamp,
    nonce
  ].join('\n');

  let signature = '';
  try {
    signature = crypto.sign('sha256', Buffer.from(payload, 'utf8'), launcherPrivateKey).toString('base64');
  } catch {
    return {};
  }

  return {
    [config.launcherKeyIdHeader || 'X-Travel-Launcher-Key-Id']: config.launcherKeyId,
    [config.launcherTimestampHeader || 'X-Travel-Launcher-Timestamp']: timestamp,
    [config.launcherNonceHeader || 'X-Travel-Launcher-Nonce']: nonce,
    [config.launcherSignatureHeader || 'X-Travel-Launcher-Signature']: signature
  };
}

function launcherSecurityHeaders(targetUrl, method = 'GET') {
  return {
    ...launcherTokenHeaders(),
    ...launcherSignatureHeaders(targetUrl, method)
  };
}

function installLauncherGateHeaders() {
  if (!config.launcherToken && !launcherPrivateKey) {
    return;
  }

  const origin = new URL(config.serverUrl).origin;
  session.defaultSession.webRequest.onBeforeSendHeaders((details, callback) => {
    let requestOrigin = '';
    try {
      requestOrigin = new URL(details.url).origin;
    } catch {
      requestOrigin = '';
    }

    if (requestOrigin === origin) {
      details.requestHeaders = {
        ...details.requestHeaders,
        ...launcherSecurityHeaders(details.url, details.method || 'GET')
      };
    }

    callback({ requestHeaders: details.requestHeaders });
  });
}

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

function createLoadingWindow() {
  const win = new BrowserWindow({
    width: 420,
    height: 240,
    resizable: false,
    maximizable: false,
    minimizable: false,
    fullscreenable: false,
    autoHideMenuBar: true,
    show: false,
    frame: false,
    transparent: false,
    alwaysOnTop: true,
    title: `${config.windowTitle} - Loading`,
    backgroundColor: '#eef4f9',
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false
    }
  });

  win.loadFile(path.join(__dirname, 'loading.html'));
  win.once('ready-to-show', () => {
    win.center();
    win.show();
  });

  return win;
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
    show: parentWindow ? true : false,
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

  win.webContents.on('before-input-event', (event, input) => {
    const key = String(input.key || '').toLowerCase();
    const isRefreshKey = key === 'f5'
      || ((input.control || input.meta) && key === 'r');

    if (!isRefreshKey) {
      return;
    }

    event.preventDefault();
    hardRefreshWindow(win);
  });

  if (parentWindow) {
    win.once('ready-to-show', () => {
      win.show();
      win.focus();
    });
  }

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
    ...launcherSecurityHeaders(url, options.method || 'GET'),
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
  const splash = createLoadingWindow();
  const win = createAppWindow(config.serverUrl);

  let windowShown = false;
  const revealWindow = () => {
    if (windowShown || win.isDestroyed()) {
      return;
    }

    windowShown = true;
    if (!splash.isDestroyed()) {
      splash.close();
    }

    win.maximize();
    win.show();
    win.focus();
  };

  win.once('ready-to-show', revealWindow);
  win.webContents.once('did-finish-load', revealWindow);
  win.on('closed', () => {
    if (!splash.isDestroyed()) {
      splash.close();
    }
  });

  return win;
}

function sanitizePathSegment(value, fallback = 'Ledger') {
  const normalized = String(value || '')
    .trim()
    .replace(/[<>:"/\\|?*\u0000-\u001F]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  return normalized !== '' ? normalized : fallback;
}

function timestampLabel() {
  const now = new Date();
  const pad = (value) => String(value).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}_${pad(now.getHours())}-${pad(now.getMinutes())}-${pad(now.getSeconds())}`;
}

async function exportLedgerPdfToDocuments(payload = {}) {
  const ledgerUrl = String(payload.ledgerUrl || '').trim();
  if (ledgerUrl === '') {
    throw new Error('Ledger URL is required.');
  }

  const customerName = sanitizePathSegment(payload.customerName, 'Customer');
  const invoiceNo = sanitizePathSegment(payload.invoiceNo, 'Ledger');
  const phoneDigits = String(payload.phoneDigits || '').trim();
  const folderRoot = path.join(app.getPath('documents'), 'Travel Agency Operations', 'Ledgers');
  const folderName = sanitizePathSegment(`${customerName} - ${invoiceNo}`, 'Ledger Export');
  const targetDir = path.join(folderRoot, folderName);

  await fs.promises.mkdir(targetDir, { recursive: true });

  const fileName = sanitizePathSegment(`${invoiceNo} - ${customerName} - ${timestampLabel()}`, 'Ledger') + '.pdf';
  const filePath = path.join(targetDir, fileName);

  const exportWindow = new BrowserWindow({
    show: false,
    width: 1400,
    height: 900,
    autoHideMenuBar: true,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      preload: path.join(__dirname, 'preload.js')
    }
  });

  try {
    await exportWindow.loadURL(ledgerUrl);
    await new Promise((resolve) => setTimeout(resolve, 700));
    const pdfBuffer = await exportWindow.webContents.printToPDF({
      printBackground: true,
      landscape: true,
      pageSize: 'A4',
      margins: {
        top: 0,
        bottom: 0,
        left: 0,
        right: 0
      }
    });

    await fs.promises.writeFile(filePath, pdfBuffer);
  } finally {
    if (!exportWindow.isDestroyed()) {
      exportWindow.close();
    }
  }

  shell.showItemInFolder(filePath);

  let whatsappUrl = '';
  if (phoneDigits !== '') {
    const messageParts = [
      payload.customerName ? `Account ledger for ${String(payload.customerName).trim()}` : 'Account ledger',
      payload.invoiceNo ? `(${String(payload.invoiceNo).trim()})` : '',
      `has been saved as ${path.basename(filePath)}.`,
      'Please attach the saved PDF from the opened folder.'
    ].filter((part) => part !== '');
    whatsappUrl = `https://wa.me/${encodeURIComponent(phoneDigits)}?text=${encodeURIComponent(messageParts.join(' '))}`;
  } else {
    whatsappUrl = 'https://web.whatsapp.com/';
  }
  shell.openExternal(whatsappUrl);

  return {
    ok: true,
    filePath,
    directory: targetDir,
    whatsappUrl
  };
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

ipcMain.handle('launcher:hardRefresh', async () => {
  const focused = BrowserWindow.getFocusedWindow();
  if (focused) {
    await hardRefreshWindow(focused);
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

ipcMain.handle('launcher:saveLedgerPdfAndOpenWhatsApp', async (_event, payload) => exportLedgerPdfToDocuments(payload));

app.whenReady().then(() => {
  installLauncherGateHeaders();
  createWindow();
});

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
