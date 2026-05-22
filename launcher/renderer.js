const STORAGE_KEYS = {
  snapshot: 'travelOps.offline.snapshot',
  csrf: 'travelOps.offline.csrf',
  drafts: 'travelOps.offline.drafts'
};

let activeTab = 'bookings';
let activeDraft = 'traveler';
let config = { serverUrl: '', sourceDevice: 'Windows Launcher' };

function readJson(key, fallback) {
  try {
    const raw = localStorage.getItem(key);
    return raw ? JSON.parse(raw) : fallback;
  } catch {
    return fallback;
  }
}

function writeJson(key, value) {
  localStorage.setItem(key, JSON.stringify(value));
}

function drafts() {
  return readJson(STORAGE_KEYS.drafts, []);
}

function setDrafts(rows) {
  writeJson(STORAGE_KEYS.drafts, rows);
  renderDrafts();
}

function snapshot() {
  return readJson(STORAGE_KEYS.snapshot, null);
}

function setConnection(label) {
  document.getElementById('connection-status').textContent = label;
}

function updateStatus() {
  const cached = snapshot();
  document.getElementById('cached-at').textContent = cached?.generated_at || 'Never';
  document.getElementById('pending-count').textContent = String(drafts().filter((row) => row.status === 'pending').length);
}

function rowText(row) {
  return JSON.stringify(row).toLowerCase();
}

function renderCache() {
  const cached = snapshot();
  const list = document.getElementById('cache-list');
  const search = document.getElementById('cache-search').value.trim().toLowerCase();
  list.innerHTML = '';

  if (!cached) {
    list.innerHTML = '<div class="row"><strong>No emergency cache yet</strong><small>Open online first, then refresh cache.</small></div>';
    return;
  }

  const source = {
    bookings: cached.bookings || [],
    travelers: cached.travelers || [],
    dues: cached.customer_outstanding || [],
    suppliers: cached.suppliers || []
  }[activeTab] || [];

  const rows = source.filter((row) => search === '' || rowText(row).includes(search)).slice(0, 80);
  if (rows.length === 0) {
    list.innerHTML = '<div class="row"><strong>No cached matches</strong></div>';
    return;
  }

  for (const row of rows) {
    const item = document.createElement('div');
    item.className = 'row';
    if (activeTab === 'bookings') {
      item.innerHTML = `<strong>${row.booking_reference || 'Booking'}</strong><small>${row.lead_traveler_name || 'Customer pending'} | ${row.branch_name || ''} | Outstanding ${row.total_outstanding || 0} ${row.booking_currency || ''}</small>`;
    } else if (activeTab === 'travelers') {
      item.innerHTML = `<strong>${row.full_name || 'Traveler'}</strong><small>${row.mobile || ''} | ${row.passport_number || ''} | ${row.branch_name || ''}</small>`;
    } else if (activeTab === 'dues') {
      item.innerHTML = `<strong>${row.booking_reference || 'Due'} - ${row.customer_name || ''}</strong><small>${row.total_outstanding_amount || 0} ${row.currency || ''} | Due ${row.due_date || 'N/A'} | ${row.branch_name || ''}</small>`;
    } else {
      item.innerHTML = `<strong>${row.name || 'Supplier'}</strong><small>${row.code || ''} | ${row.default_currency || ''}</small>`;
    }
    list.appendChild(item);
  }
}

function renderDrafts() {
  const list = document.getElementById('draft-list');
  const rows = drafts();
  list.innerHTML = '';
  updateStatus();

  if (rows.length === 0) {
    list.innerHTML = '<div class="row"><strong>No queued drafts</strong><small>Safe offline drafts will appear here.</small></div>';
    return;
  }

  for (const row of rows.slice().reverse()) {
    const item = document.createElement('div');
    item.className = 'row';
    const title = row.type === 'traveler.create'
      ? row.payload.full_name || `${row.payload.first_name || ''} ${row.payload.last_name || ''}`.trim()
      : row.payload.lead_traveler_name || 'Booking draft';
    item.innerHTML = `<strong>${title}</strong><small>${row.type} | ${row.status}${row.server_reference ? ` | ${row.server_reference}` : ''}${row.message ? ` | ${row.message}` : ''}</small>`;
    list.appendChild(item);
  }
}

