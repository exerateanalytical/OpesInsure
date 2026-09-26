// Agent / broker area: shared helpers for /account/customers, /account/leads, /account/reports, /account/commissions.
// Data comes only from the real API. Which endpoints a page uses depends on the session's permissions:
//   agent  (agent.clients.read)   GET /mobile/agent/clients[/{id}], /mobile/agent/renewals, /mobile/agent/dashboard,
//                                 /mobile/partner/agent/quotes|policies|leads, /mobile/agent/commissions|withdrawals
//   broker (broker.portal.read)   GET /mobile/broker/clients[/{id}], /mobile/broker/renewals, /mobile/broker/dashboard,
//                                 /mobile/broker/production, /mobile/partner/broker/quotes|policies|commissions
(function () {
  var O = window.Opes, T = window.AGENT_T || {}, h = O.h;
  var A = {};

  A.t = function (k, rep) { var s = T[k] !== undefined ? T[k] : k; if (rep) Object.keys(rep).forEach(function (r) { s = String(s).replace(':' + r, rep[r]); }); return s; };
  A.label = function (code) { return (T.st || {})[code] || O.label(code); };
  A.line = function (code) { return code ? ((T.lines || {})[String(code).toUpperCase()] || O.label(code)) : '—'; };
  A.money = function (minor) { return minor === null || minor === undefined ? '—' : O.money(minor, { minor: true }); };

  /** 'agent' | 'broker' | null, from the workspace permissions. */
  A.mode = function () {
    if (O.can('agent.clients.read')) return 'agent';
    if (O.can('broker.portal.read')) return 'broker';
    return null;
  };

  /** Customers / officers get a clear "not available" panel instead of API errors. Returns true when the page may continue. */
  A.guard = function (ctx) {
    if (ctx.kind === 'agent' && A.mode()) return true;
    var root = O.$('[data-agent]');
    O.clear(root).appendChild(h('div', { class: 'acard desk-na' }, h('div', { class: 'acct-empty' }, O.icon('lock'), h('h2', null, A.t('na_t')), h('p', null, A.t('na_d')),
      h('a', { class: 'dbtn dbtn-primary sm', href: '/account' }, A.t('na_btn')))));
    return false;
  };

  A.errMsg = function (e) {
    if (!e) return O.t.error;
    if (e.status === 403) return A.t('forbidden');
    if (e.status === 404) return A.t('not_found');
    return e.message || O.t.error;
  };
  A.fail = function (el, e) { O.fail(el, { message: A.errMsg(e) }); };

  A.stat = function (tone, iconName, label, value, hint) {
    return h('div', { class: 'statc' }, h('span', { class: 'sq ' + tone }, O.icon(iconName)), h('div', null, h('small', null, label), h('b', null, String(value)), hint ? h('small', null, hint) : null));
  };
  A.stats = function (el, cards) { O.clear(el).append.apply(el, cards); };
  A.field = function (label, value) { return h('div', { class: 'ag-f' }, h('small', null, label), h('b', null, value === null || value === undefined || value === '' ? '—' : value)); };
  A.table = function (heads, rows, cls) {
    return h('div', { class: 'atable-wrap' }, h('table', { class: 'atable ag-table ' + (cls || '') },
      h('thead', null, h('tr', null, heads.map(function (k) { return h('th', { scope: 'col' }, A.t(k)); }))), h('tbody', null, rows)));
  };
  /** Horizontal bars: items = [[label, value, display]], all from API data. */
  A.bars = function (items, color) {
    if (!items.length) return h('p', { class: 'sub' }, A.t('no_data'));
    var max = Math.max.apply(null, items.map(function (i) { return i[1]; })) || 1;
    return h('div', { class: 'ag-bars' }, items.map(function (i) {
      return h('div', { class: 'row' }, h('span', { class: 'l', title: i[0] }, i[0]), h('span', { class: 'tr' }, h('span', { style: 'width:' + Math.max(2, i[1] / max * 100) + '%;background:' + (color || 'var(--royal)') })), h('b', null, i[2] !== undefined ? i[2] : String(i[1])));
    }));
  };
  A.group = function (rows, key, val) {
    var m = {}; rows.forEach(function (r) { var k = key(r) || '—'; m[k] = (m[k] || 0) + (val ? val(r) : 1); });
    return Object.keys(m).map(function (k) { return [k, m[k]]; }).sort(function (a, b) { return b[1] - a[1]; });
  };
  A.search = function (ph, on) {
    var inp = h('input', { type: 'search', placeholder: ph, 'aria-label': ph, oninput: function () { on(this.value.trim().toLowerCase()); } });
    return h('label', { class: 'desk-search' }, O.icon('search'), inp);
  };
  A.tabs = function (el, defs, cur, on) {
    O.clear(el);
    defs.forEach(function (d) {
      el.appendChild(h('button', { type: 'button', role: 'tab', 'aria-selected': String(cur === d[0]), onclick: function () { A.tabs(el, defs, d[0], on); on(d[0]); } }, d[1] + ' (' + d[2] + ')'));
    });
  };
  /** Assisted quotes: agents pick from /mobile/agent/clients; broker staff from /mobile/broker/clients and need quotes.manage. */
  A.canQuote = function () { return O.can('agent.clients.read') || (O.can('broker.portal.read') && O.can('quotes.manage')); };
  A.days = function (iso) { if (!iso) return null; return Math.ceil((new Date(iso) - Date.now()) / 86400000); };

  // ---------- data ----------
  A.clients = function () {
    var p = A.mode() === 'broker' ? '/mobile/broker/clients' : '/mobile/agent/clients';
    return O.list(p).then(function (r) { return r.items.map(A.normClient); });
  };
  A.normClient = function (c) {
    c.policy_count = c.active_policies !== undefined ? c.active_policies : (c.policies !== undefined ? c.policies : 0);
    return c;
  };
  A.client = function (id) {
    var p = (A.mode() === 'broker' ? '/mobile/broker/clients/' : '/mobile/agent/clients/') + encodeURIComponent(id);
    return O.api(p).then(A.normClient);
  };
  A.renewals = function () { return O.list(A.mode() === 'broker' ? '/mobile/broker/renewals' : '/mobile/agent/renewals').then(function (r) { return r.items; }); };
  A.quotes = function () { return O.list(A.mode() === 'broker' ? '/mobile/partner/broker/quotes' : '/mobile/partner/agent/quotes').then(function (r) { return r.items; }); };
  A.policies = function () { return O.list(A.mode() === 'broker' ? '/mobile/partner/broker/policies' : '/mobile/partner/agent/policies').then(function (r) { return r.items; }); };
  A.dashboard = function () { return O.api(A.mode() === 'broker' ? '/mobile/broker/dashboard' : '/mobile/agent/dashboard'); };

  /** POST with extra headers (the step-up grant). Same auth headers as Opes.api; a 401 here is shown, never a sign-out. */
  A.postWithHeaders = function (path, body, extra) {
    var s = O.session() || {}, cfg = window.OPES_PORTAL || {};
    var headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'Accept-Language': O.locale || 'en', 'Idempotency-Key': O.uuid(), 'X-Request-ID': O.uuid() };
    if (s.access_token) headers.Authorization = 'Bearer ' + s.access_token;
    if (s.tenant_id) headers['X-Tenant-Id'] = s.tenant_id;
    Object.keys(extra || {}).forEach(function (k) { headers[k] = extra[k]; });
    return fetch((cfg.api || '/api/v1') + path, { method: 'POST', headers: headers, body: JSON.stringify(body || {}) }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var m = j && j.message; if (j && j.errors) { for (var k in j.errors) { var v = j.errors[k]; m = Array.isArray(v) ? v[0] : String(v); break; } } throw { status: r.status, message: m || O.t.error }; }
        return j && j.data !== undefined ? j.data : j;
      });
    });
  };

  window.Agent = A;
})();
