// OpesInsure signed-in account area: shared client for every /account page.
// Same API and tokens as the mobile app (api/v1, bearer + X-Tenant-Id). Pages call
//   Opes.page(async function (ctx) { const data = await Opes.api('/mobile/claims'); ... })
// ctx = { session, workspace, ids, path }. Nothing here invents data: empty API results
// render empty states, errors render an alert.
(function () {
  var C = window.OPES_PORTAL || {};
  var API = C.api || '/api/v1';
  var T = C.t || {};
  var KEY = 'opes.web.session';

  function read() { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }
  function write(s) { try { if (s) localStorage.setItem(KEY, JSON.stringify(s)); else localStorage.removeItem(KEY); } catch (e) {} }
  function uuid() { return window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'x' + Date.now() + Math.random().toString(16).slice(2); }

  function toLogin() { location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search); }

  var refreshing = null;
  function refresh() {
    var s = read();
    if (!s || !s.refresh_token) return Promise.resolve(false);
    if (!refreshing) {
      refreshing = fetch(API + '/auth/mobile/refresh', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ refresh_token: s.refresh_token }) })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          var d = j && (j.data || j);
          if (!d || !d.access_token) return false;
          s.access_token = d.access_token; if (d.refresh_token) s.refresh_token = d.refresh_token; write(s); return true;
        }).catch(function () { return false; })
        .finally(function () { refreshing = null; });
    }
    return refreshing;
  }

  /**
   * api(path, {method, body, idem, raw, query}) -> unwrapped `data` (or the full JSON with raw:true).
   * Throws {status, message, errors}. 401 -> one refresh + retry, then back to /login.
   */
  function api(path, o, retried) {
    o = o || {};
    var s = read();
    var url = API + path;
    if (o.query) { var q = new URLSearchParams(); Object.keys(o.query).forEach(function (k) { var v = o.query[k]; if (v !== undefined && v !== null && v !== '') q.set(k, v); }); var qs = q.toString(); if (qs) url += (url.indexOf('?') < 0 ? '?' : '&') + qs; }
    var method = (o.method || (o.body ? 'POST' : 'GET')).toUpperCase();
    var headers = { Accept: 'application/json', 'Accept-Language': C.locale || 'en', 'X-Request-ID': uuid() };
    var body = o.body;
    if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    if (s && s.access_token) headers.Authorization = 'Bearer ' + s.access_token;
    if (s && s.tenant_id) headers['X-Tenant-Id'] = s.tenant_id;
    if (method !== 'GET') headers['Idempotency-Key'] = o.idemKey || (o.idemKey = uuid());
    return fetch(url, { method: method, headers: headers, body: body }).then(function (r) {
      if (r.status === 401 && !retried) return refresh().then(function (ok) { if (ok) return api(path, o, true); write(null); toLogin(); throw { status: 401, message: T.signed_out }; });
      if (r.status === 204) return null;
      var ct = r.headers.get('content-type') || '';
      if (o.blob) return r.ok ? r.blob() : Promise.reject({ status: r.status, message: T.error });
      return (ct.indexOf('json') >= 0 ? r.json() : r.text().then(function (t) { return { message: t }; })).then(function (j) {
        if (!r.ok) {
          var msg = j && j.message;
          if (j && j.errors) { for (var k in j.errors) { var v = j.errors[k]; msg = Array.isArray(v) ? v[0] : String(v); break; } }
          if (r.status === 403) msg = msg || T.forbidden;
          throw { status: r.status, message: msg || T.error, errors: j && j.errors, body: j };
        }
        return o.raw ? j : (j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j);
      });
    });
  }

  /** GET a paginated list: returns {items, meta}. Accepts {data:[...]} or {data:{data:[...]}} shapes. */
  function list(path, query) {
    return api(path, { raw: true, query: query }).then(function (j) {
      var d = j && j.data !== undefined ? j.data : j;
      var items = Array.isArray(d) ? d : (d && Array.isArray(d.data) ? d.data : (d && Array.isArray(d.items) ? d.items : []));
      return { items: items, meta: (j && (j.meta || (d && d.meta))) || {} };
    });
  }

  // ---------- tiny DOM helpers ----------
  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') el.className = v; else if (k === 'html') el.innerHTML = v; else if (k === 'text') el.textContent = v;
      else if (k.indexOf('on') === 0 && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) append(el, arguments[i]);
    return el;
  }
  function append(el, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { append(el, x); }); return; }
    el.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  function icon(name, cls) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg'); svg.setAttribute('class', 'ic ' + (cls || '')); svg.setAttribute('aria-hidden', 'true');
    var use = document.createElementNS(ns, 'use'); use.setAttribute('href', '#i-' + name); svg.appendChild(use); return svg;
  }
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }

  // ---------- formatting ----------
  var loc = C.locale === 'fr' ? 'fr-FR' : 'en-GB';
  /** money(125000) -> "125,000 FCFA"; money({amount_minor:12500000}) or money(12500000, {minor:true}) divides by 100. */
  function money(v, o) {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'object') { if (v.amount_minor !== undefined) v = v.amount_minor / 100; else if (v.amount !== undefined) v = v.amount; else return '—'; }
    else if (o && o.minor) v = Number(v) / 100;
    var n = Number(v); if (!isFinite(n)) return String(v);
    return new Intl.NumberFormat(loc, { maximumFractionDigits: 0 }).format(n) + ' ' + ((o && o.currency) || 'FCFA');
  }
  function date(v, withTime) {
    if (!v) return '—';
    var d = new Date(v); if (isNaN(d)) return String(v);
    var opt = { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Africa/Douala' };
    if (withTime) { opt.hour = '2-digit'; opt.minute = '2-digit'; }
    return new Intl.DateTimeFormat(loc, opt).format(d);
  }
  function label(code) { if (!code) return '—'; var t = (T.status || {})[String(code).toUpperCase()]; return t || String(code).replace(/_/g, ' ').toLowerCase().replace(/^./, function (c) { return c.toUpperCase(); }); }
  var TONE = { ACTIVE: 'ok', PAID: 'ok', APPROVED: 'ok', SETTLED: 'ok', COMPLETED: 'ok', ISSUED: 'ok', SUCCEEDED: 'ok', VALID: 'ok', INSURED: 'ok',
    PENDING: 'warn', SUBMITTED: 'info', UNDER_REVIEW: 'info', IN_REVIEW: 'info', UNDER_ASSESSMENT: 'info', IN_PROGRESS: 'info', PROCESSING: 'info', DRAFT: 'muted', QUOTED: 'info', RATED: 'info',
    EXPIRING: 'warn', PENDING_APPROVAL: 'warn', PENDING_PAYMENT: 'warn', REFERRED: 'warn',
    REJECTED: 'bad', DECLINED: 'bad', FAILED: 'bad', CANCELLED: 'bad', EXPIRED: 'bad', LAPSED: 'bad', NOT_INSURED: 'bad' };
  function chip(code, text) { var c = String(code || '').toUpperCase(); return h('span', { class: 'st st-' + (TONE[c] || 'muted') }, icon(TONE[c] === 'ok' ? 'check' : TONE[c] === 'bad' ? 'x' : 'clock'), text || label(c)); }

  // ---------- page states ----------
  function loading(el) { return clear(el).appendChild(h('div', { class: 'acct-loading', role: 'status' }, h('span', { class: 'spin' }), T.loading)); }
  function empty(el, msg, cta) { clear(el).appendChild(h('div', { class: 'acct-empty' }, icon('doc'), h('p', null, msg || T.empty), cta || null)); }
  function fail(el, err) {
    var m = (err && err.message) || T.error;
    if (el) clear(el).appendChild(h('div', { class: 'acct-empty err' }, icon('help'), h('p', null, m), h('button', { class: 'dbtn dbtn-outline sm', type: 'button', onclick: function () { location.reload(); } }, T.retry)));
    else alert(m);
  }
  function alert(msg, tone) {
    var box = $('.acct-alert'); if (!box) return;
    box.textContent = msg || ''; box.hidden = !msg; box.className = 'acct-alert ' + (tone || 'bad');
    if (msg) box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }
  /** Horizontal stepper like the design: steps = [[title, sub], ...], current index (0-based). */
  function stepper(steps, current) {
    return h('ol', { class: 'stepbar acct-steps' }, steps.map(function (s, i) {
      return h('li', { class: (i < current ? 'done ' : '') + (i === current ? 'cur' : '') }, h('span', { class: 'n' }, i < current ? icon('check') : String(i + 1)), h('span', null, h('b', null, s[0]), s[1] ? h('small', null, s[1]) : null));
    }));
  }
  function busy(btn, on) { if (!btn) return; if (on) { btn.dataset.label = btn.innerHTML; btn.disabled = true; btn.textContent = T.wait; } else { if (btn.dataset.label) btn.innerHTML = btn.dataset.label; btn.disabled = false; } }

  // ---------- session / workspace ----------
  function roleKind(ws) {
    var r = String((ws && ws.role_code) || '').toUpperCase();
    if (/CLAIM|CARRIER|INSURER|ASSESS|ADJUST/.test(r)) return 'officer';
    if (/AGENT|BROKER/.test(r)) return 'agent';
    return 'customer';
  }
  function can(perm) { var s = read(); var p = (s && s.permissions) || []; return p.indexOf('*') >= 0 || p.indexOf(perm) >= 0 || p.some(function (x) { return x.slice(-2) === '.*' && perm.indexOf(x.slice(0, -1)) === 0; }); }

  function loadSession(s) {
    return api('/auth/mobile/session').then(function (d) {
      var wss = (d && d.workspaces) || [];
      var ws = wss.filter(function (w) { return w.tenant_id === s.tenant_id; })[0] || wss.filter(function (w) { return w.customer_id; })[0] || wss[0] || null;
      var user = (d && d.user) || {};
      s.name = user.full_name || s.name || user.phone_e164 || '';
      s.user = user; s.workspaces = wss;
      if (ws) { s.tenant_id = ws.tenant_id; s.customer_id = ws.customer_id; s.role_code = ws.role_code; s.permissions = ws.permissions || []; s.tenant_name = ws.tenant_name; }
      s.kind = roleKind(ws); s.loaded_at = Date.now();
      write(s); return s;
    });
  }

  function paintChrome(s) {
    var n = s.name || '';
    $$('[data-user-name]').forEach(function (e) { e.textContent = n; });
    $$('[data-user-role]').forEach(function (e) { e.textContent = (T.roles || {})[s.kind] || ''; });
    $$('[data-user-initials]').forEach(function (e) { e.textContent = n.split(/\s+/).map(function (p) { return p.charAt(0); }).join('').slice(0, 2).toUpperCase() || '··'; });
    $$('[data-role]').forEach(function (e) { e.hidden = !(e.dataset.role === s.kind || (e.dataset.role === 'agent' && s.kind === 'agent') || (e.dataset.role === 'officer' && s.kind === 'officer')); });
    api('/mobile/notifications', { raw: true, query: { unread: 1, per_page: 1 } }).then(function (j) {
      var n2 = (j && j.meta && (j.meta.unread_count !== undefined ? j.meta.unread_count : j.meta.total)) || 0;
      $$('[data-unread],[data-unread-count]').forEach(function (e) { e.hidden = !n2; if (e.hasAttribute('data-unread-count')) e.textContent = n2; });
    }).catch(function () {});
  }

  function signOut() {
    var s = read(); write(null);
    if (s && s.access_token) fetch(API + '/auth/mobile/logout', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: 'Bearer ' + s.access_token }, body: JSON.stringify({ refresh_token: s.refresh_token }) }).catch(function () {});
    location.href = '/login';
  }

  /** Boot a page: requires sign-in, loads the workspace, paints header/sidebar, then runs fn(ctx). */
  function page(fn) {
    var s = read();
    if (!s || !s.access_token) { toLogin(); return; }
    $$('[data-signout]').forEach(function (b) { b.addEventListener('click', signOut); });
    var ready = s.tenant_id && s.loaded_at && Date.now() - s.loaded_at < 10 * 60 * 1000 ? Promise.resolve(s) : loadSession(s);
    ready.then(function (s2) {
      paintChrome(s2);
      var ws = (s2.workspaces || []).filter(function (w) { return w.tenant_id === s2.tenant_id; })[0] || null;
      return fn && fn({ session: s2, workspace: ws, kind: s2.kind, ids: C.ids || [], path: C.path, params: new URLSearchParams(location.search) });
    }).catch(function (e) { if (e && e.status === 401) return; fail($('[data-page-body]') || null, e); });
  }

  /** Read a File as base64 (no data: prefix) for the JSON upload endpoints. */
  function fileBase64(file) {
    return new Promise(function (res, rej) { var r = new FileReader(); r.onload = function () { res(String(r.result).split(',')[1] || ''); }; r.onerror = rej; r.readAsDataURL(file); });
  }
  /** Open a document/receipt the API returns (signed URL, or a blob download). */
  function openDoc(x) {
    var url = x && (x.url || x.download_url || x.signed_url || (x.data && (x.data.url || x.data.download_url)));
    if (url) { window.open(url, '_blank', 'noopener'); return true; }
    return false;
  }

  window.Opes = { api: api, list: list, h: h, icon: icon, $: $, $$: $$, clear: clear, money: money, date: date, label: label, chip: chip,
    loading: loading, empty: empty, fail: fail, alert: alert, stepper: stepper, busy: busy, page: page, session: read, saveSession: write,
    can: can, uuid: uuid, fileBase64: fileBase64, openDoc: openDoc, signOut: signOut, t: T, ids: C.ids || [], locale: C.locale };
})();
