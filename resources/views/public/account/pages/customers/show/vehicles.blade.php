{{-- /account/customers/{id}/vehicles — AGT-047 Vehicle Profile (list) + AGT-048 Vehicle Registration (agent): the book client's
     vehicles, and registering a new one for them. GET / POST /mobile/partner/agent/clients/{id}/vehicles (book-scoped;
     RiskAssetService does the customer-of-tenant and duplicate-plate checks). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['vehicles_t'], 'lede' => $K['vehicles_lede'], 'crumbs' => [[__('account_agent.customers_t'), '/account/customers'], [$K['vehicles_t'], null]], 'active' => 'customers'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
  <section class="acard" data-register hidden><h2>{{ $K['js']['register_vehicle'] }}</h2><div data-form></div></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var id = ctx.ids[0], box = O.$('[data-page-body]'), reg = O.$('[data-register]'), list = h('div');
  O.loading(box);
  function draw(rows) {
    if (!rows.length) return O.empty(list, S.t('no_vehicles'));
    O.clear(list).appendChild(S.table(['th_vehicle', 'th_plate', 'th_year', 'th_usage', 'th_status', 'th_actions'], rows.map(function (v) {
      var f = v.facts || {};
      return S.row([h('b', null, v.display_name || '—'), f.registration_number || v.external_reference || '—', f.year || '—', S.label(f.usage_type), S.chip(v.status),
        S.btn(S.t('view'), '/account/book/vehicles/' + S.enc(v.id))]);
    })));
  }
  function form(c, rows) {
    var inp = function (name, attrs) { return h('input', Object.assign({ name: name }, attrs || {})); };
    var make = inp('make', { required: true, maxlength: 60 }), model = inp('model', { required: true, maxlength: 60 }), plate = inp('registration_number', { required: true, maxlength: 20, autocomplete: 'off', placeholder: 'LT 452 CD' });
    var year = inp('year', { type: 'number', min: 1950, max: new Date().getFullYear() + 1 }), power = inp('fiscal_power', { type: 'number', min: 1, max: 60 });
    var usage = h('select', { name: 'usage_type' }, ['PRIVATE', 'COMMERCIAL', 'TAXI'].map(function (u) { return h('option', { value: u }, S.label(u)); }));
    var btn = h('button', { type: 'submit', class: 'dbtn dbtn-primary sm' }, O.icon('check'), S.t('register_vehicle')), idem = O.uuid();
    var f = h('form', { class: 'bform', novalidate: true }, S.field(S.t('f_make'), make, true), S.field(S.t('f_model'), model, true), S.field(S.t('f_plate'), plate, true),
      S.field(S.t('f_year'), year), S.field(S.t('f_usage'), usage), S.field(S.t('f_power'), power), h('div', { class: 'bactions' }, btn));
    f.addEventListener('submit', function (e) {
      e.preventDefault(); O.alert('');
      var p = plate.value.trim().toUpperCase();
      if (!make.value.trim() || !model.value.trim() || !p) return O.alert(S.t('vehicle_missing'), 'bad');
      var facts = { make: make.value.trim(), model: model.value.trim(), registration_number: p, usage_type: usage.value };
      if (year.value) facts.year = parseInt(year.value, 10);
      if (power.value) facts.fiscal_power = parseInt(power.value, 10);
      O.busy(btn, true);
      O.api('/mobile/partner/agent/clients/' + S.enc(c.id) + '/vehicles', { body: { display_name: facts.make + ' ' + facts.model + ' · ' + p, external_reference: p, facts: facts }, idemKey: idem }).then(function (v) {
        O.busy(btn, false); f.reset(); idem = O.uuid(); rows.unshift(v); draw(rows); O.alert(S.t('vehicle_done', { p: p }), 'ok');
      }).catch(function (err) { O.busy(btn, false); O.alert(A.errMsg(err), 'bad'); });
    });
    O.clear(O.$('[data-form]')).appendChild(f);
    if (location.hash === '#register' || ctx.params.get('register')) reg.scrollIntoView();
  }
  return Promise.all([A.client(id), S.vehicles(id)]).then(function (r) {
    var c = r[0], rows = r[1];
    O.clear(box).append(
      h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('span', { class: 'av' }, O.icon('motor')), h('div', null, h('h2', null, c.full_name), h('small', null, c.phone_e164 || ''))),
        h('div', { class: 'btns' }, S.btn(S.t('back_client'), S.clientUrl(c.id)), A.canQuote() ? S.btn(S.t('quote_motor'), '/account/buy?line=motor&customer=' + S.enc(c.id), 'dbtn-primary', 'compare') : null)),
      h('hr', { class: 'ag-hr' }), list);
    draw(rows);
    if (S.canManage()) { reg.hidden = false; form(c, rows); }
  }).catch(function (e) { A.fail(box, e); });
});
</script>
@endpush
