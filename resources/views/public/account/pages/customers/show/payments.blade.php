{{-- /account/customers/{id}/payments — AGT-038 Customer Payment History (agent): every payment attempt of one book client.
     GET /mobile/agent/clients/{id} + /mobile/partner/agent/clients/{id}/payments (book-scoped; another agent's client is a 404). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['client_payments_t'], 'lede' => $K['client_payments_lede'], 'crumbs' => [[__('account_agent.customers_t'), '/account/customers'], [$K['client_payments_t'], null]], 'active' => 'customers'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var id = ctx.ids[0], box = O.$('[data-page-body]');
  O.loading(box);
  return Promise.all([A.client(id), S.clientPayments(id)]).then(function (r) {
    var c = r[0], rows = r[1], paid = rows.filter(function (p) { return ['SUCCEEDED'].indexOf(p.status) >= 0; });
    var total = paid.reduce(function (s, p) { return s + (p.amount_minor || 0); }, 0), list = h('div'), stats = h('div', { class: 'desk-stats' });
    O.clear(box).append(
      h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('span', { class: 'av' }, O.icon('card')), h('div', null, h('h2', null, c.full_name), h('small', null, c.phone_e164 || ''))),
        h('div', { class: 'btns' }, S.btn(S.t('back_client'), S.clientUrl(c.id)))),
      stats, list);
    A.stats(stats, [A.stat('b', 'card', S.t('stat_attempts'), rows.length), A.stat('g', 'check', S.t('stat_paid'), paid.length, S.money(total))]);
    if (!rows.length) return O.empty(list, S.t('no_payments'));
    list.appendChild(S.table(['th_proposal', 'th_amount', 'th_provider', 'th_status', 'th_created'], rows.map(function (p) {
      return S.row([p.proposal_number || '—', h('span', { class: 'amt' }, S.money(p.amount_minor)), S.label(p.provider), S.chip(p.status), O.date(p.created_at, true)]);
    })));
  }).catch(function (e) { A.fail(box, e); });
});
</script>
@endpush
