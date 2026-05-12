import { chromium } from 'file:///C:/Users/Laptop%20Valley/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs';

const baseUrl = process.env.RECOVERY_BASE_URL || 'http://localhost/Travel-Agency';
const loginName = process.env.RECOVERY_LOGIN || 'employee';
const loginPassword = process.env.RECOVERY_PASSWORD || 'EmployeePass!123';
const replacementPassword = process.env.RECOVERY_NEW_PASSWORD || 'EmployeePass!123A';
const userDataDir = process.env.RECOVERY_PROFILE_DIR || 'C:\\xampp\\htdocs\\Travel-Agency\\storage\\playwright-recovery-profile';
const injectedSessionId = process.env.RECOVERY_SESSION_ID || '';
const injectedSessionName = process.env.RECOVERY_SESSION_NAME || 'travel_ops_session';

const context = await chromium.launchPersistentContext(userDataDir, {
  channel: 'chrome',
  headless: true,
  args: ['--no-sandbox'],
});

const page = context.pages()[0] || await context.newPage();

const getCsrfValue = async () => {
  const input = await page.locator('input[name="_token"]').first();
  return input ? await input.inputValue() : '';
};

const generateTotp = (secret) => {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const normalized = String(secret || '').replace(/\s+/g, '').toUpperCase();
  let bits = '';
  for (const char of normalized) {
    const index = alphabet.indexOf(char);
    if (index >= 0) {
      bits += index.toString(2).padStart(5, '0');
    }
  }

  const bytes = [];
  for (let offset = 0; offset + 8 <= bits.length; offset += 8) {
    bytes.push(Number.parseInt(bits.slice(offset, offset + 8), 2));
  }

  const secretBytes = new Uint8Array(bytes);
  const counter = Math.floor(Date.now() / 30000);
  const buffer = new ArrayBuffer(8);
  const view = new DataView(buffer);
  view.setUint32(4, counter, false);

  return crypto.subtle.importKey(
    'raw',
    secretBytes,
    { name: 'HMAC', hash: 'SHA-1' },
    false,
    ['sign'],
  ).then((key) => crypto.subtle.sign('HMAC', key, buffer)).then((signature) => {
    const bytesView = new Uint8Array(signature);
    const offset = bytesView[bytesView.length - 1] & 0x0f;
    const binary = ((bytesView[offset] & 0x7f) << 24)
      | (bytesView[offset + 1] << 16)
      | (bytesView[offset + 2] << 8)
      | bytesView[offset + 3];
    return String(binary % 1000000).padStart(6, '0');
  });
};

const ensureAuthenticatedWorkspace = async () => {
  if (injectedSessionId !== '') {
    await context.addCookies([
      {
        name: injectedSessionName,
        value: injectedSessionId,
        url: baseUrl,
        httpOnly: true,
        sameSite: 'Lax',
      },
    ]);
  }

  await page.goto(`${baseUrl}/workspace?debug_ui=1`, { waitUntil: 'networkidle' });

  if (!page.url().includes('/login')) {
    return;
  }

  await page.fill('input[name="login"]', loginName);
  await page.fill('input[name="password"]', loginPassword);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');

  if (page.url().includes('/force-password-change')) {
    await page.fill('input[name="current_password"]', loginPassword);
    await page.fill('input[name="password"]', replacementPassword);
    await page.fill('input[name="password_confirmation"]', replacementPassword);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
  }

  if (page.url().includes('/2fa/setup')) {
    const secret = await page.locator('code').innerText();
    const otp = await generateTotp(secret);
    await page.fill('input[name="otp"]', otp);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
  }

  if (page.url().includes('/2fa/recovery-codes')) {
    await page.click('a.btn.btn-primary');
    await page.waitForLoadState('networkidle');
  }

  if (page.url().includes('/2fa/verify')) {
    throw new Error('2FA verify page still active; recovery probe does not yet bypass an existing verification challenge.');
  }

  if (!page.url().includes('/workspace')) {
    await page.goto(`${baseUrl}/workspace?debug_ui=1`, { waitUntil: 'networkidle' });
  }
};

const evaluateDebug = async () => page.evaluate(() => {
  if (typeof window.debugWorkspaceCalc === 'function') {
    return window.debugWorkspaceCalc();
  }
  return {
    debugAvailable: false,
    location: window.location.href,
    serviceType: document.querySelector('[data-service-field="type"]')?.value || null,
    saleValue: document.querySelector('#commercial-sale-price')?.value || null,
    taxValue: document.querySelector('#commercial-taxes')?.value || null,
    vatInputValue: document.querySelector('#commercial-vat-input')?.value || null,
    serviceChargeValue: document.querySelector('#commercial-service-charge')?.value || null,
    purchaseCostValue: document.querySelector('#commercial-purchase-cost')?.value || null,
    manualOverride: document.querySelector('#commercial-final-sale-price')?.dataset.manualOverride || null,
    debugBlock: document.querySelector('[data-commercial-debug]')?.textContent || null,
  };
});

