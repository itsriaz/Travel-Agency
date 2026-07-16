const { app, BrowserWindow, session } = require('electron');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const defaultLauncherConfig = {
  serverUrl: 'http://localhost/Travel-Agency',
  windowTitle: 'Travel Agency Operations',
  sourceDevice: 'Workflow Audit',
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

const defaultAuditConfig = {
  visible: true,
  loginTimeoutMs: 300000,
  stepTimeoutMs: 20000,
  postActionPauseMs: 600,
  bookingReference: '',
  customerSearchTerm: '',
  supplierSearchTerm: '',
  expectedPassengerName: '',
  expectedRoute: '',
  writeAudit: {
    enabled: false,
    leadTravelerName: 'AUDIT CUSTOMER',
    passengerName: 'AUDIT PASSENGER',
    mobile: '03001234567',
    supplierNameContains: '',
    ticketType: 'local',
    ticketClass: 'economy',
    departureDate: '',
    route: 'ISB/DXB/ISB',
    marketFare: 1000,
    serviceCharge: 100,
    receivedAmount: 1100,
    paymentMethod: 'cash'
  }
};

function parseArgs(argv) {
  const parsed = {};
  for (const arg of argv) {
    if (!arg.startsWith('--')) {
      continue;
    }
    const eqIndex = arg.indexOf('=');
    if (eqIndex === -1) {
      parsed[arg.slice(2)] = true;
      continue;
    }
    parsed[arg.slice(2, eqIndex)] = arg.slice(eqIndex + 1);
  }
  return parsed;
}

function deepMerge(base, extra) {
  const output = Array.isArray(base) ? [...base] : { ...base };
  if (!extra || typeof extra !== 'object') {
    return output;
  }

  for (const [key, value] of Object.entries(extra)) {
    if (
      value
      && typeof value === 'object'
      && !Array.isArray(value)
      && output[key]
      && typeof output[key] === 'object'
      && !Array.isArray(output[key])
    ) {
      output[key] = deepMerge(output[key], value);
    } else {
      output[key] = value;
    }
  }

  return output;
}

function readJsonFileIfExists(filePath) {
  if (!fs.existsSync(filePath)) {
    return null;
  }

  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (error) {
    throw new Error(`Could not parse JSON file: ${filePath} (${error.message})`);
  }
}

function normalizedPem(value) {
  return String(value || '').replace(/\\n/g, '\n').trim();
}

function resolvePrivateKey(config) {
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

function launcherTokenHeaders(config) {
  if (!config.launcherToken) {
    return {};
  }

  return {
    [config.launcherTokenHeader || 'X-Travel-Launcher-Token']: config.launcherToken
  };
}

function launcherSignatureHeaders(config, launcherPrivateKey, targetUrl, method = 'GET') {
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

function launcherSecurityHeaders(config, launcherPrivateKey, targetUrl, method = 'GET') {
  return {
    ...launcherTokenHeaders(config),
    ...launcherSignatureHeaders(config, launcherPrivateKey, targetUrl, method)
  };
}

function installLauncherGateHeaders(config, launcherPrivateKey) {
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
        ...launcherSecurityHeaders(config, launcherPrivateKey, details.url, details.method || 'GET')
      };
    }

    callback({ requestHeaders: details.requestHeaders });
  });
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function normalizeUrl(config, pathname) {
  return new URL(pathname, config.serverUrl.replace(/\/+$/, '/') + '/').toString();
}

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

async function getLocation(win) {
  return win.webContents.executeJavaScript('window.location.href', true);
}

async function waitForUrl(win, predicate, timeoutMs, label) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const href = await getLocation(win);
    if (predicate(href)) {
      return href;
    }
    await sleep(250);
  }

  throw new Error(`Timed out waiting for URL condition: ${label}`);
}

async function evaluate(win, fnSource, arg = null) {
  const payload = JSON.stringify(arg);
  return win.webContents.executeJavaScript(`(${fnSource})(${payload})`, true);
}

