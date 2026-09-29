{{-- /account/onboarding — CUST-009 Onboarding Overview: completion %, identity, contact, KYC, required documents, incomplete items, Continue.
     Computed by LC.onboarding() from GET /auth/mobile/session, /mobile/account/customer-profile and /mobile/kyc/profile (the app's endpoints). --}}
@extends('public.account.layout', ['title' => __('launch_customer.onb.title'), 'lede' => __('launch_customer.onb.lede'), 'crumbs' => [[__('account_policies.prof.title'), '/account/profile'], [__('launch_customer.onb.title'), null]], 'active' => 'profile'])
@section('content')
@include('public.account.partials.launch-assets')
<div class="agrid main-side op-ms380">
  <section class="acard" data-page-body data-onboarding></section>
  <section class="acard" data-todo></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, O = LC.T.onb, box = Opes.$('[data-page-body]'), todo = Opes.$('[data-todo]');
  Opes.loading(box); Opes.loading(todo);
  return LC.onboarding().then(function (o) {
    Opes.clear(box).append(h('h2', null, LC.fmt(O.progress, { pct: o.pct })), LC.progress(o.pct),
      h('ol', { class: 'op-nlist', style: 'margin-top:16px' }, o.steps.map(function (s) {
        var t = O.steps[s.key] || [s.key, ''];
        return h('li', { 'data-step': s.key }, h('span', { class: 'op-li' }, Opes.icon(s.done ? 'check' : 'clock')),
          h('div', null, h('b', null, t[0]), h('small', { class: 'op-muted', style: 'display:block' }, t[1])),
          h('span', { class: 'st ' + (s.done ? 'st-ok' : 'st-warn') }, s.done ? O.done : O.todo), s.done ? null : OP.btn(O.continue, s.href));
      })),
      h('div', { class: 'btnbar', style: 'margin-top:16px' }, o.next ? OP.btn(O.continue, o.next.href, 'dbtn-primary', 'chev') : h('p', { class: 'acct-alert', style: 'display:block' }, O.done_all)));
    var left = [];
    o.steps.forEach(function (s) { s.todo.forEach(function (t) { left.push(h('li', null, h('a', { href: s.href }, t))); }); });
    Opes.clear(todo).append(h('h2', null, O.incomplete_t), left.length ? h('ul', { style: 'display:grid;gap:6px;padding-left:18px' }, left) : h('p', { class: 'op-muted' }, O.none_left));
  }).catch(function (e) { Opes.fail(box, e); Opes.clear(todo); });
});
</script>
@endpush