const evaluatePaymentSubmitDebug = async () => page.evaluate(() => {
  if (typeof window.debugPaymentSubmit === 'function') {
    return window.debugPaymentSubmit();
  }

  return {
    debugAvailable: false,
    location: window.location.href,
  };
});

const runCase = async ({ fare, taxes, vatInput, serviceAmount, paymentCurrency }) => {
  await page.fill('#commercial-sale-price', '');
  await page.fill('#commercial-sale-price', String(fare));
  await page.fill('#commercial-taxes', '');
  await page.fill('#commercial-taxes', String(taxes));
  await page.fill('#commercial-vat-input', '');
  await page.fill('#commercial-vat-input', String(vatInput));
  await page.fill('#commercial-service-charge', '');
  await page.fill('#commercial-service-charge', String(serviceAmount));

  if (paymentCurrency) {
    await page.selectOption('[data-payment-currency-select]', paymentCurrency);
  }

  await page.waitForTimeout(250);

  return {
    debug: await evaluateDebug(),
    invoiceAmount: await page.inputValue('#commercial-payment-current-invoice'),
    invoiceBalance: await page.inputValue('#commercial-payment-current-balance'),
    finalSale: await page.inputValue('#commercial-final-sale-price'),
    frtx: await page.inputValue('#commercial-airline-payable'),
    noCurrentInvoiceHidden: await page.locator('[data-payment-no-current-invoice]').evaluate((node) => node.hidden),
  };
};

const feedbackText = async () => {
  const locator = page.locator('[data-workspace-feedback]');
  return (await locator.count()) > 0 ? (await locator.textContent())?.trim() || '' : '';
};

const exchangeModalHidden = async () => {
  const locator = page.locator('[data-payment-exchange-modal]');
  if ((await locator.count()) === 0) {
    return true;
  }
  return await locator.evaluate((node) => node.hidden);
};

const ensureBookingContext = async () => {
  const bookingDate = page.locator('input[name="booking_date"]');
  if ((await bookingDate.inputValue()).trim() === '') {
    await bookingDate.fill(new Date().toISOString().slice(0, 10));
  }

  await page.fill('input[name="lead_traveler_name"]', 'Codex Recovery Customer');
  await page.fill('input[name="party_label"]', 'Codex Recovery Customer');
  await page.locator('input[name="lead_traveler_name"]').blur();
  await page.locator('input[name="party_label"]').blur();
  await page.waitForTimeout(2200);
};

const hiddenFieldValue = async (selector) => {
  const locator = page.locator(selector);
  return (await locator.count()) > 0 ? await locator.inputValue() : '';
};

