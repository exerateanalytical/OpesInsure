{{-- /account/payments — payments list with receipts (GET /mobile/payments, /mobile/payments/{id}/receipt, retry). --}}
@extends('public.account.layout', ['title' => __('account_policies.pays.title'), 'lede' => __('account_policies.pays.lede'), 'crumbs' => [[__('account_policies.pays.title'), null]], 'active' => 'payments'])
@section('content')
@include('public.account.partials.policies-assets')
<div class="stats" data-stats></div>
<section class="acard" data-page-body></section>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, T = OP.T, Y = T.pays, $ = Opes.$, box = $('[data-page-body]'), stats = $('[data-stats]');
  Opes.loading(box);
  return Promise.all([OP.payments(), OP.policies().catch(function () { return []; }), OP.proposals().catch(function () { return []; })]).then(function (r) {
    var list = r[0], byProp = {};
    r[1].forEach(function (p) { byProp[p.proposal_id] = { name: OP.title(p), href: '/account/policies/' + p.id, sub: p.policy_number }; });
    r[2].forEach(function (p) { if (!byProp[p.id]) byProp[p.id] = { name: p.product_name, sub: p.carrier_name, status: p.status }; });
    var okList = list.filter(OP.ok);
    var failed = list.filter(function (x) { return /FAIL|CANCEL|EXPIRE/.test(String(x.status).toUpperCase()); });
    var pending = list.length - okList.length - failed.length;
    Opes.clear(stats).append(
      OP.stat('card', 'blue', Y.s_total, Opes.money(okList.reduce(function (s, x) { return s + (x.amount_minor || 0); }, 0), { minor: true }), ''),
      OP.stat('check', 'green', Y.s_count, okList.length, ''), OP.stat('clock', 'orange', Y.s_pending, pending, ''), OP.stat('x', 'red', Y.s_failed, failed.length, ''));
    var head = h('div', { class: 'acard-h' }, h('h2', null, T.show.pay_t), OP.btn(T.pay.pay_now, '/account/payments/new', 'dbtn-primary sm', 'card'));
    if (!list.length) { Opes.clear(box).appendChild(head); var e = h('div'); box.appendChild(e); return Opes.empty(e, T.no_payments); }
    Opes.clear(box).append(head, OP.table([
      [Y.date, function (x) { return Opes.date(x.created_at, true); }],
      [Y.for, function (x) { var p = byProp[x.proposal_id]; if (!p) return '—'; var n = h('div', null, p.href ? h('a', { class: 'rowlink', href: p.href }, p.name) : h('b', null, p.name)); if (p.sub) n.appendChild(h('small', { class: 'op-muted', style: 'display:block' }, p.sub)); return n; }],
      [Y.method, function (x) { return OP.provider(x.provider); }],
      [Y.ref, function (x) { return x.provider_reference; }],
      [Y.amount, function (x) { return h('b', null, OP.mm(x.amount_minor)); }],
      [Y.status, function (x) { return OP.chip(x.status); }],
      [T.receipt, function (x) {
        if (OP.ok(x)) return h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { OP.openReceipt(x.id); } }, Opes.icon('download'), T.receipt);
        var p = byProp[x.proposal_id];
        if (p && String(p.status).toUpperCase() === 'PAYMENT_PENDING') return OP.btn(T.pay.retry, '/account/payments/new?proposal=' + x.proposal_id, 'dbtn-outline sm', 'refresh');
        return h('small', { class: 'op-muted' }, '—');
      }]
    ], list, 'op-stack'));
  }).catch(function (e) { Opes.fail(box, e); });
});
</script>
@endpush
