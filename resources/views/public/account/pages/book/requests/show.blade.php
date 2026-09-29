{{-- /account/book/requests/{id} — AGT-043 Endorsement Tracking (one request): status, reason, amounts and the status timeline.
     GET /mobile/partner/agent/service-requests/{id} (book-scoped). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['request_t'], 'lede' => $K['request_lede'], 'crumbs' => [[$K['requests_t'], '/account/book/requests'], [$K['request_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
  <section class="acard"><h2>{{ $K['js']['sec_timeline'] }}</h2><div data-timeline></div></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var box = O.$('[data-page-body]'), tl = O.$('[data-timeline]');
  O.loading(box); O.loading(tl);
  return S.request(ctx.ids[0]).then(function (t) {
    O.clear(box).append(
      h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('span', { class: 'av' }, O.icon('edit')), h('div', null, h('h2', null, t.transaction_number || '—'), h('small', null, S.label(t.type)))),
        h('div', { class: 'btns' }, S.chip(t.status), S.btn(S.t('back_requests'), '/account/book/requests'))),
      h('hr', { class: 'ag-hr' }),
      h('div', { class: 'ag-fields' },
        A.field(S.t('f_policy'), h('a', { href: S.policyUrl(t.policy_id) }, t.policy_number || '—')), A.field(S.t('f_client'), S.clientLink(t.customer_id, t.customer_name)),
        A.field(S.t('f_channel'), S.label(t.channel)), A.field(S.t('f_effective'), O.date(t.effective_at)),
        A.field(S.t('f_premium_delta'), t.premium_delta_minor ? S.money(t.premium_delta_minor) : '—'), A.field(S.t('f_refund'), t.refund_minor ? S.money(t.refund_minor) : '—'),
        A.field(S.t('f_created'), O.date(t.created_at, true)), A.field(S.t('f_updated'), O.date(t.updated_at, true))),
      h('p', null, h('b', null, S.t('f_reason') + ': '), t.reason || '—'));
    var ev = t.timeline || [];
    if (!ev.length) return O.empty(tl, S.t('no_events'));
    O.clear(tl).appendChild(S.table(['th_when', 'th_status', 'th_event', 'th_message'], ev.map(function (e) {
      return S.row([O.date(e.occurred_at, true), S.chip(e.to_status), S.label(e.reason_code), e.message || '—']);
    })));
  }).catch(function (e) { A.fail(box, e); O.clear(tl); });
});
</script>
@endpush
