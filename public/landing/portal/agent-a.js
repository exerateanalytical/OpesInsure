// Q3 launch agent screens: small helpers on top of agent.js (window.Agent). Data only from the real API:
//   GET /mobile/partner/agent/clients/{id}/kyc, POST .../documents, .../kyc/documents, .../kyc/submit  (agent-assisted capture)
//   GET /quotes/{id}, POST /quotes/{id}/send|decline, GET /proposals/{id}[/checklist], POST /proposals/{id}/documents|submit|resubmit|withdraw
(function () {
  var O = window.Opes, A = window.Agent, T = window.LA_T || {}, h = O.h;
  var L = {};

  L.t = function (k, rep) { var s = T[k] !== undefined ? T[k] : A.t(k); if (rep) Object.keys(rep).forEach(function (r) { s = String(s).replace(':' + r, rep[r]); }); return s; };
  L.T = T;
  /** Localised name from {en, fr} / string. */
  L.tr = function (v) { if (!v) return ''; if (typeof v === 'string') return v; return v[O.locale] || v.en || v.fr || Object.values(v)[0] || ''; };
  L.link = function (label, href, cls, icon) { return h('a', { class: 'dbtn ' + (cls || 'dbtn-outline') + ' sm', href: href }, icon ? O.icon(icon) : null, label); };
  L.card = function (title, body, extra) { return h('section', { class: 'acard' }, h('div', { class: 'acard-h' }, h('h2', null, title), extra || null), body); };
  /** Same table as Agent.table, headings from the launch_agent_a copy. */
  L.table = function (heads, rows) {
    return h('div', { class: 'atable-wrap' }, h('table', { class: 'atable ag-table' },
      h('thead', null, h('tr', null, heads.map(function (k) { return h('th', { scope: 'col' }, L.t(k)); }))), h('tbody', null, rows)));
  };
  L.byCustomer = function (rows, id) { return (rows || []).filter(function (r) { return r.customer_id === id; }); };
  L.soft = function (p) { return p.catch(function () { return null; }); };

  /** Validates and reads a file input: {mime_type, file_base64}. */
  L.readFile = function (input) {
    var f = input.files && input.files[0];
    if (!f) return Promise.reject({ message: L.t('file_required') });
    if (['application/pdf', 'image/jpeg', 'image/png'].indexOf(f.type) < 0) return Promise.reject({ message: L.t('file_type') });
    if (f.size > 10 * 1024 * 1024) return Promise.reject({ message: L.t('file_big') });
    return O.fileBase64(f).then(function (b) { return { mime_type: f.type, file_base64: b }; });
  };
  /** Stores a file as the client's own document (agent-assisted capture). */
  L.uploadFor = function (customerId, input, category) {
    return L.readFile(input).then(function (f) {
      return O.api('/mobile/partner/agent/clients/' + encodeURIComponent(customerId) + '/documents', { body: { category: category, mime_type: f.mime_type, file_base64: f.file_base64 } });
    });
  };
  /** The book row (customer_id) of a proposal: the partner proposal list carries it; GET /proposals/{id} carries the party only. */
  L.proposalRow = function (id) { return A.proposals().then(function (rows) { return rows.filter(function (p) { return p.id === id; })[0] || null; }); };

  window.AgentA = L;
})();
