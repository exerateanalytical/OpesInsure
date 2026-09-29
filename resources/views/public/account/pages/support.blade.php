{{-- /account/support[?policy=<id>|claim_id=<id>|payment_id=<id>] — GET/POST /mobile/support/cases, GET /mobile/support/cases/{id}, POST .../messages;
     emergency assistance: POST /mobile/claims/emergency-assistance {policy_id, service, location, callback_phone}. --}}
@extends('public.account.layout', ['title' => __('account_policies.sup.title'), 'lede' => __('account_policies.sup.lede'), 'crumbs' => [[__('account_policies.sup.title'), null]], 'active' => 'support'])
@section('content')
@include('public.account.partials.customer-assets')
<div class="agrid main-side op-ms380">
  <section class="acard" data-page-body></section>
  <div style="display:grid;gap:16px;align-content:start"><section class="acard" data-new></section><section class="acard" data-sos></section><section class="acard" data-issue></section></div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, T = OP.T, U = T.sup, $ = Opes.$, box = $('[data-page-body]'), nb = $('[data-new]');
  var policyId = ctx.params.get('policy'), claimId = ctx.params.get('claim_id'), paymentId = ctx.params.get('payment_id');
  var cat = h('select', { name: 'category', required: true }, Object.keys(U.cats).map(function (k) { return h('option', { value: k, selected: (claimId ? k === 'CLAIM' : paymentId ? k === 'PAYMENT' : policyId && k === 'POLICY') }, U.cats[k]); }));
  var send = h('button', { type: 'submit', class: 'dbtn dbtn-primary wide' }, Opes.icon('send'), U.send);
  var form = h('form', { style: 'display:grid;gap:12px', onsubmit: function (e) {
    e.preventDefault();
    var d = {}; new FormData(form).forEach(function (v, k) { d[k] = String(v).trim(); });
    if (d.subject.length < 3 || d.description.length < 10) { Opes.alert(U.desc_h); return; }
    if (policyId) d.policy_id = policyId;
    if (claimId) d.claim_id = claimId;
    if (paymentId) d.payment_id = paymentId;
    Opes.busy(send, true);
    Opes.api('/mobile/support/cases', { body: d }).then(function (c) { Opes.alert(OP.fmt(U.sent, { ref: (c && c.reference) || '' }), 'ok'); form.reset(); return load(); })
      .catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(send, false); });
  } },
    h('label', { class: 'afield-s' }, h('span', null, U.category), cat),
    h('label', { class: 'afield-s' }, h('span', null, U.subject, h('i', null, ' *')), h('input', { name: 'subject', required: true, minlength: 3, maxlength: 200 })),
    h('label', { class: 'afield-s' }, h('span', null, U.desc, h('i', null, ' *')), h('textarea', { name: 'description', required: true, minlength: 10, maxlength: 10000, rows: 6 }), h('small', { class: 'op-muted' }, U.desc_h)),
    send);
  Opes.clear(nb).append(h('h2', null, U.new), form);

  // Emergency assistance (towing / medical / police) on one of the customer's active policies.
  var S = window.OPES_CUST.sos, sb = $('[data-sos]');
  OP.policies().then(function (pols) {
    pols = (pols || []).filter(function (p) { return /ACTIVE|EXPIRING/.test(OP.state(p)); });
    if (!pols.length) { sb.remove(); return; }
    var go = h('button', { type: 'submit', class: 'dbtn dbtn-primary wide' }, Opes.icon('phone'), S.send);
    var sf = h('form', { style: 'display:grid;gap:12px', 'data-sos-form': '', onsubmit: function (e) {
      e.preventDefault(); var el = sf.elements; Opes.busy(go, true);
      Opes.api('/mobile/claims/emergency-assistance', { body: { policy_id: el.policy_id.value, service: el.service.value, location: el.location.value.trim(), callback_phone: el.callback_phone.value.trim() } })
        .then(function (r) { Opes.alert(OP.fmt(S.sent, { ref: (r && r.reference) || '' }), 'ok'); sf.reset(); return load(); })
        .catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(go, false); });
    } },
      h('label', { class: 'afield-s' }, h('span', null, T.pol ? T.pol.policy : 'Policy'), h('select', { name: 'policy_id', required: true }, pols.map(function (p) { return h('option', { value: p.id, selected: p.id === policyId }, (p.policy_number || '') + ' — ' + OP.title(p)); }))),
      h('label', { class: 'afield-s' }, h('span', null, S.service), h('select', { name: 'service', required: true }, Object.keys(S.services).map(function (k) { return h('option', { value: k }, S.services[k]); }))),
      h('label', { class: 'afield-s' }, h('span', null, S.location, h('i', null, ' *')), h('input', { name: 'location', required: true, minlength: 3, maxlength: 500 })),
      h('label', { class: 'afield-s' }, h('span', null, S.phone, h('i', null, ' *')), h('input', { name: 'callback_phone', type: 'tel', required: true, maxlength: 32, autocomplete: 'tel', value: ((ctx.session.user || {}).phone_e164) || '' })),
      go);
    Opes.clear(sb).append(h('h2', null, S.title), h('p', { class: 'sub' }, S.text), sf);
  }).catch(function () { sb.remove(); });

  // Report a problem with the site (batch 12): POST /mobile/issue-reports {route, note, platform}.
  var SX = window.OPES_SUPX = @json(__('support_actions.portal')), ib = $('[data-issue]');
  var isend = h('button', { type: 'submit', class: 'dbtn dbtn-outline wide' }, Opes.icon('send'), SX.issue_send);
  var iform = h('form', { style: 'display:grid;gap:12px', 'data-issue-form': '', onsubmit: function (e) {
    e.preventDefault(); var note = iform.elements.note.value.trim(); if (note.length < 3) return; Opes.busy(isend, true);
    Opes.api('/mobile/issue-reports', { body: { route: location.pathname, note: note, platform: 'web' } }).then(function () { Opes.alert(SX.issue_done, 'ok'); iform.reset(); })
      .catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(isend, false); });
  } }, h('label', { class: 'afield-s' }, h('span', null, SX.issue_note), h('textarea', { name: 'note', required: true, minlength: 3, maxlength: 2000, rows: 3 })), isend);
  Opes.clear(ib).append(h('h2', null, SX.issue_title), h('p', { class: 'sub' }, SX.issue_text), iform);

  function thread(c, holder) {
    Opes.loading(holder);
    Opes.api('/mobile/support/cases/' + c.id).then(function (full) {
      Opes.clear(holder);
      (full.messages || []).forEach(function (m) { holder.appendChild(h('div', { class: 'op-msg' + (m.sender === 'CUSTOMER' ? ' me' : '') }, h('small', null, (m.sender === 'CUSTOMER' ? U.you : U.agent) + ' · ' + Opes.date(m.created_at, true)), m.body)); });
      var ta = h('textarea', { rows: 3, maxlength: 5000, 'aria-label': U.reply });
      var b = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () {
        var body = ta.value.trim(); if (!body) return; Opes.busy(b, true);
        Opes.api('/mobile/support/cases/' + c.id + '/messages', { body: { body: body } }).then(function () { thread(c, holder); }).catch(function (e) { Opes.busy(b, false); Opes.alert(e.message); });
      } }, Opes.icon('send'), U.reply_send);
      // Attachments: POST /mobile/support/cases/{id}/attachments (multipart "file", PDF/JPG/PNG up to 10 MB).
      var AT = window.OPES_CUST.attach;
      if (!/CLOSED|RESOLVED|CANCELLED/.test(String(full.status).toUpperCase())) {
        var fi = h('input', { type: 'file', accept: 'application/pdf,image/jpeg,image/png', 'aria-label': AT.label, 'data-attach-file': '' });
        var ab = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-attach': c.id, onclick: function () {
          var f = fi.files[0];
          if (!f || f.size > 10485760 || ['application/pdf', 'image/jpeg', 'image/png'].indexOf(f.type) < 0) { Opes.alert(AT.bad); return; }
          var fd = new FormData(); fd.append('file', f); Opes.busy(ab, true);
          Opes.api('/mobile/support/cases/' + c.id + '/attachments', { method: 'POST', body: fd }).then(function () { Opes.alert(AT.done, 'ok'); thread(c, holder); }).catch(function (e) { Opes.busy(ab, false); Opes.alert(e.message); });
        } }, Opes.icon('doc'), AT.send);
        holder.appendChild(h('div', { class: 'afield-s', style: 'margin-top:10px' }, h('span', null, AT.label), fi, h('div', { class: 'btnbar', style: 'margin-top:6px' }, ab)));
      }
      // Escalation (batch 12): POST /mobile/support/cases/{id}/escalate {reason?} — once per case; the API refuses closed cases.
      if (!/CLOSED|RESOLVED|CANCELLED/.test(String(full.status).toUpperCase()) && !full.escalated_at) {
        var eb = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-escalate': c.id, onclick: function () {
          var why = window.prompt(SX.escalate_reason, ''); if (why === null) return; Opes.busy(eb, true);
          Opes.api('/mobile/support/cases/' + c.id + '/escalate', { body: { reason: why.trim() || null } }).then(function () { Opes.alert(SX.escalated, 'ok'); thread(c, holder); }).catch(function (e) { Opes.busy(eb, false); Opes.alert(e.message); });
        } }, Opes.icon('bell'), SX.escalate);
        holder.appendChild(h('div', { class: 'btnbar', style: 'margin-top:10px;justify-content:flex-start' }, eb));
      }
      if (!/CLOSED|RESOLVED/.test(String(full.status).toUpperCase())) holder.appendChild(h('div', { class: 'afield-s', style: 'margin-top:10px' }, h('span', null, U.reply), ta, h('div', { class: 'btnbar', style: 'margin-top:6px' }, b)));
    }).catch(function (e) { Opes.fail(holder, e); });
  }
  function load() {
    Opes.loading(box);
    return Opes.api('/mobile/support/cases').then(function (list) {
      list = list || [];
      Opes.clear(box).appendChild(h('h2', null, U.cases));
      if (!list.length) { var e = h('div'); box.appendChild(e); return Opes.empty(e, U.none); }
      list.forEach(function (c) {
        var holder = h('div'), loaded = false;
        var det = h('details', { class: 'op-case', ontoggle: function () { if (det.open && !loaded) { loaded = true; thread(c, holder); } } },
          h('summary', null, h('b', null, c.subject), h('small', { class: 'op-muted' }, c.reference + ' · ' + (U.cats[c.category] || Opes.label(c.category)) + ' · ' + Opes.date(c.created_at)), OP.chip(c.status)),
          holder);
        box.appendChild(det);
      });
    }).catch(function (e) { Opes.fail(box, e); });
  }
  return load();
});
</script>
@endpush
