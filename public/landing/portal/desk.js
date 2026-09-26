// Claims Desk (insurer staff / claims officers): shared helpers for /account/claims-desk/*.
// Data comes only from the real API:
//   GET  /mobile/carrier/claims                          queue (carrier.claims.read)
//   GET  /mobile/partner/carrier/claims/{id}             claim + allowed actions + timeline
//   POST /mobile/partner/carrier/claims/{id}/acknowledge | request-information | decisions | decisions/{d}/approve
//   Staff-only extras, used only when the session holds the permission:
//   GET  /claims/{id} (claims.view), GET /claims/{id}/assessments (claims.view),
//   POST /claims/{id}/decisions/{d}/payments, /payments/{p}/approve|processing|paid (claims.payment.*)
(function () {
  var O = window.Opes, T = window.DESK_T || {}, h = O.h;
  var D = {};

  D.t = function (k, rep) { var s = T[k] !== undefined ? T[k] : k; if (rep) Object.keys(rep).forEach(function (r) { s = String(s).replace(':' + r, rep[r]); }); return s; };
  D.st = function (code) { return (T.st || {})[code] || O.label(code); };
  var TONE = { SUBMITTED: 'info', ACKNOWLEDGED: 'info', EVIDENCE_PENDING: 'warn', ASSESSMENT: 'info', INVESTIGATING: 'warn', CARRIER_REVIEW: 'warn', PENDING_APPROVAL: 'warn',
    APPROVED: 'ok', PARTIALLY_APPROVED: 'ok', PAID: 'ok', SETTLED: 'ok', CLOSED: 'muted', DECLINED: 'bad', DISPUTED: 'bad', PAYMENT_PENDING: 'warn', REQUESTED: 'warn', PROCESSING: 'info', FAILED: 'bad', REVERSED: 'bad' };
  D.chip = function (code) { var tone = TONE[code] || 'muted'; return h('span', { class: 'st st-' + tone }, O.icon(tone === 'ok' ? 'check' : tone === 'bad' ? 'x' : 'clock'), D.st(code)); };
  D.money = function (minor) { return minor === null || minor === undefined ? '—' : O.money(minor, { minor: true }); };
  D.type = function (code) { return code ? String(code).replace(/_/g, ' ').toLowerCase().replace(/^./, function (c) { return c.toUpperCase(); }) : '—'; };
  D.base = function (id) { return '/account/claims-desk/' + encodeURIComponent(id); };

  // Queue buckets used by the tabs, stats and the donut.
  D.bucket = function (s) {
    if (s === 'SUBMITTED' || s === 'ACKNOWLEDGED' || s === 'DRAFT') return 'new';
    if (s === 'EVIDENCE_PENDING' || s === 'ASSESSMENT' || s === 'INVESTIGATING' || s === 'REOPENED') return 'assess';
    if (s === 'CARRIER_REVIEW' || s === 'DISPUTED') return 'review';
    if (s === 'APPROVED' || s === 'PARTIALLY_APPROVED' || s === 'PAYMENT_PENDING') return 'approved';
    if (s === 'PAID' || s === 'SETTLED' || s === 'CLOSED') return 'settled';
    if (s === 'DECLINED') return 'rejected';
    return 'new';
  };
  // Stepper position (0..5) from the claim status.
  D.stage = function (s) {
    return { SUBMITTED: 1, ACKNOWLEDGED: 1, EVIDENCE_PENDING: 1, ASSESSMENT: 2, INVESTIGATING: 2, REOPENED: 2, CARRIER_REVIEW: 3, DISPUTED: 3, DECLINED: 3,
      APPROVED: 4, PARTIALLY_APPROVED: 4, PAYMENT_PENDING: 4, PAID: 5, SETTLED: 5, CLOSED: 5 }[s] || 0;
  };

  /** Non-officers get a clear "not available" panel instead of API errors. Returns true when the page may continue. */
  D.guard = function (ctx) {
    if (ctx.kind === 'officer') return true;
    var root = O.$('[data-desk]');
    O.clear(root).appendChild(h('div', { class: 'acard desk-na' }, h('div', { class: 'acct-empty' }, O.icon('lock'), h('h2', null, D.t('na_t')), h('p', null, D.t('na_d')),
      h('a', { class: 'dbtn dbtn-primary sm', href: '/account/claims' }, D.t('na_btn')))));
    return false;
  };

  D.errMsg = function (e) {
    if (!e) return O.t.error;
    if (e.status === 403) return D.t('forbidden');
    if (e.status === 404) return D.t('not_found');
    return e.message || O.t.error;
  };
  D.fail = function (el, e) { O.fail(el, { message: D.errMsg(e) }); };

  /** Load everything the API lets this user see about one claim. */
  D.load = function (id) {
    var staff = O.can('claims.view');
    return Promise.all([
      O.api('/mobile/partner/carrier/claims/' + encodeURIComponent(id)),
      O.list('/mobile/carrier/claims').then(function (r) { return r.items.filter(function (x) { return x.id === id; })[0] || null; }).catch(function () { return null; }),
      staff ? O.api('/claims/' + encodeURIComponent(id)).catch(function () { return null; }) : Promise.resolve(null),
      staff ? O.api('/claims/' + encodeURIComponent(id) + '/assessments').catch(function () { return null; }) : Promise.resolve(null),
    ]).then(function (r) {
      var c = r[0] || {}, q = r[1], s = r[2], a = r[3];
      var ld = (s && s.loss_details) || {};
      c.queue = q; c.staff = s; c.assess = a; c.staffView = !!s;
      c.incident_type = (ld.incident && ld.incident.incident_type) || (q && q.subject && q.subject.split('·').pop().trim()) || null;
      c.loss = ld;
      c.carrier_name = q && q.carrier_name;
      c.payments = (s && s.payments) || null;
      return c;
    });
  };

  D.stepper = function (c) {
    var ev = {}; (c.timeline || []).forEach(function (e) { ev[e.to_status] = ev[e.to_status] || e.occurred_at; });
    var cur = D.stage(c.status), names = T.steps || [];
    var dates = [c.submitted_at, ev.ACKNOWLEDGED || ev.EVIDENCE_PENDING, ev.ASSESSMENT, ev.APPROVED || ev.PARTIALLY_APPROVED || ev.DECLINED || ev.CARRIER_REVIEW, ev.PAID || ev.CLOSED];
    var steps = names.map(function (n, i) { return [n, i < cur ? (dates[i] ? O.date(dates[i], true) : D.t('step_done')) : i === cur ? (c.status === 'DECLINED' ? D.st('DECLINED') : D.t('step_cur')) : D.t('step_pending')]; });
    var el = O.stepper(steps, Math.min(cur, 5));
    el.classList.add('desk-steps');
    if (c.status === 'DECLINED') el.classList.add('stopped');
    return el;
  };

  D.card = function (title, iconName, body, extra) {
    return h('section', { class: 'acard desk-card' }, h('h2', null, h('span', { class: 'dh' }, O.icon(iconName), title), extra || null), body);
  };
  D.note = function (text, tone) { return h('p', { class: 'desk-note ' + (tone || '') }, O.icon(tone === 'bad' ? 'lock' : 'help'), h('span', null, text)); };
  D.field = function (label, value, cls) { return h('div', { class: 'desk-f ' + (cls || '') }, h('small', null, label), h('b', null, value === null || value === undefined || value === '' ? '—' : value)); };

  D.infoCard = function (c) {
    return D.card(D.t('claim_info'), 'doc', h('div', { class: 'desk-fields c4' },
      D.field(D.t('claim_no'), c.claim_number),
      D.field(D.t('policy_no'), c.policy_number),
      D.field(D.t('customer'), c.customer_name),
      D.field(D.t('incident_type'), D.type(c.incident_type)),
      D.field(D.t('incident_date'), O.date(c.loss_occurred_at, true)),
      D.field(D.t('location'), c.loss_location),
      D.field(D.t('status'), D.chip(c.status)),
      c.approved_amount_minor !== null ? D.field(D.t('approved_amt'), D.money(c.approved_amount_minor), 'money ok') : D.field(D.t('est_cost'), D.money(c.estimated_loss_minor), 'money')));
  };

  D.timeline = function (c) {
    var ev = c.timeline || [];
    if (!ev.length) return h('p', { class: 'sub' }, D.t('no_events'));
    return h('ol', { class: 'timeline desk-tl' }, ev.map(function (e, i) {
      var last = i === ev.length - 1;
      return h('li', { class: last && D.stage(c.status) < 5 ? 'cur' : 'done' }, h('span', { class: 'dotc' }), h('b', null, (T.ev || {})[e.to_status] || D.st(e.to_status)), h('small', null, O.date(e.occurred_at, true) + (e.reason_code && e.reason_code !== e.to_status ? ' · ' + D.type(e.reason_code) : '')));
    }));
  };

  /** Small modal for a required note (request information, etc.). Resolves with the text or null. */
  D.prompt = function (title, placeholder) {
    return new Promise(function (res) {
      var ta = h('textarea', { placeholder: placeholder, minlength: '5', maxlength: '2000', required: true });
      var dlg = h('dialog', { class: 'desk-dlg' }, h('form', { method: 'dialog' },
        h('h2', null, title), h('label', { class: 'afield-s' }, ta),
        h('div', { class: 'btnbar' }, h('button', { class: 'dbtn dbtn-outline sm', value: 'cancel', formnovalidate: true }, D.t('cancel')), h('button', { class: 'dbtn dbtn-primary sm', value: 'ok' }, D.t('send')))));
      document.body.appendChild(dlg);
      dlg.addEventListener('close', function () { var v = dlg.returnValue === 'ok' ? ta.value.trim() : null; dlg.remove(); res(v); });
      dlg.showModal(); ta.focus();
    });
  };

  /** Run an API action from a button: busy state, 403 -> clear message, then onOk(result). */
  D.act = function (btn, fn, okMsg, onOk) {
    O.busy(btn, true); O.alert('');
    return fn().then(function (r) { O.busy(btn, false); if (okMsg) O.alert(okMsg, 'ok'); if (onOk) onOk(r); return r; })
      .catch(function (e) { O.busy(btn, false); O.alert(D.errMsg(e), 'bad'); });
  };

  D.reasonSelect = function (decision) {
    var r = T.reasons || {}, pick = decision === 'DECLINE' ? ['NOT_COVERED', 'EXCLUSION_APPLIES', 'POLICY_NOT_IN_FORCE', 'INSUFFICIENT_EVIDENCE', 'FRAUD_SUSPECTED'] : decision === 'PARTIAL' ? ['PARTIAL_COVER', 'EXCLUSION_APPLIES'] : ['COVERED_LOSS'];
    return pick.map(function (k) { return h('option', { value: k }, r[k] || k); });
  };

  window.Desk = D;
})();
