// Launch 2026-10-02 (Q2): helpers shared by the customer dashboard, action centre (CUST-019) and onboarding
// overview (CUST-009). Everything is computed from the SAME /api/v1 endpoints the app uses — no new backend:
// /mobile/kyc/profile, /auth/mobile/session, /mobile/account/customer-profile, /mobile/wallet, /mobile/proposals,
// /mobile/payments, /mobile/quotes, /mobile/claims, /mobile/support/cases, /mobile/complaints, /mobile/notifications.
// Strings: lang/<locale>/launch_customer.php (js) as window.OPES_LC.
(function () {
  var L = window.OPES_LC || {};
  var h = Opes.h;
  function fmt(s, v) { s = String(s || ''); Object.keys(v || {}).forEach(function (k) { s = s.split(':' + k).join(v[k]); }); return s; }
  function soft(p) { return p.then(function (x) { return x; }, function () { return null; }); }
  function items(path) { return soft(Opes.list(path, { per_page: 100 }).then(function (r) { return r.items; })); }
  function up(s) { return String(s || '').toUpperCase(); }

  var cache = {};
  function once(k, fn) { if (!cache[k]) cache[k] = fn(); return cache[k]; }
  function kyc() { return once('kyc', function () { return soft(Opes.api('/mobile/kyc/profile')); }); }
  function session() { return once('ses', function () { return soft(Opes.api('/auth/mobile/session')); }); }
  function profile() { return once('prof', function () { return soft(Opes.api('/mobile/account/customer-profile')); }); }
  function quotes() { return once('q', function () { return items('/mobile/quotes'); }); }
  function support() { return once('sup', function () { return soft(Opes.api('/mobile/support/cases')); }); }
  function complaints() { return once('cpl', function () { return soft(Opes.api('/mobile/complaints')); }); }
  function unread() { return once('un', function () { return soft(Opes.api('/mobile/notifications', { raw: true, query: { unread: 1, per_page: 1 } }).then(function (j) { return (j && j.meta && (j.meta.unread_count !== undefined ? j.meta.unread_count : j.meta.total)) || 0; })); }); }
  function wrapSoft(fn) { return soft(fn()); }

  /** KYC state: NONE | DRAFT | SUBMITTED | IN_REVIEW | APPROVED | MORE_INFO_REQUIRED | REJECTED | EXPIRED */
  function kycState(p) {
    var s = p && p.submission; if (!s) return 'NONE';
    var st = up(s.status);
    if (st === 'UNDER_REVIEW') st = 'IN_REVIEW';
    if (st === 'VERIFIED') st = 'APPROVED';
    if (st === 'APPROVED' && s.expires_at && new Date(s.expires_at) < new Date()) st = 'EXPIRED';
    return st;
  }

  /** Onboarding completion (CUST-009): identity, contact, KYC, required documents. */
  function onboarding() {
    return Promise.all([session(), profile(), kyc()]).then(function (r) {
      var u = (r[0] && r[0].user) || {}, pr = r[1] || {}, k = r[2] || {}, F = (L.onb || {}).fix || {};
      var st = kycState(k), sub = k.submission || null, miss = (sub && sub.missing_requirements) || [];
      var steps = [
        { key: 'identity', href: '/account/profile', todo: [!(pr.full_name || u.full_name) || !pr.date_of_birth ? F.identity : null] },
        { key: 'contact', href: '/account/profile', todo: [!u.phone_verified ? F.phone : null, !u.email || !u.email_verified ? F.email : null, !pr.address_line1 && !pr.city ? F.address : null] },
        { key: 'kyc', href: '/account/kyc', todo: [!(k.identifiers || []).length ? F.kyc_id : null,
          st === 'NONE' || st === 'DRAFT' ? F.kyc_submit : st === 'MORE_INFO_REQUIRED' || st === 'REJECTED' || st === 'EXPIRED' ? F.kyc_fix : st === 'SUBMITTED' || st === 'IN_REVIEW' ? F.kyc_wait : null] },
        { key: 'docs', href: '/account/kyc#remediation', todo: miss.map(function (m) { return fmt(F.doc, { doc: Opes.label(m) }); }) }
      ];
      steps.forEach(function (s) { s.todo = s.todo.filter(Boolean); s.done = !s.todo.length; });
      var done = steps.filter(function (s) { return s.done; }).length;
      return { steps: steps, pct: Math.round(done * 100 / steps.length), next: steps.filter(function (s) { return !s.done; })[0] || null, kyc: st };
    });
  }

  var OPEN_QUOTE = /^(RATED|OFFERED|QUOTED|SENT|GENERATED|COUNTEROFFERED|PRESENTED)$/;
  var CLAIM_WAIT = /^(PENDING_CUSTOMER|EVIDENCE_PENDING|INFORMATION_REQUESTED|MORE_INFO_REQUIRED|SETTLEMENT_OFFERED|OFFER_MADE|AWAITING_CUSTOMER)$/;
  var RANK = { HIGH: 0, MEDIUM: 1, LOW: 2 };

  /** Action centre items (CUST-019): {priority, icon, title, reason, due, href, cta}, most urgent first. */
  function actions() {
    var A = L.act || {}, G = A.go || {};
    return Promise.all([kyc(), wrapSoft(OP.policies), wrapSoft(OP.proposals), wrapSoft(OP.payments), quotes(), wrapSoft(OP.claims), support(), complaints(), unread()]).then(function (r) {
      var out = [], k = r[0], pols = r[1] || [], props = r[2] || [], pays = r[3] || [], qs = r[4] || [], cls = r[5] || [], sup = r[6] || [], cpl = r[7] || [], un = r[8] || 0;
      function add(p, icon, t, v, due, href, cta) { out.push({ priority: p, icon: icon, title: fmt(t[0], v), reason: fmt(t[1], v), due: due || null, href: href, cta: cta }); }

      var ks = kycState(k);
      if (ks === 'MORE_INFO_REQUIRED' || ks === 'REJECTED') add('HIGH', 'check', A.kyc_fix, {}, null, '/account/kyc#remediation', G.kyc);
      else if (ks === 'EXPIRED') add('HIGH', 'check', A.kyc_expired, {}, k.submission && k.submission.expires_at, '/account/kyc', G.kyc);
      else if (ks === 'NONE' || ks === 'DRAFT') add('MEDIUM', 'check', A.kyc_start, {}, null, '/account/kyc', G.kyc);

      props.forEach(function (p) {
        if (up(p.status) !== 'PAYMENT_PENDING') return;
        var paid = pays.filter(function (x) { return x.proposal_id === p.id && OP.ok(x); }).reduce(function (s, x) { return s + (x.amount_minor || 0); }, 0);
        if (paid >= (p.total_minor || 0)) return;
        add('HIGH', 'card', A.pay, { product: p.product_name || OP.line(p.line_code) }, p.expires_at || p.payment_due_at, '/account/payments/new?proposal=' + p.id, G.pay);
      });
      qs.forEach(function (q) {
        if (q.is_expired || !OPEN_QUOTE.test(up(q.status))) return;
        add('MEDIUM', 'compare', A.quote, { number: q.quote_number || '' }, q.expires_at, '/account/quotes/' + q.id, G.quote);
      });
      cls.forEach(function (c) {
        if (!CLAIM_WAIT.test(up(c.status))) return;
        add('HIGH', 'shield', A.claim, { number: c.claim_number || '' }, c.due_at || null, '/account/claims/' + c.id, G.claim);
      });
      pols.forEach(function (p) {
        var s = up(p.status), d = p.days_to_expiry;
        if (s === 'ACTIVE' && typeof d === 'number' && d >= 0 && d <= 30) add(d <= 7 ? 'HIGH' : 'MEDIUM', 'refresh', A.renew, { product: OP.title(p), n: d }, p.coverage_ends_at, '/account/requests?policy=' + p.id + '#renew', G.renew);
        else if (s === 'EXPIRED' && typeof d === 'number' && d >= -30) add('LOW', 'refresh', A.lapsed, { product: OP.title(p) }, p.coverage_ends_at, '/account/requests?policy=' + p.id + '#renew', G.renew);
      });
      sup.forEach(function (t) { if (up(t.status) === 'WAITING_CUSTOMER') add('MEDIUM', 'headset', A.support, { number: t.reference || '' }, null, '/account/support', G.support); });
      cpl.forEach(function (c) { if (c.communicated_at && c.open) add('LOW', 'doc', A.complaint, { number: c.complaint_number }, null, '/account/complaints/' + c.id, G.complaint); });
      if (un > 0) add('LOW', 'bell', A.notif, { n: un }, null, '/account/notifications', G.notif);

      out.sort(function (a, b) { return RANK[a.priority] - RANK[b.priority] || (a.due ? new Date(a.due) : Infinity) - (b.due ? new Date(b.due) : Infinity); });
      return out;
    });
  }

  function prioChip(p) { var A = L.act || {}; return h('span', { class: 'st ' + (p === 'HIGH' ? 'st-bad' : p === 'MEDIUM' ? 'st-warn' : 'st-muted') }, (A.p || {})[p] || p); }

  /** One action row as a list item (dashboard) — the full table is on /account/actions. */
  function actionItem(a) {
    return h('li', { 'data-action': a.priority }, h('span', { class: 'op-li' }, Opes.icon(a.icon)),
      h('div', null, h('b', null, a.title), h('small', { class: 'op-muted', style: 'display:block' }, a.reason + (a.due ? ' · ' + Opes.date(a.due) : ''))),
      h('div', { class: 'lc-act-cta' }, prioChip(a.priority), h('a', { class: 'dbtn dbtn-outline sm', href: a.href }, a.cta)));
  }

  function progress(pct) {
    return h('div', { class: 'lc-progress', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': pct, style: 'height:10px;border-radius:6px;background:#E6ECF5;overflow:hidden' },
      h('span', { style: 'display:block;height:100%;width:' + pct + '%;background:#1B5FD9' }));
  }

  window.LC = { T: L, fmt: fmt, soft: soft, kyc: kyc, kycState: kycState, session: session, profile: profile, quotes: quotes, support: support, complaints: complaints, unread: unread,
    onboarding: onboarding, actions: actions, actionItem: actionItem, prioChip: prioChip, progress: progress };
})();