async function waitForDomCondition(win, fnSource, arg, timeoutMs, label) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const result = await evaluate(win, fnSource, arg);
    if (result && result.ok) {
      return result;
    }
    await sleep(250);
  }

  const finalResult = await evaluate(win, fnSource, arg);
  throw new Error(`Timed out waiting for DOM condition: ${label} (${JSON.stringify(finalResult)})`);
}

async function auditStep(label, fn) {
  process.stdout.write(`\n[STEP] ${label}\n`);
  const result = await fn();
  if (typeof result === 'string' && result.trim() !== '') {
    process.stdout.write(`       ${result}\n`);
  }
}

const domFns = {
  workspaceReady: String(function workspaceReady() {
    const station = document.querySelector('[data-workspace-station]');
    return {
      ok: station instanceof HTMLElement,
      url: window.location.href
    };
  }),
  clickSelector: String(function clickSelector(selector) {
    const node = document.querySelector(selector);
    if (!(node instanceof HTMLElement)) {
      return { ok: false, selector, message: 'Element not found' };
    }
    node.click();
    return { ok: true, selector };
  }),
  elementVisible: String(function elementVisible(selector) {
    const node = document.querySelector(selector);
    if (!(node instanceof HTMLElement)) {
      return { ok: false, selector, message: 'Element not found' };
    }
    const visible = !node.hidden && node.getAttribute('aria-hidden') !== 'true';
    return { ok: visible, selector, text: (node.textContent || '').trim().slice(0, 160) };
  }),
  fillField: String(function fillField(input) {
    const node = document.querySelector(input.selector);
    if (!(node instanceof HTMLInputElement || node instanceof HTMLTextAreaElement || node instanceof HTMLSelectElement)) {
      return { ok: false, selector: input.selector, message: 'Field not found' };
    }
    node.focus();
    node.value = String(input.value ?? '');
    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
    return { ok: true, selector: input.selector, value: node.value };
  }),
  chooseSelectOption: String(function chooseSelectOption(input) {
    const node = document.querySelector(input.selector);
    if (!(node instanceof HTMLSelectElement)) {
      return { ok: false, selector: input.selector, message: 'Select not found' };
    }
    const matcher = String(input.contains || '').trim().toLowerCase();
    const option = Array.from(node.options).find((candidate) => {
      const value = String(candidate.value || '').trim().toLowerCase();
      const label = String(candidate.textContent || '').trim().toLowerCase();
      if (!value || value.startsWith('__add_')) {
        return false;
      }
      return matcher === '' || value.includes(matcher) || label.includes(matcher);
    });
    if (!option) {
      return { ok: false, selector: input.selector, message: 'No matching option found' };
    }
    node.value = option.value;
    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
    return { ok: true, selector: input.selector, value: node.value, label: option.textContent.trim() };
  }),
  modalSearchResults: String(function modalSearchResults(input) {
    const field = document.querySelector(input.searchSelector);
    if (!(field instanceof HTMLInputElement)) {
      return { ok: false, message: 'Search field not found' };
    }
    field.focus();
    field.value = String(input.term || '');
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', bubbles: true }));
    field.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', code: 'Enter', bubbles: true }));
    return { ok: true, term: field.value };
  }),
  searchFeedbackLoaded: String(function searchFeedbackLoaded(input) {
    const feedback = document.querySelector(input.feedbackSelector);
    const tbody = document.querySelector(input.bodySelector);
    const rows = tbody ? Array.from(tbody.querySelectorAll('tr')) : [];
    const text = feedback instanceof HTMLElement ? String(feedback.textContent || '').trim() : '';
    const bodyText = tbody instanceof HTMLElement ? String(tbody.textContent || '').trim() : '';
    const hasMeaningfulRows = rows.some((row) => !/loading/i.test(String(row.textContent || '').trim()));
    const ready = hasMeaningfulRows || /no .*found|matches|available|open/i.test(text) || /no .*found|matches|available|open/i.test(bodyText);
    return {
      ok: ready,
      feedback: text,
      rowCount: rows.length,
      bodyText: bodyText.slice(0, 160)
    };
  }),
  reminderToggleState: String(function reminderToggleState() {
    const panel = document.querySelector('#dock-panel-reminders');
    if (!(panel instanceof HTMLElement)) {
      return { ok: false, message: 'Reminder panel missing' };
    }
    return {
      ok: !panel.hidden,
      hidden: panel.hidden
    };
  }),
  paymentContext: String(function paymentContext() {
    const printButton = document.querySelector('[data-payment-action="print-receipt"]');
    const latestReceiptId = printButton instanceof HTMLElement ? String(printButton.dataset.paymentLatestReceiptId || '').trim() : '';
    const latestPrintUrl = printButton instanceof HTMLElement ? String(printButton.dataset.paymentPrintUrl || '').trim() : '';
    return {
      ok: true,
      latestReceiptId,
      latestPrintUrl
    };
  }),
  cashAccountVisibleAndPopulated: String(function cashAccountVisibleAndPopulated() {
    const row = document.querySelector('[data-payment-treasury-row]');
    const select = document.querySelector('[data-payment-treasury-select]');
    const visible = row instanceof HTMLElement && !row.hidden;
    const options = select instanceof HTMLSelectElement ? Array.from(select.options).filter((option) => String(option.value || '').trim() !== '') : [];
    return {
      ok: visible && options.length > 0,
      visible,
      optionCount: options.length
    };
  }),
  invoiceSnapshot: String(function invoiceSnapshot() {
    const bookingField = document.querySelector('input[name="lead_traveler_name"]');
    const passengerField = document.querySelector('input[name="service_passenger_name"]');
    const invoiceNumber = document.querySelector('[data-invoice-number-display]');
    return {
      ok: true,
      leadTraveler: bookingField instanceof HTMLInputElement ? bookingField.value.trim() : '',
      passenger: passengerField instanceof HTMLInputElement ? passengerField.value.trim() : '',
      invoiceNumber: invoiceNumber instanceof HTMLElement ? String(invoiceNumber.textContent || '').trim() : ''
    };
  }),
  ticketRouteValue: String(function ticketRouteValue() {
    const field = document.querySelector('[data-ticket-route-display]');
    if (!(field instanceof HTMLInputElement)) {
      return { ok: false, message: 'Route field not found' };
    }
    return { ok: true, value: field.value };
  }),
  submitPayment: String(function submitPayment() {
    const button = document.querySelector('[data-payment-submit-action="save"]');
    if (!(button instanceof HTMLButtonElement)) {
      return { ok: false, message: 'Save Payment button not found' };
    }
    button.click();
    return { ok: true };
  }),
  paymentSavedState: String(function paymentSavedState() {
    const printButton = document.querySelector('[data-payment-action="print-receipt"]');
    const invoiceNumber = document.querySelector('[data-invoice-number-display]');
    const receiptId = printButton instanceof HTMLElement ? String(printButton.dataset.paymentLatestReceiptId || '').trim() : '';
    const printUrl = printButton instanceof HTMLElement ? String(printButton.dataset.paymentPrintUrl || '').trim() : '';
    const invoiceText = invoiceNumber instanceof HTMLElement ? String(invoiceNumber.textContent || '').trim() : '';
    return {
      ok: receiptId !== '' && printUrl !== '' && !/^draft$/i.test(invoiceText),
      receiptId,
      printUrl,
      invoiceText
    };
  }),
  summaryText: String(function summaryText() {
    const feedback = document.querySelector('.workspace-feedback');
    return {
      ok: true,
      text: feedback instanceof HTMLElement ? String(feedback.textContent || '').trim().slice(0, 240) : ''
    };
  })
};

