{{-- /account/book/vehicles/{id} — AGT-047 Vehicle Profile (agent): one vehicle of a book client with its registered facts and the
     client's motor policies. GET /mobile/partner/agent/vehicles/{id} (book-scoped) + /mobile/partner/agent/policies. --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['vehicle_t'], 'lede' => $K['vehicle_lede'], 'crumbs' => [[__('account_agent.customers_t'), '/account/customers'], [$K['vehicle_t'], null]], 'active' => 'customers'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
  <section class="acard"><h2>{{ $K['js']['sec_motor_policies'] }}</h2><div data-policies></div></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var box = O.$('[data-page-body]'), pbox = O.$('[data-policies]');
  O.loading(box); O.loading(pbox);
  return S.vehicle(ctx.ids[0]).then(function (v) {
    var f = v.facts || {}, back = v.customer_id ? S.clientUrl(v.customer_id) + '/vehicles' : '/account/customers';
    O.clear(box).append(
      h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('span', { class: 'av' }, O.icon('motor')), h('div', null, h('h2', null, v.display_name || '—'), h('small', null, f.registration_number || v.external_reference || ''))),
        h('div', { class: 'btns' }, S.chip(v.status), S.btn(S.t('back_vehicles'), back))),
      h('hr', { class: 'ag-hr' }),
      h('div', { class: 'ag-fields' }, A.field(S.t('f_make'), f.make), A.field(S.t('f_model'), f.model), A.field(S.t('f_plate'), f.registration_number || v.external_reference),
        A.field(S.t('f_year'), f.year), A.field(S.t('f_usage'), S.label(f.usage_type)), A.field(S.t('f_power'), f.fiscal_power), A.field(S.t('f_created'), O.date(v.created_at)), A.field(S.t('f_version'), v.version)));
    return A.policies().then(function (rows) {
      rows = rows.filter(function (p) { return p.party_id === v.party_id && p.line_code === 'MOTOR'; });
      if (!rows.length) return O.empty(pbox, S.t('no_motor_policies'));
      O.clear(pbox).appendChild(S.table(['th_policy', 'th_insurer', 'th_status', 'th_expires'], rows.map(function (p) {
        return S.row([h('a', { href: S.policyUrl(p.id) }, h('b', null, p.policy_number || '—')), (p.carrier_short_name || p.carrier_name) || '—', S.chip(p.status), O.date(p.coverage_ends_at)]);
      })));
    }).catch(function (e) { A.fail(pbox, e); });
  }).catch(function (e) { A.fail(box, e); O.clear(pbox); });
});
</script>
@endpush
