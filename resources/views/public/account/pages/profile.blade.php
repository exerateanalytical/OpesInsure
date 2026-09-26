{{-- /account/profile — GET /auth/mobile/session (user), PATCH /mobile/account/profile (name, email),
     GET/PATCH /mobile/account/customer-profile (date of birth, occupation, address). --}}
@extends('public.account.layout', ['title' => __('account_policies.prof.title'), 'lede' => __('account_policies.prof.lede'), 'crumbs' => [[__('account_policies.prof.title'), null]], 'active' => 'profile'])
@section('content')
@include('public.account.partials.policies-assets')
<div class="agrid c2" data-page-body>
  <section class="acard" data-user></section>
  <section class="acard" data-cust></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, T = OP.T, R = T.prof, $ = Opes.$, ub = $('[data-user]'), cb = $('[data-cust]');
  function field(name, label, value, attrs) { return h('label', { class: 'afield-s' }, h('span', null, label), h('input', Object.assign({ name: name, value: value || '' }, attrs || {}))); }
  function vals(form) { var d = {}; new FormData(form).forEach(function (v, k) { d[k] = String(v).trim(); }); return d; }
  function ver(ok) { return h('span', { class: 'op-ver ' + (ok ? 'ok' : 'no') }, ok ? R.verified : R.unverified); }
  Opes.loading(ub); Opes.loading(cb);

  Opes.api('/auth/mobile/session').then(function (s) {
    var u = (s && s.user) || {};
    var save = h('button', { type: 'submit', class: 'dbtn dbtn-primary' }, Opes.icon('check'), T.save);
    var form = h('form', { style: 'display:grid;gap:12px', onsubmit: function (e) {
      e.preventDefault(); var d = vals(form); Opes.busy(save, true);
      Opes.api('/mobile/account/profile', { method: 'PATCH', body: { full_name: d.full_name, email: d.email || null } }).then(function (nu) {
        var sess = Opes.session(); if (sess) { sess.name = (nu && nu.full_name) || d.full_name; sess.loaded_at = 0; Opes.saveSession(sess); }
        Opes.$$('[data-user-name]').forEach(function (e2) { e2.textContent = d.full_name; });
        Opes.alert(R.saved, 'ok');
      }).catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(save, false); });
    } },
      field('full_name', R.name, u.full_name, { required: true, minlength: 3, maxlength: 120, autocomplete: 'name' }),
      h('label', { class: 'afield-s' }, h('span', null, R.email, ' ', u.email ? ver(u.email_verified) : null), h('input', { name: 'email', type: 'email', value: u.email || '', maxlength: 190, autocomplete: 'email' })),
      h('div', { class: 'afield-s' }, h('span', null, R.phone, ' ', ver(u.phone_verified)), h('input', { value: u.phone_e164 || '', readonly: true, disabled: true, 'aria-label': R.phone }), h('small', { class: 'op-muted' }, R.phone_note)),
      h('div', { class: 'btnbar' }, save));
    Opes.clear(ub).append(h('div', { class: 'op-vhead' }, h('span', { class: 'op-mark' }, (u.full_name || '?').split(/\s+/).map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase()), h('div', null, h('h2', { style: 'margin:0;font-size:19px;color:#0A1E4D' }, u.full_name || '—'), h('small', { class: 'op-muted' }, u.phone_e164 || ''))),
      h('h2', null, R.personal), form);
  }).catch(function (e) { Opes.fail(ub, e); });

  Opes.api('/mobile/account/customer-profile').then(function (c) {
    c = c || {};
    var save = h('button', { type: 'submit', class: 'dbtn dbtn-primary' }, Opes.icon('check'), T.save);
    var form = h('form', { class: 'op-form', onsubmit: function (e) {
      e.preventDefault(); var d = vals(form); Opes.busy(save, true);
      var body = {}; ['date_of_birth', 'occupation', 'address_line1', 'city', 'region'].forEach(function (k) { body[k] = d[k] || null; });
      Opes.api('/mobile/account/customer-profile', { method: 'PATCH', body: body }).then(function () { Opes.alert(R.saved, 'ok'); })
        .catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(save, false); });
    } },
      field('date_of_birth', R.dob, c.date_of_birth ? String(c.date_of_birth).slice(0, 10) : '', { type: 'date', max: new Date().toISOString().slice(0, 10), autocomplete: 'bday' }),
      field('occupation', R.occupation, c.occupation, { maxlength: 120 }),
      h('div', { class: 'full' }, field('address_line1', R.address, c.address_line1, { maxlength: 255, autocomplete: 'street-address' })),
      field('city', R.city, c.city, { maxlength: 120, autocomplete: 'address-level2' }), field('region', R.region, c.region, { maxlength: 120, autocomplete: 'address-level1' }),
      h('div', { class: 'btnbar full' }, save));
    Opes.clear(cb).append(h('h2', null, R.details), form);
  }).catch(function (e) { Opes.fail(cb, e); });
});
</script>
@endpush