async function runReadOnlyAudit(win, config, auditConfig) {
  const stepTimeoutMs = Number(auditConfig.stepTimeoutMs || 20000);
  const pauseMs = Number(auditConfig.postActionPauseMs || 600);

  if (auditConfig.bookingReference) {
    await win.loadURL(normalizeUrl(config, `/workspace?q=${encodeURIComponent(auditConfig.bookingReference)}`));
  } else {
    await win.loadURL(normalizeUrl(config, '/workspace'));
  }

  await waitForDomCondition(win, domFns.workspaceReady, null, stepTimeoutMs, 'workspace ready');

  await auditStep('Receive Customer Payment modal opens and searches', async () => {
    await evaluate(win, domFns.clickSelector, '[data-workspace-action="customer-dues-finder"]');
    await waitForDomCondition(win, domFns.elementVisible, '[data-customer-dues-modal]', stepTimeoutMs, 'customer dues modal visible');
    if (auditConfig.customerSearchTerm) {
      await evaluate(win, domFns.modalSearchResults, {
        searchSelector: '[data-customer-dues-search]',
        term: auditConfig.customerSearchTerm
      });
      const result = await waitForDomCondition(win, domFns.searchFeedbackLoaded, {
        feedbackSelector: '[data-customer-dues-feedback]',
        bodySelector: '[data-customer-dues-results-body]'
      }, stepTimeoutMs, 'customer dues search results');
      return `rows=${result.rowCount}, feedback="${result.feedback || result.bodyText}"`;
    }
    return 'modal opened';
  });

  await auditStep('Customer dues modal closes', async () => {
    await evaluate(win, domFns.clickSelector, '[data-customer-dues-close]');
    await sleep(pauseMs);
    return 'closed';
  });

  await auditStep('Find Supplier Payment modal opens and searches', async () => {
    await evaluate(win, domFns.clickSelector, '[data-workspace-action="supplier-history-finder"]');
    await waitForDomCondition(win, domFns.elementVisible, '[data-supplier-history-modal]', stepTimeoutMs, 'supplier history modal visible');
    if (auditConfig.supplierSearchTerm) {
      await evaluate(win, domFns.modalSearchResults, {
        searchSelector: '[data-supplier-history-search]',
        term: auditConfig.supplierSearchTerm
      });
      const result = await waitForDomCondition(win, domFns.searchFeedbackLoaded, {
        feedbackSelector: '[data-supplier-history-feedback]',
        bodySelector: '[data-supplier-history-results-body]'
      }, stepTimeoutMs, 'supplier history search results');
      return `rows=${result.rowCount}, feedback="${result.feedback || result.bodyText}"`;
    }
    return 'modal opened';
  });

  await auditStep('Supplier history modal closes', async () => {
    await evaluate(win, domFns.clickSelector, '[data-supplier-history-close]');
    await sleep(pauseMs);
    return 'closed';
  });

  await auditStep('Reminders toggle opens and closes cleanly', async () => {
    await evaluate(win, domFns.clickSelector, '[data-workspace-action="add-reminder"]');
    await waitForDomCondition(win, domFns.reminderToggleState, null, stepTimeoutMs, 'reminder panel open');
    await evaluate(win, domFns.clickSelector, '[data-workspace-action="add-reminder"]');
    await sleep(pauseMs);
    return 'toggled twice without runtime interruption';
  });

  await auditStep('Payment history modal opens when booking context exists', async () => {
    const context = await evaluate(win, domFns.paymentContext, null);
    if (!context.latestReceiptId) {
      return 'skipped because no latest receipt is loaded for this booking context';
    }
    await evaluate(win, domFns.clickSelector, '[data-payment-action="payment-history"]');
    await waitForDomCondition(win, domFns.elementVisible, '[data-payment-history-modal]', stepTimeoutMs, 'payment history modal visible');
    await evaluate(win, domFns.clickSelector, '[data-payment-history-close]');
    await sleep(pauseMs);
    return `latestReceiptId=${context.latestReceiptId}`;
  });
}

