{{-- Sign-in and security card for /account/profile (UI coverage batch 13). Same endpoints as the mobile app:
     POST /me/email/verification, POST /me/phone/verification, POST /me/phone/verification/confirm {challenge_id, code},
     POST /me/mfa/totp, POST /me/mfa/totp/{method}/confirm {code}, PUT /me/password {current_password, password, password_confirmation},
     POST /invitations/accept {token}. Every rule (throttles, demo lock, session revocation) stays in the API. --}}
<section class="acard" data-account-security style="grid-column:1/-1"></section>
@push('scripts')
<script>
window.OPES_SEC = @json(__('support_actions.portal'));
(function () {
  var ctx = { session: Opes.session() };
  var h = Opes.h, S = window.OPES_SEC, box = Opes.$('[data-account-security]');
  if (!box) return;
  var u = (ctx && ctx.session && ctx.session.user) || {};
  function row() { return h('div', { class: 'btnbar', style: 'justify-content:flex-start;margin:0;flex-wrap:wrap;gap:8px' }); }
  function input(attrs) { return h('input', attrs); }

  // Email confirmation link.
  var emailBtn = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-email-verify': '', onclick: function () {
    Opes.busy(emailBtn, true);
    Opes.api('/me/email/verification', { method: 'POST' }).then(function (r) {
      var why = r && r.reason;
      Opes.alert(r && r.sent ? S.email_sent : why === 'already_verified' ? S.email_already : why === 'no_email' ? S.email_none : S.email_off, r && r.sent ? 'ok' : undefined);
    }).catch(function (e) { Opes.alert(e.message); }).finally(function () { Opes.busy(emailBtn, false); });
  } }, Opes.icon('mail'), S.email_verify);

  // Phone verification: request a challenge, then confirm it with the 6-digit code.
  var challenge = null, phoneCode = input({ inputmode: 'numeric', maxlength: 6, minlength: 6, 'aria-label': S.phone_code, placeholder: S.phone_code, style: 'max-width:140px' });
  var phoneConfirm = h('button', { type: 'button', class: 'dbtn dbtn-primary sm', disabled: true, onclick: function () {
    var code = phoneCode.value.trim(); if (!challenge || code.length !== 6) return; Opes.busy(phoneConfirm, true);
    Opes.api('/me/phone/verification/confirm', { body: { challenge_id: challenge, code: code } }).then(function () { Opes.alert(S.phone_done, 'ok'); challenge = null; phoneConfirm.disabled = true; })
      .catch(function (e) { Opes.alert(e.message); }).finally(function () { Opes.busy(phoneConfirm, false); });
  } }, S.phone_confirm);
  var phoneBtn = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-phone-verify': '', onclick: function () {
    Opes.busy(phoneBtn, true);
    Opes.api('/me/phone/verification', { body: { channel: 'sms' } }).then(function (r) {
      if (r && r.reason === 'already_verified') { Opes.alert(S.phone_already); return; }
      challenge = r && (r.challenge_id || r.id); phoneConfirm.disabled = !challenge; Opes.alert(S.phone_sent, 'ok'); phoneCode.focus();
    }).catch(function (e) { Opes.alert(e.message); }).finally(function () { Opes.busy(phoneBtn, false); });
  } }, Opes.icon('phone'), S.phone_verify);

  // Authenticator app (TOTP): begin → show the key → confirm with a code → show recovery codes once.
  var mfaBox = h('div', { style: 'display:grid;gap:8px' });
  var mfaBtn = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-mfa-start': '', onclick: function () {
    Opes.busy(mfaBtn, true);
    Opes.api('/me/mfa/totp', { method: 'POST' }).then(function (m) {
      var code = input({ inputmode: 'numeric', maxlength: 6, 'aria-label': S.mfa_code, placeholder: S.mfa_code, style: 'max-width:140px' });
      var ok = h('button', { type: 'button', class: 'dbtn dbtn-primary sm', onclick: function () {
        Opes.busy(ok, true);
        Opes.api('/me/mfa/totp/' + encodeURIComponent(m.method_id) + '/confirm', { body: { code: code.value.trim() } }).then(function (r) {
          Opes.clear(mfaBox).append(h('p', null, S.mfa_done), h('pre', { style: 'white-space:pre-wrap' }, ((r && r.recovery_codes) || []).join('\n')));
        }).catch(function (e) { Opes.alert(e.message); }).finally(function () { Opes.busy(ok, false); });
      } }, S.mfa_confirm);
      var r2 = row(); r2.append(code, ok);
      Opes.clear(mfaBox).append(h('p', null, S.mfa_secret), h('code', { style: 'word-break:break-all' }, m.secret), r2);
    }).catch(function (e) { Opes.alert(e.message); }).finally(function () { Opes.busy(mfaBtn, false); });
  } }, Opes.icon('shield'), S.mfa_start);
  mfaBox.append(mfaBtn);

  // Password change: the API revokes every session, so the user signs in again.
  var pwSave = h('button', { type: 'submit', class: 'dbtn dbtn-primary sm' }, S.password_save);
  var pw = h('form', { style: 'display:grid;gap:8px;max-width:420px', 'data-password-form': '', onsubmit: function (e) {
    e.preventDefault(); var el = pw.elements;
    if (el.password.value !== el.password_confirmation.value) { Opes.alert(S.password_mismatch); return; }
    Opes.busy(pwSave, true);
    Opes.api('/me/password', { method: 'PUT', body: { current_password: el.current_password.value, password: el.password.value, password_confirmation: el.password_confirmation.value } })
      .then(function () { Opes.alert(S.password_done, 'ok'); setTimeout(function () { Opes.saveSession(null); location.href = '/login'; }, 2500); })
      .catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(pwSave, false); });
  } },
    h('label', { class: 'afield-s' }, h('span', null, S.password_current), input({ name: 'current_password', type: 'password', required: true, autocomplete: 'current-password' })),
    h('label', { class: 'afield-s' }, h('span', null, S.password_new), input({ name: 'password', type: 'password', required: true, minlength: 8, maxlength: 128, autocomplete: 'new-password' })),
    h('label', { class: 'afield-s' }, h('span', null, S.password_confirm), input({ name: 'password_confirmation', type: 'password', required: true, minlength: 8, maxlength: 128, autocomplete: 'new-password' })),
    h('div', { class: 'btnbar', style: 'justify-content:flex-start' }, pwSave));

  // Invitation code from a broker / insurer.
  var invSave = h('button', { type: 'submit', class: 'dbtn dbtn-outline sm' }, S.invite_accept);
  var inv = h('form', { style: 'display:grid;gap:8px;max-width:420px', 'data-invite-form': '', onsubmit: function (e) {
    e.preventDefault(); var token = inv.elements.token.value.trim(); Opes.busy(invSave, true);
    Opes.api('/invitations/accept', { body: { token: token } }).then(function (m) {
      Opes.alert(S.invite_done.replace(':tenant', (m && m.tenant_name) || ''), 'ok'); inv.reset();
      var sess = Opes.session(); if (sess) { sess.loaded_at = 0; Opes.saveSession(sess); }
    }).catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(invSave, false); });
  } },
    h('p', { class: 'sub', style: 'margin:0' }, S.invite_text),
    h('label', { class: 'afield-s' }, h('span', null, S.invite_token), input({ name: 'token', required: true, minlength: 64, maxlength: 64, autocomplete: 'off' })),
    h('div', { class: 'btnbar', style: 'justify-content:flex-start' }, invSave));

  var verify = row(); verify.append(emailBtn, phoneBtn, phoneCode, phoneConfirm);
  if (u.email_verified) emailBtn.disabled = true;
  Opes.clear(box).append(h('h2', null, S.security_title), verify,
    h('h3', null, S.mfa_title), mfaBox,
    h('h3', null, S.password_title), pw,
    h('h3', null, S.invite_title), inv);
})();
</script>
@endpush
