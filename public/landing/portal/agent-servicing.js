// Agent servicing pages (launch 2026-10-02, AGT-037..056): policy detail / documents / change request / cancellation,
// service-request tracking, renewal detail, payments, vehicles, stickers, claims portfolio / detail / evidence, tasks.
// Loaded after agent.js (window.Agent). Data only from the API, all agent-own (book) scoped server-side:
//   GET  /mobile/partner/agent/policies/{id}, /service-requests[/{id}], /payments, /clients/{id}/payments|vehicles,
//        /vehicles/{id}, /stickers, /claims/{id}
//   POST /mobile/partner/agent/policies/{id}/service-requests|cancellations[/preview]|sticker, /clients/{id}/vehicles
//   POST /sticker-handovers/{id}/accept|reject (StickerCustodyService: only the receiving agent may decide)
(function () {
  var O = window.Opes, A = window.Agent, T = window.AGENT_B_T || {}, h = O.h;
  var S = {}, BASE = '/mobile/partner/agent';

  S.t = function (k, rep) { var s = T[k] !== undefined ? T[k] : k; if (rep) Object.keys(rep).forEach(function (r) { s = String(s).replace(':' + r, rep[r]); }); return s; };
  S.label = function (code) { return (T.st || {})[code] || A.label(code); };
  S.chip = function (code) { return O.chip(code, S.label(code)); };
  S.enc = encodeURIComponent;

  /** Agent-only pages (brokers use the /broker panel for servicing). Returns true when the page may continue. */
  S.guard = function (ctx) {
    if (!A.guard(ctx)) return false;
    if (A.mode() === 'agent') return true;
    var root = O.$('[data-agent]');
    O.clear(root).appendChild(h('div', { class: 'acard desk-na' }, h('div', { class: 'acct-empty' }, O.icon('lock'), h('h2', null, S.t('agent_only_t')), h('p', null, S.t('agent_only_d')),
      h('a', { class: 'dbtn dbtn-primary sm', href: '/account/book' }, S.t('to_book')))));
    return false;
  };
  S.table = function (heads, rows) {
    return h('div', { class: 'atable-wrap' }, h('table', { class: 'atable ag-table' },
      h('thead', null, h('tr', null, heads.map(function (k) { return h('th', { scope: 'col' }, S.t(k)); }))), h('tbody', null, rows)));
  };
  S.row = function (cells) { return h('tr', null, cells.map(function (c) { return h('td', null, c); })); };
  S.btn = function (label, href, cls, iconName) { return h('a', { class: 'dbtn ' + (cls || 'dbtn-outline') + ' sm', href: href }, iconName ? O.icon(iconName) : null, label); };
  S.money = A.money;
  S.canManage = function () { return O.can('agent.clients.manage'); };

  // ---------- links ----------
  S.policyUrl = function (id) { return '/account/book/policies/' + S.enc(id); };
  S.claimUrl = function (id) { return '/account/book/claims/' + S.enc(id); };
  S.clientUrl = function (id) { return '/account/customers/' + S.enc(id); };
  S.clientLink = function (id, name) { return id ? h('a', { href: S.clientUrl(id) }, name || '—') : (name || '—'); };

  // ---------- data ----------
  S.policy = function (id) { return O.api(BASE + '/policies/' + S.enc(id)); };
  S.requests = function () { return O.list(BASE + '/service-requests').then(function (r) { return r.items; }); };
  S.request = function (id) { return O.api(BASE + '/service-requests/' + S.enc(id)); };
  S.payments = function () { return O.list(BASE + '/payments').then(function (r) { return r.items; }); };
  S.clientPayments = function (id) { return O.list(BASE + '/clients/' + S.enc(id) + '/payments').then(function (r) { return r.items; }); };
  S.vehicles = function (id) { return O.list(BASE + '/clients/' + S.enc(id) + '/vehicles').then(function (r) { return r.items; }); };
  S.vehicle = function (id) { return O.api(BASE + '/vehicles/' + S.enc(id)); };
  S.stickers = function () { return O.api(BASE + '/stickers'); };
  S.claim = function (id) { return O.api(BASE + '/claims/' + S.enc(id)); };

  /** Policy header card used by the policy sub-pages (documents, change request, cancellation). */
  S.policyHead = function (p) {
    return h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('span', { class: 'av' }, O.icon('doc')), h('div', null, h('h2', null, p.policy_number || '—'),
      h('small', null, [p.customer_name, p.carrier_name, A.line(p.line_code)].filter(Boolean).join(' · ')))),
      h('div', { class: 'btns' }, S.chip(p.status), S.btn(S.t('back_policy'), S.policyUrl(p.id))));
  };

  /** A labelled form field. */
  S.field = function (label, input, required) { return h('label', { class: 'afield-s' }, h('span', null, label, required ? h('i', null, ' *') : null), input); };

  window.AgentS = S;
})();
