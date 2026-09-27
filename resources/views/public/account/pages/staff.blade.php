{{-- /account/staff — brokerage staff and invitations (UI audit 2026-09-27).
     GET /mobile/partner/broker/staff (members, pending invitations, can_invite) and
     POST /mobile/partner/broker/staff/invitations (InvitationService::issue, BROKER_ADMIN only — the API decides). --}}
@php $K = __('account_agent'); @endphp
@extends('public.account.layout', ['title' => $K['staff_t'], 'lede' => $K['staff_lede'], 'crumbs' => [[$K['staff_t'], null]], 'active' => 'staff'])
@section('content')
@include('public.account.agent.assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body><h2>{{ $K['js']['staff_members'] }}</h2><div data-members></div></section>
  <section class="acard"><h2>{{ $K['js']['staff_pending'] }}</h2><div data-pending></div></section>
  <section class="acard" data-invite-card hidden>
    <h2>{{ $K['js']['invite_t'] }}</h2>
    <form class="bform" data-invite-form novalidate>
      <label class="afield-s"><span>{{ $K['js']['invite_phone'] }}</span><input name="phone" type="tel" inputmode="tel" placeholder="+2376XXXXXXXX" maxlength="20"></label>
      <label class="afield-s"><span>{{ $K['js']['invite_email'] }}</span><input name="email" type="email" maxlength="190"></label>
      <div class="bactions"><button type="submit" class="dbtn dbtn-primary sm" data-invite-submit>{{ $K['js']['invite_submit'] }}</button></div>
    </form>
  </section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, O = Opes, h = O.h;
  if (!A.guard(ctx)) return;
  var mbox = O.$('[data-members]'), pbox = O.$('[data-pending]');
  if (A.mode() !== 'broker') { O.empty(mbox, A.t('staff_broker_only')); O.clear(pbox); return; }

  function load() {
    O.loading(mbox); O.loading(pbox);
    return O.api('/mobile/partner/broker/staff').then(function (d) {
      var members = d.members || [], pending = d.pending_invitations || [];
      if (!members.length) O.empty(mbox, A.t('no_data'));
      else O.clear(mbox).appendChild(A.table(['th_member', 'th_phone', 'th_role', 'th_status', 'th_since'], members.map(function (m) {
        return h('tr', null, h('td', null, h('b', null, m.full_name), m.is_me ? ' (' + A.t('me') + ')' : ''), h('td', null, m.phone_e164 || '—'),
          h('td', null, A.label(m.role_code)), h('td', null, O.chip(m.status, A.label(m.status))), h('td', null, O.date(m.since)));
      })));
      if (!pending.length) O.empty(pbox, A.t('no_pending'));
      else O.clear(pbox).appendChild(A.table(['th_recipient', 'th_role', 'th_expires'], pending.map(function (i) {
        return h('tr', null, h('td', null, i.recipient), h('td', null, A.label(i.role_code)), h('td', null, O.date(i.expires_at)));
      })));
      O.$('[data-invite-card]').hidden = !d.can_invite;
    }).catch(function (e) { A.fail(mbox, e); O.clear(pbox); });
  }

  var form = O.$('[data-invite-form]');
  form.addEventListener('submit', function (e) {
    e.preventDefault(); O.alert('');
    var phone = form.elements.phone.value.trim(), email = form.elements.email.value.trim();
    if (!phone && !email) return O.alert(A.t('invite_missing'));
    var btn = O.$('[data-invite-submit]'); O.busy(btn, true);
    O.api('/mobile/partner/broker/staff/invitations', { body: phone ? { recipient_phone_e164: phone } : { recipient_email: email } }).then(function (i) {
      O.busy(btn, false); form.reset();
      O.alert(A.t('invite_done', { r: i.recipient, c: i.invite_code || '' }), 'ok');
      load();
    }).catch(function (err) { O.busy(btn, false); O.alert(A.errMsg(err)); });
  });
  return load();
});
</script>
@endpush
