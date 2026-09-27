{{-- /account/privacy — same features as the app's Privacy & Security screens:
     GET/PUT /mobile/account/consents, GET/PUT /mobile/account/notification-preferences, PUT /mobile/account/locale,
     GET /mobile/account/devices + DELETE /mobile/account/devices/{id}, GET/POST /mobile/account/privacy-requests. --}}
@extends('public.account.layout', ['title' => __('account_customer.privacy.title'), 'lede' => __('account_customer.privacy.lede'), 'crumbs' => [[__('account_customer.privacy.title'), null]], 'active' => 'privacy'])
@section('content')
@include('public.account.partials.customer-assets')
<div class="agrid c2" data-page-body>
  <section class="acard" data-consents></section>
  <section class="acard" data-prefs></section>
  <section class="acard" data-dsr></section>
  <section class="acard" data-devices></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, T = OP.T, P = window.OPES_CUST.priv, $ = Opes.$;
  function fmt(s, v) { return OP.fmt(s, v); }
  function check(name, label, on, sub) {
    return h('label', { class: 'op-check', style: 'display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid #EEF2F8' },
      h('input', { type: 'checkbox', name: name, checked: !!on, style: 'margin-top:3px;flex:none' }), h('span', null, label, sub ? h('small', { class: 'op-muted', style: 'display:block' }, sub) : null));
  }

  // Consents
  var cb = $('[data-consents]');
  function consents() {
    Opes.loading(cb);
    return Opes.api('/mobile/account/consents').then(function (rows) {
      rows = rows || [];
      var save = h('button', { type: 'submit', class: 'dbtn dbtn-primary sm' }, Opes.icon('check'), T.save);
      var form = h('form', { 'data-consent-form': '', onsubmit: function (e) {
        e.preventDefault(); Opes.busy(save, true);
        var body = { consents: rows.map(function (r) { return { purpose: r.purpose, granted: !!form.elements[r.purpose].checked }; }) };
        Opes.api('/mobile/account/consents', { method: 'PUT', body: body }).then(function () { Opes.alert(P.saved, 'ok'); return consents(); })
          .catch(function (err) { Opes.busy(save, false); Opes.alert(err.message); });
      } }, rows.map(function (r) { return check(r.purpose, P.purposes[r.purpose] || Opes.label(r.purpose), r.granted, r.updated_at ? fmt(P.since, { date: Opes.date(r.updated_at) }) : null); }), h('div', { class: 'btnbar' }, save));
      Opes.clear(cb).append(h('h2', null, P.consent_t), h('p', { class: 'sub' }, P.consent_d), form);
    }).catch(function (e) { Opes.fail(cb, e); });
  }

  // Notification preferences + language
  var pb = $('[data-prefs]');
  function prefs() {
    Opes.loading(pb);
    return Opes.api('/mobile/account/notification-preferences').then(function (p) {
      p = p || {};
      var save = h('button', { type: 'submit', class: 'dbtn dbtn-primary sm' }, Opes.icon('check'), T.save);
      var keys = Object.keys(P.prefs);
      var form = h('form', { onsubmit: function (e) {
        e.preventDefault(); Opes.busy(save, true);
        var body = {}; keys.forEach(function (k) { body[k] = !!form.elements[k].checked; });
        Opes.api('/mobile/account/notification-preferences', { method: 'PUT', body: body }).then(function () { Opes.alert(P.saved, 'ok'); })
          .catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(save, false); });
      } },
        h('h3', { class: 'op-muted', style: 'font-size:13px;margin:6px 0 0' }, P.channels), ['push', 'sms', 'email'].map(function (k) { return check(k, P.prefs[k], p[k]); }),
        h('h3', { class: 'op-muted', style: 'font-size:13px;margin:14px 0 0' }, P.topics), ['renewals', 'claims', 'payments'].map(function (k) { return check(k, P.prefs[k], p[k]); }),
        h('div', { class: 'btnbar' }, save));
      var lang = h('select', { 'aria-label': P.lang_t, onchange: function () {
        Opes.api('/mobile/account/locale', { method: 'PUT', body: { locale: lang.value } }).then(function () { Opes.alert(P.lang_saved, 'ok'); }).catch(function (err) { Opes.alert(err.message); });
      } }, [['en', 'English'], ['fr', 'Français']].map(function (o) { return h('option', { value: o[0], selected: ((ctx.session.user || {}).locale || Opes.locale) === o[0] }, o[1]); }));
      Opes.clear(pb).append(h('h2', null, P.notif_t), form, h('h2', { style: 'margin-top:18px' }, P.lang_t), h('p', { class: 'sub' }, P.lang_d), h('label', { class: 'afield-s' }, lang));
    }).catch(function (e) { Opes.fail(pb, e); });
  }

  // Data-subject requests
  var db = $('[data-dsr]');
  function dsr() {
    Opes.loading(db);
    return Opes.api('/mobile/account/privacy-requests').then(function (rows) {
      rows = rows || [];
      function ask(type, btn) {
        if (type === 'DELETE' && !window.confirm(P.delete_q)) return;
        Opes.busy(btn, true);
        Opes.api('/mobile/account/privacy-requests', { body: { type: type } }).then(function (x) { Opes.alert(fmt(P.dsr_sent, { ref: (x && x.reference) || '' }), 'ok'); return dsr(); })
          .catch(function (err) { Opes.busy(btn, false); Opes.alert(err.message); });
      }
      var bx = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-dsr-export': '', onclick: function () { ask('EXPORT', bx); } }, Opes.icon('download'), P.export);
      var bd = h('button', { type: 'button', class: 'dbtn dbtn-outline sm cl-danger', 'data-dsr-delete': '', onclick: function () { ask('DELETE', bd); } }, Opes.icon('trash'), P.delete);
      var list = rows.length ? h('ul', { class: 'op-nlist' }, rows.map(function (x) {
        return h('li', null, h('div', null, h('b', null, (P.types[x.type] || x.type) + ' · ' + (x.reference || '')),
          h('small', { class: 'op-muted', style: 'display:block' }, Opes.date(x.created_at) + (x.completed_at ? ' · ' + fmt(P.done, { date: Opes.date(x.completed_at) }) : x.due_on ? ' · ' + fmt(P.due, { date: Opes.date(x.due_on) }) : ''))), Opes.chip(x.status));
      })) : h('p', { class: 'op-muted' }, P.dsr_none);
      Opes.clear(db).append(h('h2', null, P.dsr_t), h('p', { class: 'sub' }, P.dsr_d), h('div', { class: 'btnbar', style: 'justify-content:flex-start' }, bx, bd), list);
    }).catch(function (e) { Opes.fail(db, e); });
  }

  // Devices
  var vb = $('[data-devices]');
  function devices() {
    Opes.loading(vb);
    return Opes.api('/mobile/account/devices').then(function (rows) {
      rows = rows || [];
      Opes.clear(vb).appendChild(h('h2', null, P.dev_t));
      if (!rows.length) { vb.appendChild(h('p', { class: 'op-muted' }, P.dev_none)); return; }
      vb.appendChild(h('ul', { class: 'op-nlist' }, rows.map(function (d) {
        var b = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () {
          if (!window.confirm(P.revoke_q)) return; Opes.busy(b, true);
          Opes.api('/mobile/account/devices/' + encodeURIComponent(d.id), { method: 'DELETE' }).then(function () { Opes.alert(P.revoked, 'ok'); return devices(); })
            .catch(function (err) { Opes.busy(b, false); Opes.alert(err.message); });
        } }, Opes.icon('logout'), P.revoke);
        return h('li', null, h('span', { class: 'op-li' }, Opes.icon('phone')), h('div', null, h('b', null, d.name), d.current ? h('small', { class: 'st st-info', style: 'margin-left:8px' }, P.this) : null,
          h('small', { class: 'op-muted', style: 'display:block' }, fmt(P.last_seen, { date: Opes.date(d.last_seen_at, true) }))), b);
      })));
    }).catch(function (e) { Opes.fail(vb, e); });
  }

  return Promise.all([consents(), prefs(), dsr(), devices()]);
});
</script>
@endpush
