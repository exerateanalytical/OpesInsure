{{-- /account/kyc — identity verification, same flow as the app's onboarding/kyc screen:
     GET/PATCH /mobile/kyc/profile (identifiers), POST /mobile/documents (KYC_IDENTITY, base64) then
     POST /mobile/kyc/documents {document_id, purpose}, POST /mobile/kyc/submission {notes}. --}}
@extends('public.account.layout', ['title' => __('account_customer.kyc.title'), 'lede' => __('account_customer.kyc.lede'), 'crumbs' => [[__('account_policies.prof.title'), '/account/profile'], [__('account_customer.kyc.title'), null]], 'active' => 'kyc'])
@section('content')
@include('public.account.partials.customer-assets')
<div class="agrid c2" data-page-body>
  <div class="acard"><div class="acct-loading" role="status"><span class="spin"></span>{{ __('account.js.loading') }}</div></div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, T = OP.T, K = window.OPES_CUST.kyc, box = Opes.$('[data-page-body]');
  var EDITABLE = /^(DRAFT|MORE_INFO_REQUIRED)$/;
  var MAX = 10 * 1024 * 1024, MIMES = ['application/pdf', 'image/jpeg', 'image/png'];
  function card(t) { return h('section', { class: 'acard' }, h('h2', null, t)); }

  function load() {
    Opes.loading(box);
    return Opes.api('/mobile/kyc/profile').then(function (p) {
      p = p || {}; var s = p.submission, st = s ? String(s.status).toUpperCase() : 'NONE';
      var editable = !s || EDITABLE.test(st);
      Opes.clear(box);

      // Status
      var sc = card(K.status_t);
      if (!s) sc.appendChild(h('p', { class: 'op-muted' }, K.none));
      else {
        sc.appendChild(h('p', null, Opes.chip(st)));
        sc.appendChild(h('dl', { class: 'kv' },
          s.submitted_at ? [h('dt', null, K.submitted), h('dd', null, Opes.date(s.submitted_at, true))] : null,
          s.reviewed_at ? [h('dt', null, K.reviewed), h('dd', null, Opes.date(s.reviewed_at, true))] : null,
          s.expires_at ? [h('dt', null, K.expires), h('dd', null, Opes.date(s.expires_at))] : null));
        if (s.remediation_reason) sc.appendChild(h('p', { class: 'acct-alert', style: 'display:block' }, K.remediation + ' : ' + s.remediation_reason));
        var miss = s.missing_requirements || [];
        sc.appendChild(miss.length ? h('p', null, h('b', null, K.missing + ' : '), miss.map(Opes.label).join(', ')) : h('p', { class: 'op-muted' }, K.all_ok));
      }
      box.appendChild(sc);

      // Identifiers
      var ic = card(K.ids_t);
      var ids = p.identifiers || [];
      ic.appendChild(ids.length ? h('ul', { class: 'op-nlist' }, ids.map(function (i) {
        return h('li', null, h('div', null, h('b', null, K.types[i.type] || Opes.label(i.type)), h('small', { class: 'op-muted', style: 'display:block' }, i.masked_value + ' · ' + i.country_code)),
          h('span', { class: 'st ' + (i.verified_at ? 'st-ok' : 'st-muted') }, i.verified_at ? K.verified : K.unverified));
      })) : h('p', { class: 'op-muted' }, K.ids_none));
      var add = h('button', { type: 'submit', class: 'dbtn dbtn-outline sm' }, Opes.icon('check'), K.add);
      var idf = h('form', { class: 'op-form', 'data-kyc-id': '', onsubmit: function (e) {
        e.preventDefault(); Opes.busy(add, true);
        Opes.api('/mobile/kyc/profile', { method: 'PATCH', body: { identifier_type: idf.elements.identifier_type.value, identifier_value: idf.elements.identifier_value.value.trim(), identifier_country: idf.elements.identifier_country.value.trim().toUpperCase() || 'CM' } })
          .then(function () { Opes.alert(K.added, 'ok'); return load(); }).catch(function (err) { Opes.busy(add, false); Opes.alert(err.message); });
      } },
        h('label', { class: 'afield-s' }, h('span', null, K.id_type), h('select', { name: 'identifier_type', required: true }, Object.keys(K.types).map(function (k) { return h('option', { value: k }, K.types[k]); }))),
        h('label', { class: 'afield-s' }, h('span', null, K.id_value), h('input', { name: 'identifier_value', required: true, maxlength: 64, autocomplete: 'off' })),
        h('label', { class: 'afield-s' }, h('span', null, K.id_country), h('input', { name: 'identifier_country', value: 'CM', maxlength: 2, minlength: 2 })),
        h('div', { class: 'btnbar full' }, add));
      ic.append(h('h3', { style: 'font-size:15px;margin:16px 0 8px' }, K.add_t), idf);
      box.appendChild(ic);

      // Documents
      var dc = card(K.docs_t);
      var docs = (s && s.documents) || [];
      dc.appendChild(docs.length ? h('ul', { class: 'op-nlist' }, docs.map(function (d) {
        return h('li', null, h('span', { class: 'op-li' }, Opes.icon('doc')), h('div', null, h('b', null, K.purposes[d.purpose] || Opes.label(d.purpose))), Opes.chip(d.verification_status || d.scan_status));
      })) : h('p', { class: 'op-muted' }, T.no_docs));
      if (editable) {
        var up = h('button', { type: 'submit', class: 'dbtn dbtn-outline sm' }, Opes.icon('download'), K.upload);
        var df = h('form', { class: 'op-form', 'data-kyc-doc': '', onsubmit: function (e) {
          e.preventDefault(); var f = df.elements.file.files[0];
          if (!f || f.size > MAX || MIMES.indexOf(f.type) < 0) { Opes.alert(K.bad_file); return; }
          Opes.busy(up, true);
          Opes.fileBase64(f).then(function (b64) { return Opes.api('/mobile/documents', { body: { category: 'KYC_IDENTITY', mime_type: f.type, file_base64: b64 } }); })
            .then(function (doc) { return Opes.api('/mobile/kyc/documents', { body: { document_id: doc.id, purpose: df.elements.purpose.value } }); })
            .then(function () { Opes.alert(K.uploaded, 'ok'); return load(); }).catch(function (err) { Opes.busy(up, false); Opes.alert(err.message); });
        } },
          h('label', { class: 'afield-s' }, h('span', null, K.doc_purpose), h('select', { name: 'purpose', required: true }, Object.keys(K.purposes).map(function (k) { return h('option', { value: k }, K.purposes[k]); }))),
          h('label', { class: 'afield-s' }, h('span', null, K.doc_file), h('input', { type: 'file', name: 'file', required: true, accept: 'application/pdf,image/jpeg,image/png' })),
          h('div', { class: 'btnbar full' }, up));
        dc.append(h('h3', { style: 'font-size:15px;margin:16px 0 8px' }, K.doc_t), df);
      }
      box.appendChild(dc);

      // Submit
      if (s && EDITABLE.test(st)) {
        var sub = card(K.submit_t);
        var go = h('button', { type: 'submit', class: 'dbtn dbtn-primary' }, Opes.icon('send'), K.submit);
        var sf = h('form', { 'data-kyc-submit': '', onsubmit: function (e) {
          e.preventDefault(); Opes.busy(go, true);
          Opes.api('/mobile/kyc/submission', { body: { notes: sf.elements.notes.value.trim() || null } }).then(function () { Opes.alert(K.sent, 'ok'); return load(); })
            .catch(function (err) { Opes.busy(go, false); Opes.alert(err.message); if (err.status === 409) load(); });
        } }, h('label', { class: 'afield-s' }, h('span', null, K.notes), h('textarea', { name: 'notes', rows: 3, maxlength: 2000 })), h('div', { class: 'btnbar' }, go));
        sub.appendChild(sf);
        box.appendChild(sub);
      }
    }).catch(function (e) { Opes.fail(box, e); });
  }
  return load();
});
</script>
@endpush
