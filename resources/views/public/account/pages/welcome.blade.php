{{-- /account/welcome — CUST-008 Account Created: shown right after sign-up (public/landing/auth.js), CTA "Complete my profile" → /account/onboarding.
     Reads GET /auth/mobile/session for the name only. --}}
@extends('public.account.layout', ['title' => __('launch_customer.welcome.title'), 'lede' => __('launch_customer.welcome.lede'), 'crumbs' => [[__('launch_customer.welcome.title'), null]], 'active' => 'dashboard'])
@section('content')
@include('public.account.partials.launch-assets')
<section class="acard" data-page-body data-welcome>
  <div class="acct-loading" role="status"><span class="spin"></span>{{ __('account.js.loading') }}</div>
</section>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, W = LC.T.welcome, box = Opes.$('[data-page-body]');
  var name = (ctx.session && ctx.session.name) || '';
  Opes.clear(box).append(
    h('div', { style: 'display:flex;gap:16px;align-items:center' }, h('span', { class: 'op-sq green' }, Opes.icon('check')), h('div', null, h('h2', { style: 'margin:0' }, LC.fmt(W.hello, { name: name })), h('p', { class: 'op-muted', style: 'margin:4px 0 0' }, W.done))),
    h('h3', { style: 'font-size:16px;margin:20px 0 8px' }, W.next_t),
    h('ol', { style: 'display:grid;gap:6px;padding-left:20px' }, h('li', null, W.n1), h('li', null, W.n2), h('li', null, W.n3)),
    h('div', { class: 'btnbar', style: 'margin-top:16px' },
      OP.btn(W.cta, '/account/onboarding', 'dbtn-primary', 'user'), OP.btn(W.later, '/account', 'dbtn-outline', 'home'), OP.btn(W.browse, '/account/needs', 'dbtn-outline', 'compare')));
});
</script>
@endpush
