{{-- /account — customer dashboard: summary cards, recent policies, quick actions, recent payments, notifications. --}}
@extends('public.account.layout', ['title' => __('account_policies.dash.title'), 'lede' => __('account_policies.dash.lede'), 'crumbs' => [[__('account_policies.dash.title'), null]], 'active' => 'dashboard'])
@section('content')
@include('public.account.partials.policies-assets')
<div class="stats" data-stats></div>
<div class="op-dash" data-page-body>
  <div class="col">
    <section class="acard" data-recent></section>
    <section class="acard" data-pays></section>
  </div>
  <div class="col">
    <section class="acard" data-quick></section>
    <section class="acard" data-notes></section>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, T = OP.T, D = T.dash, $ = Opes.$;
  var stats = $('[data-stats]'), recent = $('[data-recent]'), pays = $('[data-pays]'), quick = $('[data-quick]'), notes = $('[data-notes]');

  Opes.clear(quick).append(h('h2', null, D.quick), h('div', { class: 'op-qa' },
    OP.btn(D.q_quote, '/account/buy', 'dbtn-primary', 'compare'), OP.btn(D.q_claim, '/account/claims/new', 'dbtn-outline', 'shield'),
    OP.btn(D.q_pay, '/account/payments/new', 'dbtn-navy', 'card'), OP.btn(D.q_docs, '/account/documents', 'dbtn-outline', 'doc'),
    OP.btn(D.q_veh, '/account/vehicles', 'dbtn-outline', 'motor'), OP.btn(D.q_help, '/account/support', 'dbtn-outline', 'headset')));

  Opes.loading(recent); Opes.loading(pays); Opes.loading(notes);
  var soft = function (p) { return p.catch(function () { return null; }); };
  Promise.all([OP.policies(), soft(OP.claims()), soft(OP.proposals()), soft(OP.payments())]).then(function (r) {
    var pols = r[0], cl = r[1], pr = r[2], py = r[3];
    var active = pols.filter(function (p) { return String(p.status).toUpperCase() === 'ACTIVE'; });
    var exp = pols.filter(function (p) { return OP.state(p) === 'EXPIRING'; });
    var due = pr ? pr.filter(function (p) { var paid = (py || []).filter(function (x) { return x.proposal_id === p.id && OP.ok(x); }).reduce(function (s, x) { return s + x.amount_minor; }, 0); return String(p.status).toUpperCase() === 'PAYMENT_PENDING' && paid < (p.total_minor || 0); }) : null;
    Opes.clear(stats).append(
      OP.stat('shield', 'blue', D.s_active, active.length, D.s_active_d),
      OP.stat('doc', 'navy', D.s_claims, cl ? cl.filter(OP.claimOpen).length : '—', D.s_claims_d),
      OP.stat('card', 'orange', D.s_due, due ? due.length : '—', D.s_due_d),
      OP.stat('clock', exp.length ? 'red' : 'green', D.s_exp, exp.length, D.s_exp_d));

    Opes.clear(recent).append(h('div', { class: 'acard-h' }, h('h2', null, D.recent), h('a', { class: 'rowlink', href: '/account/policies' }, T.view_all)), h('p', { class: 'sub' }, D.recent_d));
    if (!pols.length) { var e = h('div'); recent.appendChild(e); Opes.empty(e, T.no_policies, OP.btn(T.get_quote, '/account/buy', 'dbtn-primary sm')); }
    else recent.appendChild(OP.table([
      [T.pol.policy, function (p) { return h('div', { class: 'op-cell' }, h('span', { class: 'op-li' }, Opes.icon(OP.lineIcon(OP.lineOf(p)))), h('div', null, h('b', null, OP.title(p)), h('small', null, p.carrier_name || ''))); }],
      [T.pol.number, function (p) { return p.policy_number; }],
      [T.pol.period, function (p) { return h('div', null, Opes.date(p.coverage_starts_at) + ' – ' + Opes.date(p.coverage_ends_at), h('small', { class: 'op-muted', style: 'display:block' }, OP.daysNote(p))); }],
      [T.pol.status, function (p) { return OP.chip(OP.state(p)); }],
      ['', function (p) { return OP.btn(T.view, '/account/policies/' + p.id); }]
    ], pols.slice(0, 5), 'op-stack'));

    Opes.clear(pays).append(h('div', { class: 'acard-h' }, h('h2', null, D.pay_t), h('a', { class: 'rowlink', href: '/account/payments' }, T.view_all)));
    if (!py) { var f = h('div'); pays.appendChild(f); Opes.fail(f); return; }
    if (!py.length) { var g = h('div'); pays.appendChild(g); Opes.empty(g, T.no_payments); return; }
    var byProp = {}; pols.forEach(function (p) { byProp[p.proposal_id] = p; });
    (pr || []).forEach(function (p) { if (!byProp[p.id]) byProp[p.id] = { product_name: p.product_name }; });
    pays.appendChild(OP.table([
      [T.pays.date, function (x) { return Opes.date(x.created_at, true); }],
      [T.pays.for, function (x) { var p = byProp[x.proposal_id]; return p ? OP.title(p) : '—'; }],
      [T.pays.method, function (x) { return OP.provider(x.provider); }],
      [T.pays.amount, function (x) { return OP.mm(x.amount_minor); }],
      [T.pays.status, function (x) { return OP.chip(x.status); }]
    ], py.slice(0, 4), 'op-stack'));
  }).catch(function (e) { Opes.fail(recent, e); Opes.clear(pays); Opes.clear(stats); });

  Opes.api('/mobile/notifications').then(function (list) {
    list = list || [];
    Opes.clear(notes).append(h('div', { class: 'acard-h' }, h('h2', null, D.notif_t), h('a', { class: 'rowlink', href: '/account/notifications' }, T.view_all)));
    if (!list.length) { var e = h('div'); notes.appendChild(e); Opes.empty(e, D.no_notif); return; }
    notes.appendChild(h('ul', { class: 'op-notes' }, list.slice(0, 4).map(function (n) {
      return h('li', { class: n.read ? '' : 'unread' }, h('b', null, n.title), h('span', null, n.body), h('small', { class: 'op-muted', style: 'display:block' }, Opes.date(n.created_at, true)));
    })));
  }).catch(function (e) { Opes.fail(notes, e); });
});
</script>
@endpush