function formPayload(form) {
  const payload = Object.fromEntries(new FormData(form).entries());
  for (const key of Object.keys(payload)) {
    payload[key] = String(payload[key]).trim();
    if (payload[key] === '') {
      delete payload[key];
    }
  }
  return payload;
}

function queueDraft(type, payload) {
  const id = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  setDrafts([...drafts(), {
    client_draft_id: id,
    type,
    payload,
    status: 'pending',
    queued_at: new Date().toISOString()
  }]);
}

async function refreshCache() {
  setConnection('Checking');
  const response = await window.travelLauncher.snapshot();
  if (!response.ok || response.needsLogin || !response.payload?.ok) {
    setConnection(response.needsLogin ? 'Login Required' : 'Offline');
    return;
  }

  writeJson(STORAGE_KEYS.snapshot, response.payload.snapshot);
  localStorage.setItem(STORAGE_KEYS.csrf, response.payload.csrf_token || '');
  setConnection('Online');
  updateStatus();
  renderCache();
}

async function syncDrafts() {
  const rows = drafts();
  const pending = rows.filter((row) => row.status === 'pending');
  if (pending.length === 0) {
    updateStatus();
    return;
  }

  const csrfToken = localStorage.getItem(STORAGE_KEYS.csrf) || '';
  const response = await window.travelLauncher.syncDrafts({ csrfToken, drafts: pending });
  if (!response.ok || response.needsLogin || !response.payload?.ok) {
    setConnection(response.needsLogin ? 'Login Required' : 'Offline');
    return;
  }

  const resultById = new Map(response.payload.results.map((result) => [result.client_draft_id, result]));
  setDrafts(rows.map((row) => {
    const result = resultById.get(row.client_draft_id);
    if (!result) {
      return row;
    }

    return {
      ...row,
      status: result.status,
      server_record_type: result.server_record_type,
      server_record_id: result.server_record_id,
      server_reference: result.server_reference,
      message: result.message || '',
      synced_at: new Date().toISOString()
    };
  }));
  setConnection('Online');
  await refreshCache();
}

async function init() {
  config = await window.travelLauncher.config();
  document.getElementById('server-label').textContent = 'Secure cloud connection';
  document.getElementById('open-online').addEventListener('click', () => window.travelLauncher.openOnline());
  document.getElementById('refresh-cache').addEventListener('click', refreshCache);
  document.getElementById('sync-drafts').addEventListener('click', syncDrafts);
  document.getElementById('cache-search').addEventListener('input', renderCache);

  document.querySelectorAll('.tab').forEach((button) => {
    button.addEventListener('click', () => {
      activeTab = button.dataset.tab;
      document.querySelectorAll('.tab').forEach((tab) => tab.classList.toggle('active', tab === button));
      renderCache();
    });
  });

  document.querySelectorAll('.draft-tab').forEach((button) => {
    button.addEventListener('click', () => {
      activeDraft = button.dataset.draft;
      document.querySelectorAll('.draft-tab').forEach((tab) => tab.classList.toggle('active', tab === button));
      document.getElementById('traveler-form').classList.toggle('hidden', activeDraft !== 'traveler');
      document.getElementById('booking-form').classList.toggle('hidden', activeDraft !== 'booking');
    });
  });

  document.getElementById('traveler-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const payload = formPayload(event.currentTarget);
    payload.full_name = `${payload.first_name || ''} ${payload.last_name || ''}`.trim();
    payload.gender = 'unspecified';
    queueDraft('traveler.create', payload);
    event.currentTarget.reset();
  });

  document.getElementById('booking-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const payload = formPayload(event.currentTarget);
    payload.party_label = 'Lead Traveler / Booking Party';
    payload.booking_status = 'draft';
    queueDraft('booking.create', payload);
    event.currentTarget.reset();
  });

  updateStatus();
  renderCache();
  renderDrafts();
  await refreshCache();
  if (drafts().some((row) => row.status === 'pending')) {
    await syncDrafts();
  }
}

init();
