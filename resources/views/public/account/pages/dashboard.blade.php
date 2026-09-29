{{-- /account — customer dashboard (CUST-017): KPIs (active policies, open claims, outstanding premium, expiring soon), action required
     (LC.actions — CUST-019), profile completion (CUST-009), my policies, claims status, payments due, renewals, recent documents, recent
     payments, notifications, quick actions. All from the app's endpoints: /mobile/wallet, /mobile/proposals, /mobile/payments, /mobile/claims,
     /mobile/documents, /mobile/notifications, /mobile/kyc/profile, /mobile/quotes, /mobile/support/cases, /mobile/complaints. --}}
@extends('public.account.layout', ['title' => __('account_policies.dash.title'), 'lede' => __('account_policies.dash.lede'), 'crumbs' => [[__('account_policies.dash.title'), null]], 'active' => 'dashboard'])
@section('content')
@include('public.account.partials.launch-assets')
<div class="stats" data-stats></div>
<section class="acard" data-onb hidden style="margin-bottom:16px"></section>
<div class="op-dash" data-page-body>
  <div class="col">
    <section class="acard" data-actions></section>
    <section class="acard" data-recent></section>
    <section class="acard" data-claims></section>
    <section class="acard" data-pays></section>
  </div>
  <div class="col">
    <section class="acard" data-quick></section>
    <section class="acard" data-due></section>
    <section class="acard" data-renew></section>
    <section class="acard" data-docs></section>
    <section class="acard" data-notes></section>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  // Q3 launch: agents get their own dashboard (AGT-004, /account/agent).
  if (ctx.kind === 'agent' && Opes.can('agent.clients.read')) { location.replace('/account/agent'); return; }
  var h = Opes.h, T = OP.T, D = T.dash, X = LC.T.dash, $ = Opes.$;
  var stats = $('[data-stats]'), recent = $('[data-recent]'), pays = $('[data-pays]'), quick = $('[data-quick]'), notes = $('[data-notes]');
  var acts = $('[data-actions]'), claimsB = $('[data-claims]'), dueB = $('[data-due]'), renB = $('[data-renew]'), docsB = $('[data-docs]'), onb = $('[data-onb]');
  function head(t, href) { return h('div', { class: 'acard-h' }, h('h2', null, t), href ? h('a', { class: 'rowlink', href: href }, T.view_all) : null); }
  // KPI tiles drill down (CUST-017): the whole tile is a link.
  function kpi(href, node) { node.style.position = 'relative'; node.appendChild(h('a', { href: href, style: 'position:absolute;inset:0', 'aria-label': node.textContent })); return node; }
  function emptyIn(el, msg, cta) { var e = h('div'); el.appendChild(e); Opes.empty(e, msg, cta); }

  Opes.clear(quick).append(h('h2', null, D.quick), h('div', { class: 'op-qa' },
    OP.btn(D.q_quote, '/account/buy', 'dbtn-primary', 'compare'), OP.btn(D.q_claim, '/account/claims/new', 'dbtn-outline', 'shield'),
    OP.btn(D.q_pay, '/account/payments/new', 'dbtn-navy', 'card'), OP.btn(D.q_docs, '/account/documents', 'dbtn-outline', 'doc'),
    OP.btn(D.q_veh, '/account/vehicles', 'dbtn-outline', 'motor'), OP.btn(X.q_needs, '/account/needs', 'dbtn-outline', 'bulb'),
    OP.btn(X.q_search, '/account/search', 'dbtn-outline', 'search'), OP.btn(X.q_msgs, '/account/messages', 'dbtn-outline', 'chat'),
    OP.btn(X.q_complaint, '/account/complaints', 'dbtn-outline', 'edit'), OP.btn(D.q_help, '/account/support', 'dbtn-outline', 'headset')));

  [recent, pays, notes, acts, claimsB, dueB, renB, docsB].forEach(Opes.loading);
  var soft = LC.soft;

  // Profile completion banner (CUST-009) — only while something is left to do.
  LC.onboarding().then(function (o) {
    if (o.pct >= 100) return;
    onb.hidden = false;
    Opes.clear(onb).append(h('div', { class: 'acard-h' }, h('h2', null, LC.fmt(X.onb, { pct: o.pct })), OP.btn(X.onb_go, '/account/onboarding', 'dbtn-primary sm', 'user')), LC.progress(o.pct));
  }).catch(function () {});

  // Action required (CUST-019): top 5, full list on /account/actions.
  LC.actions().then(function (list) {
    Opes.clear(acts).appendChild(h('div', { class: 'acard-h' }, h('h2', null, X.actions_t), h('a', { class: 'rowlink', href: '/account/actions' }, X.actions_all)));
    if (!list.length) { emptyIn(acts, LC.T.act.none); return; }
    acts.appendChild(h('ul', { class: 'op-nlist' }, list.slice(0, 5).map(LC.actionItem)));
  }).catch(function (e) { Opes.fail(acts, e); });

  Promise.all([OP.policies(), soft(OP.claims()), soft(OP.proposals()), soft(OP.payments())]).then(function (r) {
    var pols = r[0], cl = r[1], pr = r[2], py = r[3];
    var active = pols.filter(function (p) { return String(p.status).toUpperCase() === 'ACTIVE'; });
    var exp = pols.filter(function (p) { return OP.state(p) === 'EXPIRING'; });
    var paidFor = function (p) { return (py || []).filter(function (x) { return x.proposal_id === p.id && OP.ok(x); }).reduce(function (s, x) { return s + (x.amount_minor || 0); }, 0); };
    var due = pr ? pr.filter(function (p) { return String(p.status).toUpperCase() === 'PAYMENT_PENDING' && paidFor(p) < (p.total_minor || 0); }) : null;
    var outstanding = due ? due.reduce(function (s, p) { return s + ((p.total_minor || 0) - paidFor(p)); }, 0) : null;
    Opes.clear(stats).append(
      kpi('/account/policies', OP.stat('shield', 'blue', D.s_active, active.length, D.s_active_d)),
      kpi('/account/claims', OP.stat('doc', 'navy', D.s_claims, cl ? cl.filter(OP.claimOpen).length : '—', D.s_claims_d)),
      kpi('/account/payments/new', OP.stat('card', 'orange', X.s_outstanding, outstanding === null ? '—' : OP.mm(outstanding), due ? due.length + ' · ' + X.s_outstanding_d : X.s_outstanding_d)),
      kpi('/account/policies', OP.stat('clock', exp.length ? 'red' : 'green', D.s_exp, exp.length, D.s_exp_d)));

    // My insurance
    Opes.clear(recent).append(head(D.recent, '/account/policies'), h('p', { class: 'sub' }, D.recent_d));
    if (!pols.length) emptyIn(recent, T.no_policies, OP.btn(T.get_quote, '/account/buy', 'dbtn-primary sm'));
    else recent.appendChild(OP.table([
      [T.pol.policy, function (p) { return h('div', { class: 'op-cell' }, h('span', { class: 'op-li' }, Opes.icon(OP.lineIcon(OP.lineOf(p)))), h('div', null, h('b', null, OP.title(p)), h('small', null, p.carrier_short_name || p.carrier_name || ''))); }],
      [T.pol.number, function (p) { return p.policy_number; }],
      [T.pol.period, function (p) { return h('div', null, Opes.date(p.coverage_starts_at) + ' – ' + Opes.date(p.coverage_ends_at), h('small', { class: 'op-muted', style: 'display:block' }, OP.daysNote(p))); }],
      [T.pol.status, function (p) { return OP.chip(OP.state(p)); }],
      ['', function (p) { return OP.btn(T.view, '/account/policies/' + p.id); }]
    ], pols.slice(0, 5), 'op-stack'));

    // Claims status
    Opes.clear(claimsB).appendChild(head(X.claims_t, '/account/claims'));
    if (!cl) Opes.fail(claimsB);
    else if (!cl.length) emptyIn(claimsB, X.no_claims, OP.btn(D.q_claim, '/account/claims/new', 'dbtn-outline sm'));
    else claimsB.appendChild(OP.table([
      [X.claim_no, function (c) { return h('b', null, c.claim_number || '—'); }],
      [X.claim_date, function (c) { return Opes.date(c.submitted_at || c.created_at); }],
      [X.claim_st, function (c) { return OP.chip(c.status); }],
      ['', function (c) { return OP.btn(T.view, '/account/claims/' + c.id); }]
    ], cl.slice(0, 5), 'op-stack'));

    // Payments due
    Opes.clear(dueB).appendChild(head(X.due_t, '/account/payments'));
    if (!due) Opes.fail(dueB);
    else if (!due.length) emptyIn(dueB, X.no_due);
    else dueB.appendChild(h('ul', { class: 'op-nlist' }, due.slice(0, 5).map(function (p) {
      return h('li', null, h('span', { class: 'op-li' }, Opes.icon('card')), h('div', null, h('b', null, p.product_name || OP.line(p.line_code)), h('small', { class: 'op-muted', style: 'display:block' }, OP.mm((p.total_minor || 0) - paidFor(p)))),
        OP.btn(X.pay, '/account/payments/new?proposal=' + p.id, 'dbtn-primary sm'));
    })));

    // Renewals (active policies ending within 60 days, or expired in the last 30)
    var ren = pols.filter(function (p) { var d = p.days_to_expiry, s = String(p.status).toUpperCase(); return typeof d === 'number' && ((s === 'ACTIVE' && d <= 60) || (s === 'EXPIRED' && d >= -30)); })
      .sort(function (a, b) { return a.days_to_expiry - b.days_to_expiry; });
    Opes.clear(renB).appendChild(head(X.renew_t, '/account/requests'));
    if (!ren.length) emptyIn(renB, X.no_renew);
    else renB.appendChild(h('ul', { class: 'op-nlist' }, ren.slice(0, 5).map(function (p) {
      return h('li', null, h('span', { class: 'op-li' }, Opes.icon('refresh')), h('div', null, h('b', null, OP.title(p)), h('small', { class: 'op-muted', style: 'display:block' }, (p.policy_number || '') + ' · ' + OP.daysNote(p))),
        OP.btn(X.renew, '/account/requests?policy=' + p.id + '#renew', 'dbtn-outline sm'));
    })));

    // Recent payments
    Opes.clear(pays).appendChild(head(D.pay_t, '/account/payments'));
    if (!py) { Opes.fail(pays); return; }
    if (!py.length) { emptyIn(pays, T.no_payments); return; }
    var byProp = {}; pols.forEach(function (p) { byProp[p.proposal_id] = p; });
    (pr || []).forEach(function (p) { if (!byProp[p.id]) byProp[p.id] = { product_name: p.product_name }; });
    pays.appendChild(OP.table([
      [T.pays.date, function (x) { return Opes.date(x.created_at, true); }],
      [T.pays.for, function (x) { var p = byProp[x.proposal_id]; return p ? OP.title(p) : '—'; }],
      [T.pays.method, function (x) { return OP.provider(x.provider); }],
      [T.pays.amount, function (x) { return OP.mm(x.amount_minor); }],
      [T.pays.status, function (x) { return OP.chip(x.status); }]
    ], py.slice(0, 4), 'op-stack'));
  }).catch(function (e) { Opes.fail(recent, e); [pays, claimsB, dueB, renB].forEach(Opes.clear); Opes.clear(stats); });

  // Recent documents
  Opes.list('/mobile/documents', { per_page: 5 }).then(function (r) {
    var list = r.items || [];
    Opes.clear(docsB).appendChild(head(X.docs_t, '/account/documents'));
    if (!list.length) { emptyIn(docsB, X.no_docs); return; }
    docsB.appendChild(h('ul', { class: 'op-nlist' }, list.slice(0, 5).map(function (d) {
      return h('li', null, h('span', { class: 'op-li' }, Opes.icon('doc')), h('div', null, h('b', null, d.name || d.title || Opes.label(d.category)), h('small', { class: 'op-muted', style: 'display:block' }, Opes.date(d.issued_at || d.created_at))),
        h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { OP.openDocument(d.id); } }, T.view));
    })));
  }).catch(function (e) { Opes.fail(docsB, e); });

  Opes.api('/mobile/notifications').then(function (list) {
    list = list || [];
    Opes.clear(notes).appendChild(head(D.notif_t, '/account/messages'));
    if (!list.length) { emptyIn(notes, D.no_notif); return; }
    notes.appendChild(h('ul', { class: 'op-notes' }, list.slice(0, 4).map(function (n) {
      return h('li', { class: n.read ? '' : 'unread' }, h('b', null, n.title), h('span', null, n.body), h('small', { class: 'op-muted', style: 'display:block' }, Opes.date(n.created_at, true)));
    })));
  }).catch(function (e) { Opes.fail(notes, e); });
});
</script>
@endpush
