{{-- /account/claims-desk/{id}/payment — design: screens/compare_buy_flow/21_stage.png (Process Payment).
     Staff claims payment API (wave7): POST /claims/{id}/decisions/{d}/payments (claims.payment.request),
     /claims/{id}/payments/{p}/approve (claims.payment.approve), /processing and /paid (claims.payment.execute).
     The insurer workspace API has no claim payment endpoint, so CARRIER_STAFF sees this screen read-only. --}}
@php $K = __('account_desk'); @endphp
@extends('public.account.layout', ['title' => $K['pay_t'], 'lede' => $K['pay_lede'], 'crumbs' => [[$K['crumb_claims'], '/account/claims'], [$K['crumb_desk'], '/account/claims-desk'], [$K['crumb_claim'], '#'], [$K['crumb_pay'], null]], 'active' => 'desk'])
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
    var approved = ['APPROVED', 'PARTIALLY_APPROVED', 'PAYMENT_PENDING'].indexOf(c.status) >= 0;
    var perms = O.can('claims.payment.request') || O.can('claims.payment.approve') || O.can('claims.payment.execute');
    var live = approved && c.staffView && perms;
    var staff = c.staff || {};
    var decision = (staff.decisions || []).filter(function (d) { return d.status === 'APPROVED'; }).pop() || null;
    var payee = staff.claimant_party_id || (staff.claimant && staff.claimant.id) || null;
    var pays = c.payments || [];

    var methods = [['bank', 'bank', 'm_bank'], ['momo', 'phone', 'm_momo'], ['cheque', 'doc', 'm_cheque'], ['cash', 'piggy', 'm_cash']];
    var methodCard = h('section', { class: 'acard' }, h('h2', null, D.t('pay_method')), h('p', { class: 'sub' }, D.t('pay_method_sub')),
      h('div', { class: 'pay-methods' }, methods.map(function (m, i) { return h('label', { class: 'opt-card' }, h('input', { type: 'radio', name: 'method', value: m[0], checked: i === 0, disabled: !live }), O.icon(m[1]), h('span', null, h('b', null, D.t(m[2])), h('small', null, D.t(m[2] + '_d')))); })),
      D.note(D.t('method_note')));

    var amount = c.approved_amount_minor;
    var amt = h('input', { type: 'number', min: '1', step: '1', value: amount ? Math.round(amount / 100) : '', disabled: !live || pays.length > 0 });
    var ref = h('input', { type: 'text', maxlength: '255', placeholder: 'PAY-…', disabled: !live });
    var details = h('section', { class: 'acard' }, h('h2', null, D.t('pay_details')), h('div', { class: 'pay-grid' },
      h('label', { class: 'afield-s' }, h('span', null, D.t('pay_amount')), amt),
      h('label', { class: 'afield-s' }, h('span', null, D.t('beneficiary')), h('input', { type: 'text', value: c.customer_name || '', disabled: true })),
      h('label', { class: 'afield-s' }, h('span', null, D.t('pay_ref')), ref)));
    var docs = h('section', { class: 'acard' }, h('h2', null, D.t('pay_docs')), D.note(D.t('pay_docs_na')));

    var summary = h('section', { class: 'acard' }, h('h2', null, D.t('summary')), h('dl', { class: 'kv' },
      h('dt', null, D.t('claim_no')), h('dd', null, c.claim_number), h('dt', null, D.t('policy_no')), h('dd', null, c.policy_number || '—'),
      h('dt', null, D.t('incident_type')), h('dd', null, D.type(c.incident_type)), h('dt', null, D.t('incident_date')), h('dd', null, O.date(c.loss_occurred_at, true)),
      h('dt', null, D.t('approved_amt')), h('dd', { style: 'color:#12784A' }, D.money(amount)), h('dt', null, D.t('deductible')), h('dd', null, D.t('deductible_na')),
      h('dt', null, D.t('net_payable')), h('dd', null, D.money(amount))));
    var recipient = h('section', { class: 'acard' }, h('h2', null, D.t('recipient')), h('dl', { class: 'kv' }, h('dt', null, D.t('policyholder')), h('dd', null, c.customer_name || '—'), h('dt', null, D.t('policy_no')), h('dd', null, c.policy_number || '—')));

    var seq = h('div');
    var confirm = h('button', { type: 'button', class: 'dbtn dbtn-primary', disabled: true }, O.icon('send'), D.t('confirm_pay'));
    var approval = h('section', { class: 'acard' }, h('h2', null, D.t('pay_approval')), seq, h('div', { class: 'btnbar' }, confirm));

    if (!approved) seq.appendChild(D.note(D.t('pay_not_ready'), 'bad'));
    else if (!live) seq.appendChild(D.note(D.t('pay_na'), 'bad'));
    else paySequence();

    O.clear(box).append(h('div', { class: 'agrid' }, D.stepper(c),
      h('div', { class: 'desk-2' }, h('div', { class: 'agrid' }, D.infoCard(c), methodCard, details, docs), h('div', { class: 'desk-side' }, summary, recipient, approval))));

    function call(btn, path, body, msg) {
      return D.act(btn, function () { return O.api('/claims/' + c.id + path, { method: 'POST', body: body }); }, msg || null, function () { setTimeout(function () { location.reload(); }, 900); });
    }
    function paySequence() {
      var p = pays[pays.length - 1];
      if (!p) {
        confirm.disabled = !O.can('claims.payment.request') || !decision || !payee;
        if (!payee) seq.appendChild(D.note(D.t('payee_missing'), 'bad'));
        confirm.onclick = function () {
          var a = Math.round(+amt.value || 0); if (a <= 0) return O.alert(D.t('amount_required'), 'bad');
          call(confirm, '/decisions/' + decision.id + '/payments', { payee_party_id: payee, amount_minor: a * 100, idempotency_key: 'web-' + O.uuid() }, D.t('pay_requested'));
        };
        return;
      }
      seq.appendChild(h('ul', { class: 'pay-seq' }, pays.map(function (x) { return h('li', null, h('span', null, D.money(x.amount_minor)), D.chip(x.status)); })));
      if (p.status === 'PENDING_APPROVAL') { confirm.textContent = D.t('pay_step_approve'); confirm.disabled = !O.can('claims.payment.approve'); confirm.onclick = function () { call(confirm, '/payments/' + p.id + '/approve'); }; }
      else if (p.status === 'APPROVED' || p.status === 'RETRY_PENDING') { confirm.textContent = D.t('pay_step_process'); confirm.disabled = !O.can('claims.payment.execute'); confirm.onclick = function () { call(confirm, '/payments/' + p.id + '/processing'); }; }
      else if (p.status === 'PROCESSING') {
        confirm.textContent = D.t('pay_step_paid'); confirm.disabled = !O.can('claims.payment.execute');
        confirm.onclick = function () { var r = ref.value.trim(); if (!r) { ref.focus(); return O.alert(D.t('ext_ref_required'), 'bad'); } D.act(confirm, function () { return O.api('/claims/' + c.id + '/payments/' + p.id + '/paid', { body: { external_reference: r } }); }, null, function () { location.href = D.base(c.id) + '/paid'; }); };
      } else if (p.status === 'PAID') { confirm.replaceWith(h('a', { class: 'dbtn dbtn-primary', href: D.base(c.id) + '/paid' }, D.t('act_paid'), O.icon('arrow'))); }
    }
  }
});
</script>
@endpush
