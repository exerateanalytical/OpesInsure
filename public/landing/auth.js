// OpesInsure website sign-in / sign-up. Talks to the same customer-account API
// as the mobile app (api/v1 auth/mobile/*, public/accounts). The session is kept
// in localStorage (shared with the /account pages, see public/landing/portal/portal.js).
(function () {
  var C = window.OPES_AUTH || {};
  var API = C.api || '/api/v1';
  var KEY = 'opes.web.session';

  function store(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }
  function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function device() {
    var id = null;
    try { id = localStorage.getItem('opes.web.device'); } catch (e) {}
    if (!id) {
      id = 'web-' + (window.crypto && crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
      try { localStorage.setItem('opes.web.device', id); } catch (e) {}
    }
    return { fingerprint: id, name: 'Web browser', platform: 'web' };
  }
  function phone(v) {
    var d = String(v || '').replace(/[\s\-().]/g, '');
    if (!d) return '';
    if (d.indexOf('00') === 0) d = '+' + d.slice(2);
    if (d.charAt(0) !== '+') d = d.length === 9 ? '+237' + d : '+' + d;
    return /^\+[1-9]\d{7,14}$/.test(d) ? d : '';
  }
  function post(path, body, token) {
    var h = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Accept-Language': C.locale || 'en' };
    if (token) h.Authorization = 'Bearer ' + token;
    return fetch(API + path, { method: 'POST', headers: h, body: JSON.stringify(body || {}), credentials: 'omit' })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); });
  }
  function firstError(res, fallback) {
    var b = res.body || {};
    if (b.errors) { for (var k in b.errors) { var v = b.errors[k]; return Array.isArray(v) ? v[0] : String(v); } }
    return b.message && res.status !== 500 ? b.message : fallback;
  }

  var root = document.querySelector('.auth-card');
  if (!root) return;
  var steps = {};
  Array.prototype.forEach.call(root.querySelectorAll('[data-step]'), function (el) { steps[el.dataset.step] = el; });
  function show(name) {
    for (var k in steps) steps[k].hidden = k !== name;
    var f = steps[name].querySelector('input:not([type=hidden]),button'); if (f) f.focus();
  }
  function err(step, msg) {
    var box = steps[step].querySelector('.auth-err');
    if (!box) return;
    box.textContent = msg || ''; box.hidden = !msg;
  }
  function busy(form, on) {
    var b = form.querySelector('button[type=submit]');
    if (!b) return;
    if (on) { b.dataset.label = b.innerHTML; b.textContent = C.wait || '…'; b.disabled = true; }
    else if (b.dataset.label) { b.innerHTML = b.dataset.label; b.disabled = false; }
  }
  function signedIn(data) {
    var user = (data && data.user) || {};
    store(KEY, JSON.stringify({ access_token: data.access_token, refresh_token: data.refresh_token, name: user.full_name || user.phone_e164 || '' }));
    // Continue into the account area (or the page that sent the visitor to sign in).
    var next = new URLSearchParams(location.search).get('next') || '';
    location.href = /^\/account(\/|$|\?)/.test(next) ? next : '/account';
  }

  // Password visibility
  Array.prototype.forEach.call(document.querySelectorAll('[data-toggle-pw]'), function (b) {
    b.addEventListener('click', function () {
      var i = document.getElementById(b.dataset.togglePw);
      i.type = i.type === 'password' ? 'text' : 'password';
      b.setAttribute('aria-pressed', String(i.type === 'text'));
    });
  });

  // Already signed in this tab
  var existing = null;
  try { existing = JSON.parse(read(KEY) || 'null'); } catch (e) {}
  if (existing && existing.access_token) signedIn({ access_token: existing.access_token, refresh_token: existing.refresh_token, user: { full_name: existing.name } });

  var signout = root.querySelector('[data-signout]');
  if (signout) signout.addEventListener('click', function () {
    var s = null; try { s = JSON.parse(read(KEY) || 'null'); } catch (e) {}
    store(KEY, null);
    if (s && s.access_token) post('/auth/mobile/logout', { refresh_token: s.refresh_token }, s.access_token).catch(function () {});
    show('form');
  });

  // One code step shared by OTP sign-in, password reset and sign-up verification.
  var pending = null; // {mode: 'otp'|'reset'|'register', challenge_id, phone}
  var codeForm = document.getElementById('code-form');
  function askCode(mode, challenge, ph) {
    pending = { mode: mode, challenge_id: challenge, phone: ph };
    var msg = root.querySelector('[data-code-msg]');
    if (msg) msg.textContent = (C.codeSent || '').replace(':phone', ph);
    var np = codeForm.querySelector('[data-newpw]');
    if (np) { np.hidden = mode !== 'reset'; np.querySelector('input').required = mode === 'reset'; }
    var btn = codeForm.querySelector('button[type=submit]');
    btn.textContent = mode === 'reset' ? btn.dataset.resetLabel : btn.dataset.verifyLabel;
    codeForm.reset(); err('code', ''); show('code');
  }
  if (codeForm) codeForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var code = (codeForm.code.value || '').trim();
    if (!/^\d{6}$/.test(code)) { err('code', C.err.code); return; }
    busy(codeForm, true); err('code', '');
    var req = pending.mode === 'reset'
      ? post('/auth/mobile/password/reset', { phone_e164: pending.phone, code: code, challenge_id: pending.challenge_id, password: codeForm.password.value, device: device() })
      : post('/auth/mobile/otp/verify', { challenge_id: pending.challenge_id, code: code, device: device() });
    req.then(function (r) {
      busy(codeForm, false);
      if (r.ok && r.body.data && r.body.data.access_token) signedIn(r.body.data);
      else err('code', r.status === 422 ? firstError(r, C.err.code) : C.err.code);
    }).catch(function () { busy(codeForm, false); err('code', C.err.generic); });
  });
  var back = root.querySelector('[data-back]');
  if (back) back.addEventListener('click', function () { show('form'); });

  // ---- Sign in ----
  var login = document.getElementById('login-form');
  if (login) {
    login.addEventListener('submit', function (e) {
      e.preventDefault();
      var ph = phone(login.phone.value);
      if (!ph) { err('form', C.err.phone); return; }
      if (!login.password.value) { err('form', C.err.login); return; }
      busy(login, true); err('form', '');
      post('/auth/mobile/password-login', { phone_e164: ph, password: login.password.value, device: device() }).then(function (r) {
        busy(login, false);
        if (r.ok && r.body.data && r.body.data.access_token) signedIn(r.body.data);
        else err('form', r.status === 401 || r.status === 422 ? C.err.login : firstError(r, C.err.generic));
      }).catch(function () { busy(login, false); err('form', C.err.generic); });
    });
    function codeFlow(path, mode, extra) {
      return function () {
        var ph = phone(login.phone.value);
        if (!ph) { err('form', C.err.phone); login.phone.focus(); return; }
        err('form', '');
        post(path, Object.assign({ phone_e164: ph }, extra || {})).then(function (r) {
          var d = r.body && r.body.data;
          if (r.ok) askCode(mode, d && d.challenge_id, ph);
          else err('form', firstError(r, C.err.generic));
        }).catch(function () { err('form', C.err.generic); });
      };
    }
    var otp = root.querySelector('[data-otp]');
    if (otp) otp.addEventListener('click', codeFlow('/auth/mobile/otp/request', 'otp'));
    var forgot = root.querySelector('[data-forgot]');
    if (forgot) forgot.addEventListener('click', codeFlow('/auth/mobile/password/forgot', 'reset'));
  }

  // ---- Sign up ----
  var signup = document.getElementById('signup-form');
  if (signup) {
    var sw = signup.querySelector('[data-partner-switch]');
    var partner = signup.querySelector('.apartner'), cust = signup.querySelector('[data-customer]');
    sw.addEventListener('change', function () { var p = sw.value === 'partner'; partner.hidden = !p; cust.hidden = p; });
    signup.addEventListener('submit', function (e) {
      e.preventDefault();
      var ph = phone(signup.phone.value);
      if (!ph) { err('form', C.err.phone); return; }
      if (signup.password.value !== signup.password_confirmation.value) { err('form', C.err.match); return; }
      if (!signup.terms.checked) { err('form', C.err.terms); return; }
      busy(signup, true); err('form', '');
      post('/public/accounts', {
        full_name: signup.full_name.value.trim() || null, email: signup.email.value.trim() || null, phone_e164: ph,
        password: signup.password.value, password_confirmation: signup.password_confirmation.value,
        locale: C.locale === 'fr' ? 'fr' : 'en', terms_version: 'web', device: device()
      }).then(function (r) {
        busy(signup, false);
        var d = r.body && r.body.data;
        if (!r.ok) { err('form', firstError(r, C.err.generic)); return; }
        if (d && d.access_token) signedIn(d);
        else if (d && d.verification_required) askCode('register', d.challenge_id, ph);
        else err('form', C.err.generic);
      }).catch(function () { busy(signup, false); err('form', C.err.generic); });
    });
  }
})();
