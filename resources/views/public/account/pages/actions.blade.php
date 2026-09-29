{{-- /account/actions — CUST-019 Action Centre: priority, entity, action, due date, reason, direct CTA.
     Built by LC.actions() from the app's endpoints (KYC, proposals + payments, quotes, claims, wallet renewals, support, complaints, notifications). --}}
@extends('public.account.layout', ['title' => __('launch_customer.actions.title'), 'lede' => __('launch_customer.actions.lede'), 'crumbs' => [[__('account_policies.dash.title'), '/account'], [__('launch_customer.actions.title'), null]], 'active' => 'dashboard'])
@section('content')
@include('public.account.partials.launch-assets')
<section class="acard" data-page-body data-actions></section>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, A = LC.T.act, box = Opes.$('[data-page-body]');
  Opes.loading(box);
  return LC.actions().then(function (list) {
    Opes.clear(box);
    if (!list.length) { Opes.empty(box, A.none); return; }
    box.appendChild(OP.table([
      [A.priority, function (a) { return LC.prioChip(a.priority); }],
      [A.item, function (a) { return h('div', { class: 'op-cell' }, h('span', { class: 'op-li' }, Opes.icon(a.icon)), h('b', null, a.title)); }],
      [A.reason, function (a) { return a.reason; }],
      [A.due, function (a) { return a.due ? Opes.date(a.due) : '—'; }],
      [A.action, function (a) { return h('a', { class: 'dbtn dbtn-primary sm', href: a.href }, a.cta); }]
    ], list, 'op-stack'));
  }).catch(function (e) { Opes.fail(box, e); });
});
</script>
@endpush