try {
  await ensureAuthenticatedWorkspace();
  const probeSnapshot = {
    url: page.url(),
    title: await page.title(),
    bodySnippet: (await page.textContent('body')).slice(0, 800),
    saleFieldCount: await page.locator('#commercial-sale-price').count(),
  };
  if (probeSnapshot.saleFieldCount === 0) {
    console.log(JSON.stringify({
      probeSnapshot,
      initialDebug: await evaluateDebug(),
    }, null, 2));
    process.exit(0);
  }
  const result = {
    probeSnapshot,
    initialDebug: await evaluateDebug(),
    caseA: await runCase({ fare: 900, taxes: 0, vatInput: 0, serviceAmount: 20, paymentCurrency: 'PKR' }),
  };

  result.caseB = await runCase({ fare: 900, taxes: 10, vatInput: 0, serviceAmount: 20, paymentCurrency: 'PKR' });
  result.caseC = await runCase({ fare: 800, taxes: 0, vatInput: 100, serviceAmount: 79, paymentCurrency: 'PKR' });

  result.caseDBeforeAutosave = await runCase({ fare: 900, taxes: 0, vatInput: 0, serviceAmount: 20, paymentCurrency: 'PKR' });
  await ensureBookingContext();
  result.caseDAutosaveIds = {
    bookingId: await hiddenFieldValue('form.legacy-invoice-header input[name="booking_id"]'),
    serviceId: await hiddenFieldValue('#legacy-service-form input[name="service_id"]'),
    invoiceNo: await page.inputValue('[data-invoice-number-display]'),
  };
  await page.goto(`${baseUrl}/workspace?booking_id=${result.caseDAutosaveIds.bookingId}&debug_ui=1`, { waitUntil: 'networkidle' });
  result.caseDAfterReload = {
    debug: await evaluateDebug(),
    invoiceAmount: await page.inputValue('#commercial-payment-current-invoice'),
    invoiceBalance: await page.inputValue('#commercial-payment-current-balance'),
    finalSale: await page.inputValue('#commercial-final-sale-price'),
    frtx: await page.inputValue('#commercial-airline-payable'),
    noCurrentInvoiceHidden: await page.locator('[data-payment-no-current-invoice]').evaluate((node) => node.hidden),
  };

  await page.selectOption('[data-payment-currency-select]', 'AED');
  await page.waitForTimeout(250);
  result.caseE = {
    debug: await evaluateDebug(),
    exchangeModalHidden: await exchangeModalHidden(),
    feedback: await feedbackText(),
    invoiceAmount: await page.inputValue('#commercial-payment-current-invoice'),
    finalSale: await page.inputValue('#commercial-final-sale-price'),
  };

  await page.click('[data-payment-action="print-receipt"]');
  await page.waitForTimeout(150);
  result.caseF = {
    exchangeModalHidden: await exchangeModalHidden(),
    feedback: await feedbackText(),
  };

  await page.selectOption('[data-payment-currency-select]', 'PKR');
  await page.fill('input[name="received_amount"]', '300');
  await page.fill('input[data-payment-due-date]', new Date().toISOString().slice(0, 10));
  await page.waitForTimeout(200);
  const caseGDebugBefore = await evaluatePaymentSubmitDebug();
  const caseGPreflight = await page.evaluate(() => {
    const form = document.querySelector('form.legacy-payment-strip');
    const saveButton = document.querySelector('[data-payment-action="save-payment"]');
    const bookingField = form?.querySelector('input[name="booking_id"]');
    const dueDateField = form?.querySelector('[data-payment-due-date]');
    const receivedField = form?.querySelector('input[name="received_amount"]');
    return {
      formValidity: form instanceof HTMLFormElement ? form.checkValidity() : null,
      bookingId: bookingField instanceof HTMLInputElement ? bookingField.value : null,
      dueDate: dueDateField instanceof HTMLInputElement ? dueDateField.value : null,
      receivedAmount: receivedField instanceof HTMLInputElement ? receivedField.value : null,
      saveButtonDisabled: saveButton instanceof HTMLButtonElement ? saveButton.disabled : null,
    };
  });
  await page.click('[data-payment-action="save-payment"]');
  await page.waitForTimeout(1800);
  result.caseG = {
    preflight: caseGPreflight,
    debugBefore: caseGDebugBefore,
    debugAfter: await evaluatePaymentSubmitDebug(),
    debug: await evaluateDebug(),
    invoiceAmount: await page.inputValue('#commercial-payment-current-invoice'),
    invoiceBalance: await page.inputValue('#commercial-payment-current-balance'),
    paidOnThisInvoice: await page.inputValue('#commercial-payment-already-received'),
    printReceiptUrl: await page.locator('[data-payment-action="print-receipt"]').getAttribute('data-payment-print-url'),
    feedback: await feedbackText(),
  };

  const receiptPopupPromise = page.waitForEvent('popup', { timeout: 5000 }).catch(() => null);
  await page.click('[data-payment-action="print-receipt"]');
  const receiptPopup = await receiptPopupPromise;
  result.caseHPrintAfterSave = {
    feedback: await feedbackText(),
    popupUrl: receiptPopup ? receiptPopup.url() : null,
    popupReachedWorkspaceOutput: receiptPopup ? receiptPopup.url().includes('/Travel-Agency/workspace/output') : false,
  };
  if (receiptPopup) {
    await receiptPopup.close();
  }

  await page.selectOption('[data-payment-currency-select]', 'AED');
  await page.fill('input[name="received_amount"]', '10');
  await page.waitForTimeout(250);
  const caseICrossBefore = await evaluatePaymentSubmitDebug();
  await page.click('[data-payment-action="save-payment"]');
  await page.waitForTimeout(300);
  result.caseICrossCurrencyBlocked = {
    debugBefore: caseICrossBefore,
    debugAfter: await evaluatePaymentSubmitDebug(),
    feedback: await feedbackText(),
    invoiceBalance: await page.inputValue('#commercial-payment-current-balance'),
    paidOnThisInvoice: await page.inputValue('#commercial-payment-already-received'),
  };

  await page.selectOption('[data-payment-currency-select]', 'PKR');
  await page.fill('input[name="received_amount"]', '50');
  await page.waitForTimeout(250);
  const caseJDoubleBefore = await evaluatePaymentSubmitDebug();
  await page.dblclick('[data-payment-action="save-payment"]');
  await page.waitForTimeout(1800);
  result.caseJDoubleClick = {
    debugBefore: caseJDoubleBefore,
    debugAfter: await evaluatePaymentSubmitDebug(),
    feedback: await feedbackText(),
    invoiceBalance: await page.inputValue('#commercial-payment-current-balance'),
    paidOnThisInvoice: await page.inputValue('#commercial-payment-already-received'),
    printReceiptUrl: await page.locator('[data-payment-action="print-receipt"]').getAttribute('data-payment-print-url'),
  };
  console.log(JSON.stringify(result, null, 2));
} finally {
  await context.close();
}
