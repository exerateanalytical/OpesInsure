{{-- /account/claims-desk/{id}/paid — design: screens/compare_buy_flow/22_stage.png (Payment Confirmed).
     Shows success only when the API has the claim PAID/CLOSED or a claim payment with status PAID. --}}
@php $K = __('account_desk'); @endphp
@extends('public.account.layout', ['title' => $K['paid_t'], 'lede' => $K['paid_lede'], 'crumbs' => [[$K['crumb_claims'], '/account/claims'], [$K['crumb_desk'], '/account/claims-desk'], [$K['crumb_claim'], '#'], [$K['crumb_paid'], null]], 'active' => 'desk'])
@section('content')
@include('public.account.desk.assets')
<div data-desk class="agrid"><div data-page-body></div></div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var D = Desk, O = Opes, h = O.h, id = ctx.ids[0];
  if (!D.guard(ctx)) return;
  var box = O.$('[data-page-body]');
  O.loading(box);
  return D.load(id).then(draw).catch(function (e) { D.fail(box, e); });

  function draw(c) {
    var cl = O.$$('.crumbs a').pop(); if (cl) { cl.textContent = c.claim_number; cl.href = D.base(c.id); }
    var pay = (c.payments || []).filter(function (p) { return p.status === 'PAID'; }).pop() || null;
    var paid = !!pay || c.status === 'PAID' || c.status === 'CLOSED' && c.approved_amount_minor > 0;
    var ev = (c.timeline || []).filter(function (e) { return e.to_status === 'PAID'; }).pop();
    var amount = pay ? pay.amount_minor : c.approved_amount_minor;
    var when = (pay && pay.paid_at) || (ev && ev.occurred_at) || null;

    var hero = h('section', { class: 'acard paid-hero' }, h('span', { class: 'ok' + (paid ? '' : ' wait') }, O.icon(paid ? 'check' : 'clock')),
      h('h2', null, paid ? D.t('paid_ok') : D.t('paid_wait')), h('p', null, paid ? D.t('paid_ok_d') : D.t('paid_wait_d')),
      paid ? null : D.chip(c.status),
      h('div', { class: 'btnbar no-print', style: 'justify-content:center' }, paid ? h('button', { type: 'button', class: 'dbtn dbtn-primary', onclick: function () { window.print(); } }, O.icon('download'), D.t('print')) : null,
        h('a', { class: 'dbtn dbtn-outline', href: D.base(c.id) }, D.t('ns_history'))),
      paid ? D.note(D.t('receipt_dl_na')) : null);

    var details = D.card(D.t('pay_details_t'), 'doc', h('div', { class: 'desk-fields c3' },
      D.field(D.t('claim_no'), c.claim_number), D.field(D.t('approved_amt'), D.money(c.approved_amount_minor), 'money ok'), D.field(D.t('pay_date'), when ? O.date(when, true) : '—'),
      D.field(D.t('policy_no'), c.policy_number), D.field(D.t('deductible'), D.t('deductible_na')), D.field(D.t('tx_ref'), pay && pay.external_reference),
      D.field(D.t('incident_type'), D.type(c.incident_type)), D.field(D.t('net_payable'), D.money(amount), 'money ok'), D.field(D.t('beneficiary'), c.customer_name)));

    var next = D.card(D.t('next_steps'), 'bulb', h('div', null, h('p', { class: 'sub' }, D.t('next_steps_d')), h('div', { class: 'nsteps' },
      h('div', null, h('b', null, D.t('ns_print')), h('small', null, D.t('ns_print_d')), h('button', { type: 'button', class: 'dbtn dbtn-outline sm', disabled: !paid, onclick: function () { window.print(); } }, D.t('print'))),
      h('div', null, h('b', null, D.t('ns_history')), h('small', null, D.t('ns_history_d')), h('a', { class: 'dbtn dbtn-outline sm', href: D.base(c.id) }, D.t('view'))),
      h('div', null, h('b', null, D.t('ns_queue')), h('small', null, D.t('ns_queue_d')), h('a', { class: 'dbtn dbtn-outline sm', href: '/account/claims-desk' }, D.t('back_desk'))))));

    var receipt = h('section', { class: 'acard' }, h('h2', null, D.t('receipt'), paid ? D.chip('PAID') : D.chip(c.status)), paid ? h('div', { class: 'receipt' },
      h('h3', null, D.t('receipt_t').toUpperCase()),
      h('dl', null, h('dt', null, D.t('tx_ref')), h('dd', null, (pay && pay.external_reference) || '—'), h('dt', null, D.t('pay_date')), h('dd', null, when ? O.date(when, true) : '—'),
        h('dt', null, D.t('claim_no')), h('dd', null, c.claim_number), h('dt', null, D.t('policy_no')), h('dd', null, c.policy_number || '—'),
        h('dt', null, D.t('customer')), h('dd', null, c.customer_name || '—'), h('dt', null, D.t('approved_amt')), h('dd', null, D.money(c.approved_amount_minor))),
      h('div', { class: 'net' }, h('span', null, D.t('net_payable')), h('span', null, D.money(amount)))) : h('p', { class: 'sub' }, D.t('no_payments')));

    O.clear(box).append(h('div', { class: 'agrid' }, D.stepper(c), h('div', { class: 'desk-2' }, h('div', { class: 'agrid' }, hero, details, next), h('div', { class: 'desk-side' }, receipt))));
  }
});
</script>
@endpush
