{{-- /account/support[?policy=<id>] — GET/POST /mobile/support/cases, GET /mobile/support/cases/{id}, POST .../messages. --}}
@extends('public.account.layout', ['title' => __('account_policies.sup.title'), 'lede' => __('account_policies.sup.lede'), 'crumbs' => [[__('account_policies.sup.title'), null]], 'active' => 'support'])
@section('content')
@include('public.account.partials.policies-assets')
<div class="agrid main-side op-ms380">
  <section class="acard" data-page-body></section>
  <section class="acard" data-new></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, T = OP.T, U = T.sup, $ = Opes.$, box = $('[data-page-body]'), nb = $('[data-new]');
  var policyId = ctx.params.get('policy');
  var cat = h('select', { name: 'category', required: true }, Object.keys(U.cats).map(function (k) { return h('option', { value: k, selected: policyId && k === 'POLICY' }, U.cats[k]); }));
  var send = h('button', { type: 'submit', class: 'dbtn dbtn-primary wide' }, Opes.icon('send'), U.send);
  var form = h('form', { style: 'display:grid;gap:12px', onsubmit: function (e) {
    e.preventDefault();
    var d = {}; new FormData(form).forEach(function (v, k) { d[k] = String(v).trim(); });
    if (d.subject.length < 3 || d.description.length < 10) { Opes.alert(U.desc_h); return; }
    if (policyId) d.policy_id = policyId;
    Opes.busy(send, true);
    Opes.api('/mobile/support/cases', { body: d }).then(function (c) { Opes.alert(OP.fmt(U.sent, { ref: (c && c.reference) || '' }), 'ok'); form.reset(); return load(); })
      .catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(send, false); });
  } },
    h('label', { class: 'afield-s' }, h('span', null, U.category), cat),
    h('label', { class: 'afield-s' }, h('span', null, U.subject, h('i', null, ' *')), h('input', { name: 'subject', required: true, minlength: 3, maxlength: 200 })),
    h('label', { class: 'afield-s' }, h('span', null, U.desc, h('i', null, ' *')), h('textarea', { name: 'description', required: true, minlength: 10, maxlength: 10000, rows: 6 }), h('small', { class: 'op-muted' }, U.desc_h)),
    send);
  Opes.clear(nb).append(h('h2', null, U.new), form);

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
