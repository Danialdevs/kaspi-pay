/* Kaspi POS — admin panel. Session-cookie auth, no apiKey in localStorage. */
(() => {
  const $ = (id) => document.getElementById(id);
  const $$ = (sel) => Array.from(document.querySelectorAll(sel));

  // ── HTTP ───────────────────────────────
  const api = async (path, opts = {}) => {
    const headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
    if (opts.sessionId) headers['X-Session-Id'] = String(opts.sessionId);
    const res = await fetch(path, {
      method: opts.method || 'GET',
      headers,
      body: opts.body,
      credentials: 'same-origin',
    });
    let json;
    try { json = await res.json(); } catch { json = { error: await res.text() }; }
    return { ok: res.ok, status: res.status, json };
  };

  // ── State ──────────────────────────────
  let user = null;          // { id, username, apiKey }
  let sessions = [];        // Kaspi cashiers
  let currentPage = 'dashboard';
  let pollTimer = null;

  // ── Auth gate ──────────────────────────
  const setAuthErr = (m) => { const e = $('authErr'); e.textContent = m || ''; e.classList.toggle('hidden', !m); };

  const submitAuth = async () => {
    const username = $('authUsername').value.trim();
    const password = $('authPassword').value;
    if (!username || !password) return setAuthErr('Заполни логин и пароль');
    setAuthErr('');
    const r = await api('/api/account/login', { method: 'POST', body: JSON.stringify({ username, password }) });
    if (!r.ok) return setAuthErr(r.json.error || 'Ошибка');
    await afterLogin();
  };

  const logout = async () => {
    if (!confirm('Выйти из кабинета?')) return;
    await api('/api/account/logout', { method: 'POST' });
    user = null;
    showGate();
  };

  // ── Boot ───────────────────────────────
  const boot = async () => {
    const r = await api('/api/account/me');
    if (r.ok && r.json.id) {
      user = r.json;
      await afterLogin();
    } else {
      showGate();
    }
  };
  const showGate = () => {
    $('authGate').classList.remove('hidden');
    $('app').classList.add('hidden');
  };
  const afterLogin = async () => {
    // already authenticated — fetch /me to get apiKey + username
    if (!user || !user.apiKey) {
      const me = await api('/api/account/me');
      if (me.ok) user = me.json;
    }
    $('authGate').classList.add('hidden');
    $('app').classList.remove('hidden');
    $('userBadge').textContent = user.username;
    $('userAvatar').textContent = (user.username[0] || 'M').toUpperCase();
    $('apiKeyOut').textContent = user.apiKey || '—';
    await refreshSessions();
    navigate(location.hash.replace('#', '') || 'dashboard');
  };

  // ── Navigation ─────────────────────────
  const navigate = (page) => {
    currentPage = page;
    $$('.sidebar-menu a').forEach((a) => a.classList.toggle('active', a.dataset.page === page));
    $$('section[data-page]').forEach((s) => s.classList.toggle('hidden', s.dataset.page !== page));
    const titles = { dashboard: 'Дашборд', orders: 'Заказы', pos: 'Касса', sessions: 'Kaspi-кассиры', settings: 'API-ключ магазина' };
    $('pageTitle').textContent = titles[page] || '';
    location.hash = page;
    if (page === 'dashboard') loadDashboard();
    if (page === 'orders') loadOrders();
    if (page === 'sessions') renderSessionsTable();
    if (page === 'pos') populateSessionSelector('posSession');
  };
  window.addEventListener('hashchange', () => navigate(location.hash.replace('#', '') || 'dashboard'));
  $$('.sidebar-menu a').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); navigate(a.dataset.page); }));

  // ── Sessions ───────────────────────────
  const refreshSessions = async () => {
    const r = await api('/api/sessions/list');
    sessions = (r.ok ? (r.json.sessions || []) : []).filter((s) => s.status === 'active' || s.status === 'pending');
    populateSessionSelector('posSession');
    populateSessionSelector('ordersSession');
    $('statCashiers').textContent = sessions.filter((s) => s.status === 'active').length;
  };
  const populateSessionSelector = (id) => {
    const sel = $(id);
    if (!sel) return;
    const cur = sel.value;
    sel.innerHTML = '';
    const active = sessions.filter((s) => s.status === 'active');
    if (!active.length) {
      const o = document.createElement('option');
      o.value = ''; o.textContent = '— нет активных —';
      sel.appendChild(o);
      return;
    }
    active.forEach((s) => {
      const o = document.createElement('option');
      o.value = String(s.id);
      o.textContent = (s.name || s.org_name || `Кассир #${s.id}`) + (s.phone_number ? ` · +7${s.phone_number}` : '');
      sel.appendChild(o);
    });
    if (cur && active.some((s) => String(s.id) === cur)) sel.value = cur;
  };
  const currentSessionId = (selectId) => {
    const sel = $(selectId);
    return sel ? parseInt(sel.value, 10) || null : null;
  };

  const renderSessionsTable = () => {
    const tbody = $('sessTbody');
    if (!sessions.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted small p-4">Нет кассиров. Жми «+ Добавить кассира».</td></tr>';
      return;
    }
    tbody.innerHTML = sessions.map((s) => {
      const tok = s.api_token || '';
      const tokView = tok ? `${tok.slice(0, 12)}…${tok.slice(-6)}` : '—';
      return `
      <tr data-row-id="${s.id}">
        <td>#${s.id}</td>
        <td>${escapeHtml(s.name || '—')}</td>
        <td>${s.phone_number ? '+7' + escapeHtml(s.phone_number) : '—'}</td>
        <td>${escapeHtml(s.org_name || '—')}</td>
        <td>
          ${tok ? `<code class="small">${tokView}</code>
                  <button class="btn btn-sm btn-link p-0 ms-1" title="Скопировать" onclick="copyCashierToken('${tok}')"><i class="bi bi-clipboard"></i></button>
                  <button class="btn btn-sm btn-link p-0 ms-1 text-muted" title="Перевыпустить" onclick="rotateCashierToken(${s.id})"><i class="bi bi-arrow-repeat"></i></button>`
              : '<span class="text-muted small">нет</span>'}
        </td>
        <td>${statusBadge(s.status)}</td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-danger" onclick="deleteCashier(${s.id})"><i class="bi bi-trash"></i></button>
        </td>
      </tr>`;
    }).join('');
  };
  const copyCashierToken = (t) => { navigator.clipboard.writeText(t); };
  const rotateCashierToken = async (id) => {
    if (!confirm('Перевыпустить токен кассы? Старый перестанет работать.')) return;
    const r = await api('/api/sessions/rotate-token', { method: 'POST', body: JSON.stringify({ id }) });
    if (!r.ok) return alert(r.json.error || 'Ошибка');
    await refreshSessions();
    renderSessionsTable();
  };
  const statusBadge = (s) => {
    const map = { active: 'success', pending: 'warning', expired: 'danger' };
    const txt = { active: 'активна', pending: 'ожидает', expired: 'истекла' }[s] || s;
    return `<span class="badge-soft badge-soft-${map[s] || 'info'}">${txt}</span>`;
  };

  // ── Add Cashier modal ──────────────────
  let modal = null;
  let processId = null;
  const showAddCashier = () => {
    if (!modal) modal = new bootstrap.Modal($('addCashierModal'));
    $('ccName').value = ''; $('ccPhone').value = ''; $('ccOtp').value = '';
    $('ccStep1').classList.remove('hidden');
    $('ccStep2').classList.add('hidden');
    $('ccErr').classList.add('hidden');
    modal.show();
  };
  const setCcErr = (m) => { const e = $('ccErr'); e.textContent = m || ''; e.classList.toggle('hidden', !m); };

  const ccSendPhone = async () => {
    const name = $('ccName').value.trim();
    const phone = $('ccPhone').value.replace(/\D/g, '');
    if (phone.length < 10) return setCcErr('10 цифр номера');
    setCcErr('');
    $('ccSendBtn').disabled = true;
    try {
      const init = await api('/api/auth/init', { method: 'POST', body: JSON.stringify({ name }) });
      if (!init.ok || !init.json.processId) return setCcErr(init.json.error || 'Ошибка init');
      processId = init.json.processId;
      const sp = await api('/api/auth/send-phone', { method: 'POST', body: JSON.stringify({ phoneNumber: phone, processId }) });
      if (!sp.ok || !sp.json.success) return setCcErr(sp.json.body?.error?.label || sp.json.body?.data?.desc || sp.json.error || 'Ошибка SMS');
      $('ccDesc').textContent = sp.json.desc || `SMS на +7${phone}`;
      $('ccStep1').classList.add('hidden');
      $('ccStep2').classList.remove('hidden');
    } finally { $('ccSendBtn').disabled = false; }
  };

  const ccSubmitOtp = async () => {
    const otp = $('ccOtp').value.replace(/\D/g, '');
    if (!otp) return setCcErr('Введи код из SMS');
    $('ccOtpBtn').disabled = true;
    try {
      const r = await api('/api/auth/verify-otp', { method: 'POST', body: JSON.stringify({ otp, processId }) });
      if (r.ok && r.json.step === 'finished') {
        modal.hide();
        await refreshSessions();
        navigate('sessions');
        return;
      }
      setCcErr(r.json.error || r.json.body?.error?.label || r.json.body?.data?.desc || 'Ошибка');
    } finally { $('ccOtpBtn').disabled = false; }
  };

  const deleteCashier = async (id) => {
    if (!confirm('Удалить кассира?')) return;
    await api('/api/sessions/delete', { method: 'POST', body: JSON.stringify({ id }) });
    await refreshSessions();
    renderSessionsTable();
  };

  // ── POS ────────────────────────────────
  const createQr = async () => {
    const sid = currentSessionId('posSession');
    if (!sid) return alert('Выбери кассира');
    const amount = parseFloat($('qrAmount').value);
    if (!amount) return alert('Сумма?');
    const r = await api('/api/pay/qr', { method: 'POST', sessionId: sid, body: JSON.stringify({ amount }) });
    if (!r.ok || !r.json.ok) return showPosResult(r.json.error || JSON.stringify(r.json), 'err');
    renderQr(r.json, sid);
  };
  const createInvoice = async () => {
    const sid = currentSessionId('posSession');
    if (!sid) return alert('Выбери кассира');
    const phone = $('invPhone').value.replace(/\D/g, '');
    const amount = parseFloat($('invAmount').value);
    const comment = $('invComment').value.trim();
    if (phone.length < 10) return alert('10 цифр номера');
    if (!amount) return alert('Сумма?');
    const r = await api('/api/pay/invoice', { method: 'POST', sessionId: sid, body: JSON.stringify({ phoneNumber: '7' + phone, amount, comment }) });
    if (!r.ok || !r.json.ok) return showPosResult(r.json.error || JSON.stringify(r.json), 'err');
    showPosResult(`Счёт ${r.json.id} на ${r.json.amount}₸ отправлен на +${r.json.phoneNumber}`, 'info');
    pollPayment('invoice', r.json.id, sid);
  };
  const renderQr = (j, sid) => {
    const url = j.qrToken;
    const qrImg = url ? `https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=${encodeURIComponent(url)}` : '';
    $('posResult').innerHTML = `
      <div class="panel card-view">
        <div class="panel-heading"><h6 class="panel-title txt-dark">QR-платёж #${j.id}</h6><span class="badge-soft badge-soft-info" id="payStatusLine">pending</span></div>
        <div class="panel-body text-center">
          ${qrImg ? `<img src="${qrImg}" alt="QR" class="border rounded mb-3">` : ''}
          <p class="small text-break"><a href="${url}" target="_blank" rel="noopener">${url}</a></p>
          <p class="text-muted small">${j.amount}₸</p>
        </div>
      </div>`;
    $('posResult').classList.remove('hidden');
    pollPayment('qr', j.id, sid);
  };
  const showPosResult = (text, kind) => {
    const cls = kind === 'err' ? 'status-err' : 'status-info';
    $('posResult').innerHTML = `<div class="status-bar ${cls}">${escapeHtml(text)}</div>`;
    $('posResult').classList.remove('hidden');
  };
  const pollPayment = (type, id, sid) => {
    stopPolling();
    pollTimer = setInterval(async () => {
      const r = await api(`/api/pay/status?id=${encodeURIComponent(id)}&type=${type}`, { sessionId: sid });
      if (!r.ok || !r.json.ok) return;
      const line = $('payStatusLine');
      if (line) {
        const cls = { success: 'badge-soft-success', pending: 'badge-soft-info', failed: 'badge-soft-danger', expired: 'badge-soft-warning' }[r.json.status] || 'badge-soft-info';
        line.className = 'badge-soft ' + cls;
        line.textContent = r.json.status + (r.json.statusDesc ? ' — ' + r.json.statusDesc : '');
      }
      if (r.json.final) stopPolling();
    }, 3000);
  };
  const stopPolling = () => { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } };

  // ── Orders + Dashboard ────────────────
  const loadOrders = async () => {
    const sid = currentSessionId('ordersSession');
    const tbody = $('ordersTbody');
    if (!sid) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted small p-4">Нет активного кассира</td></tr>';
      return;
    }
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Загрузка…</td></tr>';
    const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
    const r = await api(`/api/pay/history?endDate=${tomorrow}`, { sessionId: sid });
    if (!r.ok || !r.json.ok) {
      tbody.innerHTML = `<tr><td colspan="7" class="text-danger small p-4">${escapeHtml(r.json.error || 'Ошибка')}</td></tr>`;
      return;
    }
    const items = r.json.items || [];
    if (!items.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted small p-4">Заказы отсутствуют</td></tr>';
      return;
    }
    tbody.innerHTML = items.map((o) => `
      <tr>
        <td>#${escapeHtml(o.id)}</td>
        <td class="small text-muted">${escapeHtml(o.date || '')}</td>
        <td>${o.type === 'qr' ? '<i class="bi bi-qr-code"></i> QR' : '<i class="bi bi-phone"></i> Счёт'}</td>
        <td class="fw-semibold">${o.amount ?? 0} ₸</td>
        <td class="small">${escapeHtml(o.clientName || o.phoneNumber || '—')}</td>
        <td>${statusBadge(o.status)}</td>
        <td class="text-end small text-muted">${escapeHtml(o.rawStatus || '')}</td>
      </tr>`).join('');
  };

  const loadDashboard = async () => {
    const sid = sessions.find((s) => s.status === 'active')?.id;
    if (!sid) {
      $('dashRecent').innerHTML = '<p class="text-muted small text-center p-4 mb-0">Подключи Kaspi-кассира на вкладке «Kaspi-кассиры»</p>';
      $('statCount').textContent = '0';
      $('statRevenue').textContent = '0 ₸';
      $('statRefunds').textContent = '0 ₸';
      return;
    }
    const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
    const r = await api(`/api/pay/history?endDate=${tomorrow}`, { sessionId: sid });
    if (!r.ok || !r.json.ok) {
      $('dashRecent').innerHTML = `<p class="text-danger small p-4 mb-0">${escapeHtml(r.json.error || 'Ошибка')}</p>`;
      return;
    }
    const items = r.json.items || [];
    const today = new Date().toISOString().slice(0, 10);
    const todays = items.filter((o) => (o.date || '').startsWith(today) && o.status === 'success');
    $('statCount').textContent = todays.length;
    $('statRevenue').textContent = todays.reduce((a, o) => a + (parseFloat(o.amount) || 0), 0) + ' ₸';
    $('statRefunds').textContent = '0 ₸'; // TODO: separate refund endpoint
    if (!items.length) {
      $('dashRecent').innerHTML = '<p class="text-muted small text-center p-4 mb-0">Заказы отсутствуют</p>';
      return;
    }
    $('dashRecent').innerHTML = `
      <div class="table-responsive"><table class="table product-overview border-none mb-0"><thead><tr><th>ID</th><th>Дата</th><th>Тип</th><th>Сумма</th><th>Статус</th></tr></thead><tbody>
      ${items.slice(0, 8).map((o) => `<tr>
        <td>#${escapeHtml(o.id)}</td>
        <td class="small text-muted">${escapeHtml(o.date || '')}</td>
        <td>${o.type === 'qr' ? 'QR' : 'Счёт'}</td>
        <td class="fw-semibold">${o.amount ?? 0} ₸</td>
        <td>${statusBadge(o.status)}</td>
      </tr>`).join('')}
      </tbody></table></div>`;
  };

  // ── Settings ───────────────────────────
  const copyApiKey = () => { if (user?.apiKey) navigator.clipboard.writeText(user.apiKey); };
  const regenerateKey = async () => {
    if (!confirm('Перегенерировать ключ? Старый перестанет работать в интеграциях.')) return;
    const r = await api('/api/account/regenerate-key', { method: 'POST' });
    if (r.ok && r.json.apiKey) {
      user.apiKey = r.json.apiKey;
      $('apiKeyOut').textContent = r.json.apiKey;
    } else {
      alert(r.json.error || 'Ошибка');
    }
  };

  // ── Utils ──────────────────────────────
  const escapeHtml = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  // ── Exports ────────────────────────────
  Object.assign(window, {
    submitAuth, logout,
    showAddCashier, ccSendPhone, ccSubmitOtp, deleteCashier,
    copyCashierToken, rotateCashierToken,
    createQr, createInvoice,
    loadOrders, copyApiKey, regenerateKey,
  });

  boot();
})();
