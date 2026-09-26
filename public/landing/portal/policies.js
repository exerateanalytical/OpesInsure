// Helpers shared by the policies-area account pages (dashboard, policies, payments, documents,
// vehicles, profile, notifications, support). Strings come from lang/<locale>/account_policies.php (js).
(function () {
  var T = window.OPES_POL || {};
  var h = Opes.h;

  function fmt(s, v) { s = String(s || ''); Object.keys(v || {}).forEach(function (k) { s = s.split(':' + k).join(v[k]); }); return s; }
  function st(code) { var c = String(code || '').toUpperCase(); return (T.status || {})[c] || Opes.label(c); }
  function chip(code) {
    var c = String(code || '').toUpperCase();
    if (c === 'EXPIRING') return h('span', { class: 'st st-warn' }, Opes.icon('clock'), st(c));
    if (c === 'INSURED' || c === 'VALID') return h('span', { class: 'st st-ok' }, Opes.icon('check'), st(c));
    if (c === 'NOT_INSURED') return h('span', { class: 'st st-bad' }, Opes.icon('x'), st(c));
    if (c === 'PENDING_CUSTOMER' || c === 'PAYMENT_PENDING' || c === 'EVIDENCE_PENDING' || c === 'OPEN') return h('span', { class: 'st st-warn' }, Opes.icon('clock'), st(c));
    return Opes.chip(c, st(c));
  }
  var LINE_ICON = { MOTOR: 'motor', HOME: 'home', TRAVEL: 'travel', HEALTH: 'health', LIFE: 'life', ACCIDENT: 'accident', BUSINESS: 'business' };
  function lineIcon(code) { return LINE_ICON[String(code || '').toUpperCase()] || 'shield'; }
  function line(code) { return (T.lines || {})[String(code || '').toUpperCase()] || Opes.label(code); }
  function mm(v) { return v === null || v === undefined ? '—' : Opes.money(v, { minor: true }); }
  function provider(p) { return (T.providers || {})[p] || Opes.label(p); }

  function lineOf(p) { return p.line_code || (p.terms_snapshot && p.terms_snapshot.line_code) || ''; }
  function facts(p) { return (p.terms_snapshot && p.terms_snapshot.risk_facts) || {}; }
  /** "Toyota Corolla 2019" / registration, or null when the policy has no vehicle facts. */
  function risk(p) {
    var f = facts(p);
    var name = [f.make, f.model, f.year].filter(Boolean).join(' ');
    return { name: name || null, reg: f.registration_number || null, facts: f };
  }
  function title(p) { return p.product_name || (p.terms_snapshot && p.terms_snapshot.product) || line(lineOf(p)); }
  function total(p) { var t = p.terms_snapshot || {}; return t.total_minor !== undefined ? t.total_minor : p.premium_minor; }
  /** ACTIVE, EXPIRING (active and ends within 30 days) or the API status. */
  function state(p) {
    var s = String(p.status || '').toUpperCase();
    if (s === 'ACTIVE' && typeof p.days_to_expiry === 'number' && p.days_to_expiry <= 30) return 'EXPIRING';
    return s;
  }
  function daysNote(p) {
    if (typeof p.days_to_expiry !== 'number') return '';
    return p.days_to_expiry >= 0 ? fmt(T.days_left, { n: p.days_to_expiry }) : fmt(T.expired_ago, { n: -p.days_to_expiry });
  }
  function mark(name) {
    var ini = String(name || '?').replace(/[^A-Za-zÀ-ÿ ]/g, '').split(/\s+/).filter(Boolean).map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase();
    return h('span', { class: 'op-mark', 'aria-hidden': 'true' }, ini || '?');
  }
  function stat(icon, tone, label, value, sub) {
    return h('div', { class: 'statc' }, h('span', { class: 'op-sq ' + tone }, Opes.icon(icon)), h('div', null, h('small', { class: 'op-sl' }, label), h('b', null, value), sub ? h('small', null, sub) : null));
  }
  function card(ttl, sub, action) {
    var el = h('section', { class: 'acard' }, h('div', { class: 'acard-h' }, h('h2', null, ttl), action || null), sub ? h('p', { class: 'sub' }, sub) : null);
    return el;
  }
  function btn(text, href, cls, icon) { return h('a', { class: 'dbtn ' + (cls || 'dbtn-outline sm'), href: href }, icon ? Opes.icon(icon) : null, text); }

  /** Open a URL obtained asynchronously without the popup blocker eating it. */
  function openAsync(getUrl) {
    var w = window.open('', '_blank');
    return Promise.resolve().then(getUrl).then(function (url) {
      if (!url) throw { message: Opes.t.error };
      if (w) w.location.href = url; else location.href = url;
    }).catch(function (e) { if (w) w.close(); Opes.alert((e && e.message) || Opes.t.error); });
  }
  function openDocument(id) { return openAsync(function () { return Opes.api('/mobile/documents/' + id + '/access', { body: {} }).then(function (d) { return d && (d.url || d.download_url); }); }); }
  function openReceipt(pid) { return openAsync(function () { return Opes.api('/mobile/payments/' + pid + '/receipt').then(function (d) { return d && d.download_url; }); }); }

  var cache = {};
  function once(key, fn) { if (!cache[key]) cache[key] = fn().catch(function (e) { delete cache[key]; throw e; }); return cache[key]; }
  function policies() { return once('w', function () { return Opes.list('/mobile/wallet', { per_page: 100 }).then(function (r) { return r.items; }); }); }
  function payments() { return once('p', function () { return Opes.list('/mobile/payments', { per_page: 100 }).then(function (r) { return r.items; }); }); }
  function proposals() { return once('pr', function () { return Opes.list('/mobile/proposals', { per_page: 100 }).then(function (r) { return r.items; }); }); }
  function claims() { return once('c', function () { return Opes.list('/mobile/claims', { per_page: 100 }).then(function (r) { return r.items; }); }); }
  var CLOSED_CLAIM = /^(CLOSED|SETTLED|PAID|REJECTED|DECLINED|WITHDRAWN|CANCELLED|DENIED)$/;
  function claimOpen(c) { return !CLOSED_CLAIM.test(String(c.status || '').toUpperCase()); }
  function ok(pay) { return String(pay.status).toUpperCase() === 'SUCCEEDED'; }

  /** Table helper: cols = [[label, fn(row)->node|string]]. */
  function table(cols, rows, cls) {
    return h('div', { class: 'atable-wrap' }, h('table', { class: 'atable ' + (cls || '') },
      h('thead', null, h('tr', null, cols.map(function (c) { return h('th', { scope: 'col' }, c[0]); }))),
      h('tbody', null, rows.map(function (r) { return h('tr', null, cols.map(function (c) { var v = c[1](r); return h('td', { 'data-l': c[0] }, v === null || v === undefined || v === '' ? '—' : v); })); }))));
  }

  window.OP = { T: T, fmt: fmt, st: st, chip: chip, line: line, lineIcon: lineIcon, lineOf: lineOf, mm: mm, provider: provider, risk: risk, facts: facts, title: title, total: total,
    state: state, daysNote: daysNote, mark: mark, stat: stat, card: card, btn: btn, openAsync: openAsync, openDocument: openDocument, openReceipt: openReceipt,
    policies: policies, payments: payments, proposals: proposals, claims: claims, claimOpen: claimOpen, ok: ok, table: table };
})();
