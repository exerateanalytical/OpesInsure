{{-- /account/payments — payments list with receipts (GET /mobile/payments, /mobile/payments/{id}/receipt, retry) and refund requests
     (POST /mobile/payments/{id}/refunds behind a one-time code: Opes.stepUp PAYMENT_REFUND_REQUEST). --}}
@extends('public.account.layout', ['title' => __('account_policies.pays.title'), 'lede' => __('account_policies.pays.lede'), 'crumbs' => [[__('account_policies.pays.title'), null]], 'active' => 'payments'])
@section('content')
@include('public.account.partials.customer-assets')
<div class="stats" data-stats></div>
<section class="acard" data-page-body></section>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, T = OP.T, Y = T.pays, $ = Opes.$, box = $('[data-page-body]'), stats = $('[data-stats]');
  var RF = window.OPES_CUST.refund;
  /** Ask the reason (and optional partial amount), then confirm with a one-time code and POST /refunds. */
  function refund(x) {
    var err = h('p', { class: 'err', role: 'alert', hidden: true, style: 'color:#B42318;font-size:13px' });
    var ok = h('button', { type: 'submit', class: 'dbtn dbtn-primary sm', value: 'ok', 'data-refund-send': '' }, RF.send);
    var form = h('form', { method: 'dialog' }, h('h2', null, RF.title),
      h('label', { class: 'afield-s' }, h('span', null, RF.reason, h('i', null, ' *')), h('textarea', { name: 'reason', required: true, minlength: 5, maxlength: 2000, rows: 4 })),
      h('label', { class: 'afield-s' }, h('span', null, RF.amount), h('input', { name: 'amount', type: 'number', min: 1, max: Math.floor((x.amount_minor || 0) / 100), step: 1 })), err,
      h('div', { class: 'btnbar' }, h('button', { type: 'submit', class: 'dbtn dbtn-outline sm', value: 'cancel', formnovalidate: true }, RF.cancel), ok));
    var dlg = h('dialog', { class: 'cl-dlg' }, form);
    form.addEventListener('submit', function (e) {
      if (e.submitter && e.submitter.value === 'cancel') return;
      e.preventDefault();
      var body = { reason: form.elements.reason.value.trim(), reason_code: 'CUSTOMER_REQUEST' };
      var amt = Number(form.elements.amount.value); if (amt > 0) body.amount_minor = Math.round(amt * 100);
      dlg.close();
      Opes.stepUp('PAYMENT_REFUND_REQUEST', '/mobile/payments/' + encodeURIComponent(x.id) + '/refunds', { method: 'POST', body: body })
        .then(function () { Opes.alert(RF.done, 'ok'); }).catch(function (e2) { if (e2 && !e2.cancelled) Opes.alert(e2.message); });
    });
    dlg.addEventListener('close', function () { if (dlg.parentNode) dlg.remove(); });
    document.body.appendChild(dlg); dlg.showModal();
  }
  Opes.loading(box);
  return Promise.all([OP.payments(), OP.policies().catch(function () { return []; }), OP.proposals().catch(function () { return []; })]).then(function (r) {
    var list = r[0], byProp = {};
    r[1].forEach(function (p) { byProp[p.proposal_id] = { name: OP.title(p), href: '/account/policies/' + p.id, sub: p.policy_number }; });
    r[2].forEach(function (p) { if (!byProp[p.id]) byProp[p.id] = { name: p.product_name, sub: p.carrier_name, status: p.status }; });
    var okList = list.filter(OP.ok);
    var failed = list.filter(function (x) { return /FAIL|CANCEL|EXPIRE/.test(String(x.status).toUpperCase()); });
    var pending = list.length - okList.length - failed.length;
    Opes.clear(stats).append(
      OP.stat('card', 'blue', Y.s_total, Opes.money(okList.reduce(function (s, x) { return s + (x.amount_minor || 0); }, 0), { minor: true }), ''),
      OP.stat('check', 'green', Y.s_count, okList.length, ''), OP.stat('clock', 'orange', Y.s_pending, pending, ''), OP.stat('x', 'red', Y.s_failed, failed.length, ''));
    var head = h('div', { class: 'acard-h' }, h('h2', null, T.show.pay_t), OP.btn(T.pay.pay_now, '/account/payments/new', 'dbtn-primary sm', 'card'));
    if (!list.length) { Opes.clear(box).appendChild(head); var e = h('div'); box.appendChild(e); return Opes.empty(e, T.no_payments); }
    Opes.clear(box).append(head, OP.table([
      [Y.date, function (x) { return Opes.date(x.created_at, true); }],
      [Y.for, function (x) { var p = byProp[x.proposal_id]; if (!p) return '—'; var n = h('div', null, p.href ? h('a', { class: 'rowlink', href: p.href }, p.name) : h('b', null, p.name)); if (p.sub) n.appendChild(h('small', { class: 'op-muted', style: 'display:block' }, p.sub)); return n; }],
      [Y.method, function (x) { return OP.provider(x.provider); }],
      [Y.ref, function (x) { return x.provider_reference; }],
      [Y.amount, function (x) { return h('b', null, OP.mm(x.amount_minor)); }],
      [Y.status, function (x) { return OP.chip(x.status); }],
      [T.receipt, function (x) {
        if (OP.ok(x)) return h('div', { class: 'op-acts' }, h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { OP.openReceipt(x.id); } }, Opes.icon('download'), T.receipt),
          h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-refund': x.id, onclick: function () { refund(x); } }, Opes.icon('refresh'), RF.btn));
        var p = byProp[x.proposal_id];
        if (String(x.status).toUpperCase() === 'FAILED') {
          // REQ-PAY-008: a new attempt under the same payment (retry limits and no-double-charge guards server-side).
          var rb = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-retry': x.id }, Opes.icon('refresh'), T.pay.retry);
          rb.addEventListener('click', function () {
            Opes.busy(rb, true);
            Opes.api('/mobile/payments/' + encodeURIComponent(x.id) + '/retry', { method: 'POST', body: {} })
              .then(function () { Opes.alert(Y.retry_done, 'ok'); rb.remove(); })
              .catch(function (e3) { Opes.busy(rb, false); Opes.alert(e3.message); });
          });
          return rb;
        }
        if (p && String(p.status).toUpperCase() === 'PAYMENT_PENDING') return OP.btn(T.pay.retry, '/account/payments/new?proposal=' + x.proposal_id, 'dbtn-outline sm', 'refresh');
        return h('small', { class: 'op-muted' }, '—');
      }]
    ], list, 'op-stack'));
  }).catch(function (e) { Opes.fail(box, e); });
});
</script>
@endpush
