{{-- /account/requests[?policy=<id>&type=<TYPE>] — policy service requests and renewals, as in the app's services screens:
     GET/POST /mobile/policy-service-requests, GET /mobile/policy-service-requests/{id}, POST .../{id}/messages,
     POST /policies/{policy}/renewal-quote (then compare the renewal offers on /account/quotes/{quote}). --}}
@extends('public.account.layout', ['title' => __('account_customer.req.title'), 'lede' => __('account_customer.req.lede'), 'crumbs' => [[__('account_policies.pol.title'), '/account/policies'], [__('account_customer.req.title'), null]], 'active' => 'requests'])
@section('content')
@include('public.account.partials.customer-assets')
<div class="agrid main-side op-ms380">
  <section class="acard" data-page-body></section>
  <div style="display:grid;gap:16px;align-content:start">
    <section class="acard" data-new></section>
    <section class="acard" data-renew id="renew"></section>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, T = OP.T, R = window.OPES_CUST.req, $ = Opes.$, box = $('[data-page-body]'), nb = $('[data-new]'), rb = $('[data-renew]');
  var want = ctx.params.get('policy'), wantType = ctx.params.get('type');
  var names = {};
  function pname(p) { return (p.policy_number || String(p.id).slice(0, 8)) + ' — ' + OP.title(p); }

  function thread(r, holder) {
    Opes.loading(holder);
    Opes.api('/mobile/policy-service-requests/' + encodeURIComponent(r.id)).then(function (full) {
      Opes.clear(holder);
      if (full.reason) holder.appendChild(h('div', { class: 'op-msg me' }, full.reason));
      (full.requested_documents || []).length ? holder.appendChild(h('p', null, h('b', null, R.docs_needed + ' : '), full.requested_documents.map(Opes.label).join(', '))) : null;
      (full.timeline || []).forEach(function (e) { holder.appendChild(h('div', { class: 'op-msg' }, h('small', null, e.label + ' · ' + Opes.date(e.occurred_at, true)), e.description || '')); });
      if (!/CLOSED|COMPLETED|REJECTED|CANCELLED|APPLIED/.test(String(full.status).toUpperCase())) {
        var ta = h('textarea', { rows: 3, maxlength: 4000, 'aria-label': R.msg });
        var b = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () {
          var m = ta.value.trim(); if (!m) return; Opes.busy(b, true);
          Opes.api('/mobile/policy-service-requests/' + encodeURIComponent(r.id) + '/messages', { body: { message: m } }).then(function () { thread(r, holder); }).catch(function (e) { Opes.busy(b, false); Opes.alert(e.message); });
        } }, Opes.icon('send'), R.msg_send);
        holder.appendChild(h('div', { class: 'afield-s', style: 'margin-top:10px' }, h('span', null, R.msg), ta, h('div', { class: 'btnbar', style: 'margin-top:6px' }, b)));
      }
    }).catch(function (e) { Opes.fail(holder, e); });
  }
  function load() {
    Opes.loading(box);
    return Opes.api('/mobile/policy-service-requests').then(function (list) {
      list = list || [];
      Opes.clear(box).appendChild(h('h2', null, R.list_t));
      if (!list.length) { var e = h('div'); box.appendChild(e); return Opes.empty(e, R.none); }
      list.forEach(function (r) {
        var holder = h('div'), loaded = false;
        var det = h('details', { class: 'op-case', ontoggle: function () { if (det.open && !loaded) { loaded = true; thread(r, holder); } } },
          h('summary', null, h('b', null, R.types[r.type] || Opes.label(r.type)), h('small', { class: 'op-muted' }, (names[r.policy_id] || '') + ' · ' + OP.fmt(R.opened, { date: Opes.date(r.created_at) })), OP.chip(r.status)), holder);
        box.appendChild(det);
      });
    }).catch(function (e) { Opes.fail(box, e); });
  }

  return OP.policies().catch(function () { return []; }).then(function (pols) {
    pols = pols || [];
    pols.forEach(function (p) { names[p.id] = p.policy_number || ''; });
    var active = pols.filter(function (p) { return /ACTIVE|ISSUED|IN_FORCE|EXPIRING/.test(String(OP.state(p)).toUpperCase()) || String(p.status).toUpperCase() === 'ACTIVE'; });

    // New request
    if (!active.length) { Opes.clear(nb).append(h('h2', null, R.new_t)); var e0 = h('div'); nb.appendChild(e0); Opes.empty(e0, R.no_policy, OP.btn(T.get_quote, '/account/buy', 'dbtn-primary sm')); }
    else {
      var send = h('button', { type: 'submit', class: 'dbtn dbtn-primary wide' }, Opes.icon('send'), R.send);
      var form = h('form', { style: 'display:grid;gap:12px', 'data-request-form': '', onsubmit: function (e) {
        e.preventDefault();
        var reason = form.elements.reason.value.trim(); if (reason.length < 5) { Opes.alert(R.reason_h); return; }
        Opes.busy(send, true);
        Opes.api('/policies/' + encodeURIComponent(form.elements.policy_id.value) + '/service-requests', { body: { type: form.elements.type.value, reason: reason } })
          .then(function () { Opes.alert(R.sent, 'ok'); form.reset(); return load(); }).catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(send, false); });
      } },
        h('label', { class: 'afield-s' }, h('span', null, R.policy), h('select', { name: 'policy_id', required: true }, active.map(function (p) { return h('option', { value: p.id, selected: p.id === want }, pname(p)); }))),
        h('label', { class: 'afield-s' }, h('span', null, R.type), h('select', { name: 'type', required: true }, Object.keys(R.types).map(function (k) { return h('option', { value: k, selected: k === wantType }, R.types[k]); }))),
        h('label', { class: 'afield-s' }, h('span', null, R.reason, h('i', null, ' *')), h('textarea', { name: 'reason', required: true, minlength: 5, maxlength: 4000, rows: 5 }), h('small', { class: 'op-muted' }, R.reason_h)),
        send);
      Opes.clear(nb).append(h('h2', null, R.new_t), form);
    }

    // Renewal
    var soon = Date.now() + 60 * 86400000, late = Date.now() - 90 * 86400000;
    var due = pols.filter(function (p) { var end = Date.parse(p.coverage_ends_at); return end && end <= soon && end >= late; });
    Opes.clear(rb).append(h('h2', null, R.renew_t), h('p', { class: 'sub' }, R.renew_d));
    if (!due.length) rb.appendChild(h('p', { class: 'op-muted' }, R.renew_none));
    due.forEach(function (p) {
      var b = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-renew-policy': p.id, onclick: function () {
        Opes.busy(b, true);
        Opes.api('/policies/' + encodeURIComponent(p.id) + '/renewal-quote', { body: {} }).then(function (d) {
          var q = d && d.quote; location.href = '/account/quotes/' + encodeURIComponent(q && q.id);
        }).catch(function (err) { Opes.busy(b, false); Opes.alert(err.message); });
      } }, Opes.icon('refresh'), R.renew);
      rb.appendChild(h('div', { class: 'op-case', style: 'display:flex;gap:10px;align-items:center;flex-wrap:wrap' }, h('div', { style: 'flex:1;min-width:0' }, h('b', null, pname(p)), h('small', { class: 'op-muted', style: 'display:block' }, Opes.date(p.coverage_ends_at) + (OP.daysNote(p) ? ' · ' + OP.daysNote(p) : ''))), b));
    });
    return load();
  });
});
</script>
@endpush
