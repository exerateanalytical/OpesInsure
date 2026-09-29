{{-- /account/profile — GET /auth/mobile/session (user), PATCH /mobile/account/profile (name, email),
     GET/PATCH /mobile/account/customer-profile (date of birth, occupation, address). --}}
@extends('public.account.layout', ['title' => __('account_policies.prof.title'), 'lede' => __('account_policies.prof.lede'), 'crumbs' => [[__('account_policies.prof.title'), null]], 'active' => 'profile'])
@section('content')
@include('public.account.partials.policies-assets')
<div class="btnbar" style="margin-bottom:16px" data-launch-links><a class="dbtn dbtn-outline sm" href="/account/onboarding">@include('public.partials.i', ['n' => 'check']){{ __('launch_customer.onb.title') }}</a><a class="dbtn dbtn-outline sm" href="/account/activity">@include('public.partials.i', ['n' => 'clock']){{ __('launch_customer.activity.title') }}</a></div>
<div class="agrid c2" data-page-body>
  <section class="acard" data-user></section>
  <section class="acard" data-cust></section>
  <section class="acard" data-profile-links style="grid-column:1/-1"><div class="btnbar" style="justify-content:flex-start;margin:0">
    <a class="dbtn dbtn-outline sm" href="/account/kyc">{{ __('account.side.kyc') }}</a>
    <a class="dbtn dbtn-outline sm" href="/account/privacy">{{ __('account.side.privacy') }}</a>
    <a class="dbtn dbtn-outline sm" href="/account/requests">{{ __('account.side.requests') }}</a>
  </div></section>
  <section class="acard" data-agent-profile hidden style="grid-column:1/-1"></section>
  @include('public.account.partials.account-security')
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

  // S3 2026-09-29: agent profile (agents only; GET|PATCH /mobile/agent/profile, agent.clients.read). A new payout number
  // needs the PAYOUT_DESTINATION_CHANGE step-up code first (POST /mobile/security/step-up/request|verify), as in the app.
  var ap = $('[data-agent-profile]'), LP = @json(__('leftover_actions.agent_profile'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
  if (ap && Opes.can('agent.clients.read')) Opes.api('/mobile/agent/profile').then(function (p) {
    p = p || {}; ap.hidden = false;
    var save = h('button', { type: 'submit', class: 'dbtn dbtn-primary' }, Opes.icon('check'), T.save);
    var codeRow = h('label', { class: 'afield-s', hidden: true }, h('span', null, LP.code), h('input', { name: 'code', inputmode: 'numeric', maxlength: 6, autocomplete: 'one-time-code' }));
    var challenge = null, P = 'PAYOUT_DESTINATION_CHANGE';
    var form = h('form', { class: 'op-form', 'data-agent-profile-form': '', onsubmit: function (e) {
      e.preventDefault(); var d = vals(form), body = { full_name: d.full_name };
      if (d.national_id_number) body.national_id_number = d.national_id_number;
      var momoChanged = !!d.momo_phone_e164 && d.momo_phone_e164 !== (p.momo_phone_e164 || '');
      if (momoChanged) body.momo_phone_e164 = d.momo_phone_e164;
      Opes.alert(''); Opes.busy(save, true);
      if (momoChanged && !challenge) {
        return Opes.api('/mobile/security/step-up/request', { body: { purpose: P } }).then(function (c) { challenge = c.challenge_id; codeRow.hidden = false; Opes.alert(LP.code_sent, 'ok'); })
          .catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(save, false); });
      }
      var go = momoChanged
        ? Opes.api('/mobile/security/step-up/verify', { body: { challenge_id: challenge, purpose: P, code: d.code } }).then(function (g) { return Opes.api('/mobile/agent/profile', { method: 'PATCH', body: body, stepUp: true, headers: { 'X-Step-Up-Grant': g.grant_token } }); })
        : Opes.api('/mobile/agent/profile', { method: 'PATCH', body: body });
      go.then(function (np) { p = np || p; challenge = null; codeRow.hidden = true; form.elements.national_id_number.value = ''; form.elements.national_id_number.placeholder = p.national_id_number || ''; Opes.alert(LP.saved, 'ok'); })
        .catch(function (err) { challenge = null; codeRow.hidden = true; Opes.alert(err.message); }).finally(function () { Opes.busy(save, false); });
    } },
      field('full_name', R.name, p.full_name, { required: true, minlength: 3, maxlength: 120 }),
      field('national_id_number', LP.national_id, '', { maxlength: 40, placeholder: p.national_id_number || '', autocomplete: 'off' }),
      field('momo_phone_e164', LP.momo, p.momo_phone_e164, { maxlength: 32, inputmode: 'tel' }), codeRow,
      h('div', { class: 'btnbar full' }, save));
    Opes.clear(ap).append(h('h2', null, LP.title), h('p', { class: 'sub' }, LP.help, ' ', h('b', null, p.agent_code || '')), form);
  }).catch(function () { ap.hidden = true; });
});
</script>
@endpush
