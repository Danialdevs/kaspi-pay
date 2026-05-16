/* Storefront SPA — /s/<username> */
(() => {
  const $ = (id) => document.getElementById(id);
  const username = location.pathname.split('/').filter(Boolean)[1]; // /s/<username>
  let products = [];
  let selected = null;
  let currentOrderId = null;
  let pollTimer = null;

  const api = async (path, opts = {}) => {
    const res = await fetch(path, {
      method: opts.method || 'GET',
      headers: { 'Content-Type': 'application/json' },
      body: opts.body,
    });
    let json;
    try { json = await res.json(); } catch { json = { error: 'bad response' }; }
    return { ok: res.ok, status: res.status, json };
  };

  const show = (id) => {
    ['catalogSection', 'formSection', 'paySection', 'invSection', 'doneSection', 'failSection'].forEach((s) => {
      $(s).classList.toggle('hidden', s !== id);
    });
    stopPolling();
  };

  // ── Catalog ─────────────────────────────────────
  const loadCatalog = async () => {
    const r = await api(`/api/store/${encodeURIComponent(username)}`);
    if (!r.ok) {
      $('catalog').innerHTML = `<div class="col-12 text-danger small">${escapeHtml(r.json.error || 'Ошибка')}</div>`;
      return;
    }
    $('shopName').textContent = r.json.shop?.name || 'Магазин';
    products = r.json.products || [];
    if (!products.length) {
      $('catalog').classList.add('hidden');
      $('catalogEmpty').classList.remove('hidden');
      return;
    }
    $('catalog').innerHTML = products.map((p) => `
      <div class="col-md-6 col-lg-4">
        <div class="product-card" data-pid="${p.id}">
          ${p.image_url
            ? `<div class="img" style="background-image:url('${escapeAttr(p.image_url)}'); background-size:cover; background-position:center"></div>`
            : `<div class="img placeholder"></div>`}
          <div class="body">
            <div class="name">${escapeHtml(p.name)}</div>
            ${p.description ? `<div class="desc">${escapeHtml(p.description)}</div>` : ''}
            <div class="price">${formatPrice(p.price)} ₸</div>
          </div>
        </div>
      </div>`).join('');
    $('catalog').querySelectorAll('.product-card').forEach((el) => {
      el.addEventListener('click', () => selectProduct(parseInt(el.dataset.pid, 10)));
    });
  };

  const selectProduct = (pid) => {
    selected = products.find((p) => p.id === pid);
    if (!selected) return;
    $('selectedName').textContent = selected.name;
    $('selectedPrice').textContent = formatPrice(selected.price);
    const img = $('selectedImg');
    if (selected.image_url) {
      img.src = selected.image_url;
      img.classList.remove('hidden');
    } else {
      img.classList.add('hidden');
    }
    $('custName').value = '';
    $('custPhone').value = '';
    $('formErr').classList.add('hidden');
    show('formSection');
  };

  const backToCatalog = () => { selected = null; currentOrderId = null; show('catalogSection'); };

  // ── Checkout ────────────────────────────────────
  const goPay = async () => {
    const customerName = $('custName').value.trim();
    const customerPhone = $('custPhone').value.replace(/\D/g, '');
    const errBox = $('formErr');
    if (!customerName) return setErr('Введите имя');
    if (customerPhone.length < 10) return setErr('Введите 10 цифр номера');
    errBox.classList.add('hidden');
    $('payBtn').disabled = true;
    try {
      const r = await api(`/api/store/${encodeURIComponent(username)}/checkout`, {
        method: 'POST',
        body: JSON.stringify({
          productId: selected.id,
          customerName,
          customerPhone: '7' + customerPhone,
        }),
      });
      if (!r.ok || !r.json.ok) {
        const msg = r.json.error || r.json.kaspi?.Message || 'Не удалось создать платёж';
        return setErr(msg);
      }
      currentOrderId = r.json.orderId;
      renderPay(r.json);
      startPolling();
    } finally { $('payBtn').disabled = false; }
  };
  const setErr = (msg) => {
    const e = $('formErr');
    e.textContent = msg;
    e.classList.remove('hidden');
  };

  const renderPay = (r) => {
    const url = r.qrToken;
    const qrImg = url ? `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(url)}` : '';
    $('qrBox').innerHTML = qrImg ? `<img src="${qrImg}" alt="QR">` : '<p class="text-muted">QR не получен</p>';
    $('kaspiLink').href = url || '#';
    $('paySum').textContent = formatPrice(r.amount);
    $('payProductName').textContent = r.productName || '';
    show('paySection');
  };
  const renderInvoice = (r) => {
    $('invSum').textContent = formatPrice(r.amount);
    $('invProductName').textContent = r.productName || '';
    const ph = r.customerPhone || '';
    $('invPhone').textContent = ph ? '+' + ph.replace(/^7/, '7 ') : '+7…';
    show('invSection');
  };
  const sendInvoiceFallback = async () => {
    if (!currentOrderId) return;
    const btn = $('fallbackBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Отправляю…';
    try {
      const r = await api(`/api/store/${encodeURIComponent(username)}/invoice`, {
        method: 'POST',
        body: JSON.stringify({ orderId: currentOrderId }),
      });
      if (!r.ok || !r.json.ok) {
        alert(r.json.error || 'Не удалось выставить счёт');
        return;
      }
      renderInvoice(r.json);
      startPolling();
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-phone-vibrate"></i> Не получается — выставить счёт на телефон';
    }
  };
  const checkNow = async () => {
    if (!currentOrderId) return;
    const btn = $('refreshBtn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Проверяю…';
    }
    try {
      const r = await api(`/api/store/${encodeURIComponent(username)}/status?orderId=${currentOrderId}`);
      if (r.ok) handleStatus(r.json);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Проверить сейчас';
      }
    }
  };

  // ── Polling status ─────────────────────────────
  const handleStatus = (s) => {
    const cls = { success: 'badge-soft-success', pending: 'badge-soft-info', failed: 'badge-soft-danger', expired: 'badge-soft-warning' }[s.status] || 'badge-soft-info';
    const txt = s.status === 'pending' ? 'Ожидание оплаты…' : s.status;
    ['payStatus', 'invStatus'].forEach((id) => {
      const line = $(id);
      if (line) { line.className = 'badge-soft ' + cls; line.textContent = txt; }
    });
    if (s.final) {
      stopPolling();
      if (s.paid) {
        $('doneSum').textContent = formatPrice(s.amount);
        $('doneProductName').textContent = s.productName || '';
        show('doneSection');
      } else {
        $('failReason').textContent = ({
          failed:  'Платёж отклонён или отменён.',
          expired: 'Время оплаты истекло.',
        }[s.status]) || 'Платёж не прошёл.';
        show('failSection');
      }
    }
  };
  const startPolling = () => {
    stopPolling();
    pollTimer = setInterval(async () => {
      if (!currentOrderId) return stopPolling();
      const r = await api(`/api/store/${encodeURIComponent(username)}/status?orderId=${currentOrderId}`);
      if (!r.ok) return;
      handleStatus(r.json);
    }, 3000);
  };
  const stopPolling = () => { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } };

  // ── Utils ──────────────────────────────────────
  const escapeHtml = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const escapeAttr = (s) => escapeHtml(s).replace(/'/g, '&#39;');
  const formatPrice = (n) => Number(n).toLocaleString('ru-RU', { maximumFractionDigits: 0 });

  // ── Boot ───────────────────────────────────────
  Object.assign(window, { backToCatalog, goPay, checkNow, sendInvoiceFallback });
  show('catalogSection');
  loadCatalog();
})();