async function runWriteAudit(win, config, auditConfig, spawnedWindows) {
  const stepTimeoutMs = Number(auditConfig.stepTimeoutMs || 20000);
  const pauseMs = Number(auditConfig.postActionPauseMs || 600);
  const writeConfig = auditConfig.writeAudit || {};

  await win.loadURL(normalizeUrl(config, '/workspace?new=1&focus=customer'));
  await waitForDomCondition(win, domFns.workspaceReady, null, stepTimeoutMs, 'new invoice workspace ready');

  const uniqueSuffix = Date.now().toString().slice(-6);
  const leadTravelerName = `${String(writeConfig.leadTravelerName || 'AUDIT CUSTOMER')} ${uniqueSuffix}`;
  const passengerName = `${String(writeConfig.passengerName || 'AUDIT PASSENGER')} ${uniqueSuffix}`;
  const departureDate = String(writeConfig.departureDate || todayIso());

  await auditStep('Populate dummy invoice fields through real workspace controls', async () => {
    await evaluate(win, domFns.fillField, { selector: 'input[name="lead_traveler_name"]', value: leadTravelerName });
    await evaluate(win, domFns.fillField, { selector: 'input[name="service_passenger_name"]', value: passengerName });
    await evaluate(win, domFns.fillField, { selector: 'input[name="ticket_number"]', value: `AUD-${uniqueSuffix}` });
    await evaluate(win, domFns.fillField, { selector: 'input[name="ticket_pnr"]', value: `PNR${uniqueSuffix}` });
    await evaluate(win, domFns.chooseSelectOption, { selector: '#legacy-service-form [name="supplier_name"]', contains: writeConfig.supplierNameContains || '' });
    await evaluate(win, domFns.fillField, { selector: 'select[name="ticket_type"]', value: String(writeConfig.ticketType || 'local') });
    await evaluate(win, domFns.fillField, { selector: 'select[name="ticket_class"]', value: String(writeConfig.ticketClass || 'economy') });
    await evaluate(win, domFns.fillField, { selector: 'input[name="ticket_departure_date"]', value: departureDate });
    await evaluate(win, domFns.fillField, { selector: '[data-ticket-route-display]', value: String(writeConfig.route || 'ISB/DXB/ISB') });
    await evaluate(win, domFns.fillField, { selector: '#commercial-sale-price', value: String(writeConfig.marketFare || 1000) });
    await evaluate(win, domFns.fillField, { selector: '#commercial-service-charge', value: String(writeConfig.serviceCharge || 100) });
    await evaluate(win, domFns.fillField, { selector: '[data-payment-focus="received_amount"]', value: String(writeConfig.receivedAmount || 1100) });
    await evaluate(win, domFns.fillField, { selector: 'select[name="payment_method"]', value: String(writeConfig.paymentMethod || 'cash') });
    const routeState = await evaluate(win, domFns.ticketRouteValue, null);
    return `route=${routeState.value}`;
  });

  await auditStep('Cash or bank account becomes visible and populated', async () => {
    const result = await waitForDomCondition(win, domFns.cashAccountVisibleAndPopulated, null, stepTimeoutMs, 'cash account visible');
    return `options=${result.optionCount}`;
  });

  await auditStep('Save Payment completes and creates a printable receipt', async () => {
    await evaluate(win, domFns.submitPayment, null);
    const result = await waitForDomCondition(win, domFns.paymentSavedState, null, stepTimeoutMs, 'payment saved state');
    return `invoice=${result.invoiceText}, receipt=${result.receiptId}`;
  });

  await auditStep('Print Receipt opens a child window with receipt context', async () => {
    const beforeCount = spawnedWindows.length;
    await evaluate(win, domFns.clickSelector, '[data-payment-action="print-receipt"]');
    const started = Date.now();
    while (Date.now() - started < stepTimeoutMs) {
      if (spawnedWindows.length > beforeCount) {
        const child = spawnedWindows[spawnedWindows.length - 1];
        await waitForUrl(child, (href) => /receipt|report|print/i.test(href), stepTimeoutMs, 'receipt window url');
        const href = await getLocation(child);
        return href;
      }
      await sleep(250);
    }
    throw new Error('Print Receipt did not open a child window.');
  });
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const launcherConfigPath = path.join(__dirname, 'launcher-config.json');
  const launcherConfig = deepMerge(
    defaultLauncherConfig,
    readJsonFileIfExists(launcherConfigPath) || {}
  );

  const auditConfigPath = args.config
    ? path.resolve(__dirname, String(args.config))
    : path.join(__dirname, 'workflow-audit.config.json');
  const fileAuditConfig = readJsonFileIfExists(auditConfigPath) || {};
  const auditConfig = deepMerge(
    defaultAuditConfig,
    deepMerge(fileAuditConfig, {
      visible: args.headless ? false : undefined,
      bookingReference: args.booking || undefined,
      customerSearchTerm: args.customerSearch || undefined,
      supplierSearchTerm: args.supplierSearch || undefined,
      writeAudit: {
        enabled: Boolean(args.write || fileAuditConfig?.writeAudit?.enabled)
      }
    })
  );

  const launcherPrivateKey = resolvePrivateKey(launcherConfig);
  app.setName('Travel Agency Operations');
  await app.whenReady();
  installLauncherGateHeaders(launcherConfig, launcherPrivateKey);

  const spawnedWindows = [];
  app.on('browser-window-created', (_event, window) => {
    spawnedWindows.push(window);
  });

  const renderErrors = [];
  const consoleErrors = [];
  const win = new BrowserWindow({
    width: 1440,
    height: 960,
    show: Boolean(auditConfig.visible),
    autoHideMenuBar: true,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false
    }
  });
  spawnedWindows.push(win);

  win.webContents.on('console-message', (_event, level, message) => {
    if (level >= 2) {
      consoleErrors.push(message);
    }
  });
  win.webContents.on('render-process-gone', (_event, details) => {
    renderErrors.push(`render-process-gone:${details.reason || 'unknown'}`);
  });
  win.webContents.on('did-fail-load', (_event, errorCode, errorDescription) => {
    renderErrors.push(`did-fail-load:${errorCode}:${errorDescription}`);
  });

  await win.loadURL(normalizeUrl(launcherConfig, '/workspace'));

  const loginHref = await getLocation(win);
  if (/\/login/i.test(loginHref)) {
    process.stdout.write('\n[INFO] Login page detected. Complete login and 2FA in the audit window.\n');
    await waitForUrl(
      win,
      (href) => /\/workspace/i.test(href) && !/\/login/i.test(href),
      Number(auditConfig.loginTimeoutMs || 300000),
      'manual login to workspace'
    );
  }

  const failures = [];
  try {
    await runReadOnlyAudit(win, launcherConfig, auditConfig);

    if (auditConfig.writeAudit && auditConfig.writeAudit.enabled) {
      await runWriteAudit(win, launcherConfig, auditConfig, spawnedWindows);
    }
  } catch (error) {
    failures.push(error instanceof Error ? error.message : String(error));
  }

  if (consoleErrors.length) {
    failures.push(`Console errors: ${consoleErrors.slice(0, 5).join(' | ')}`);
  }
  if (renderErrors.length) {
    failures.push(`Renderer errors: ${renderErrors.join(' | ')}`);
  }

  if (failures.length) {
    process.stderr.write('\nWorkflow audit failed:\n');
    failures.forEach((failure) => process.stderr.write(` - ${failure}\n`));
    app.exit(1);
    return;
  }

  process.stdout.write('\nWorkflow audit passed.\n');
  app.exit(0);
}

main().catch((error) => {
  process.stderr.write(`Workflow audit fatal error: ${error instanceof Error ? error.stack || error.message : String(error)}\n`);
  app.exit(1);
});
