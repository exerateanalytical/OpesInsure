{{-- /account/claims-desk/{id} — design: screens/compare_buy_flow/24_stage.png (Claim Details, officer view). --}}
@php $K = __('account_desk'); @endphp
@extends('public.account.layout', ['title' => $K['show_t'], 'lede' => $K['show_lede'], 'crumbs' => [[$K['crumb_claims'], '/account/claims'], [$K['crumb_desk'], '/account/claims-desk'], [$K['crumb_claim'], null]], 'active' => 'desk'])
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
    var crumb = O.$('.crumbs [aria-current]'); if (crumb) crumb.textContent = c.claim_number;
    var days = c.loss_occurred_at ? Math.floor((Date.now() - new Date(c.loss_occurred_at)) / 864e5) : null;
    var strip = h('div', { class: 'desk-strip' },
      h('div', null, O.icon('doc', 'big'), h('div', null, h('small', null, D.t('claim_no')), h('b', null, c.claim_number), h('small', null, c.policy_number || ''))),
      h('div', null, O.icon('clock', 'big'), h('div', null, h('small', null, D.t('incident_date')), h('b', null, O.date(c.loss_occurred_at, true)), h('small', null, days === null ? '' : days < 1 ? D.t('today') : D.t('days_ago', { n: days })))),
      h('div', null, O.icon('piggy', 'big'), h('div', null, h('small', null, c.approved_amount_minor !== null ? D.t('approved_amt') : D.t('claimed')), h('b', null, D.money(c.approved_amount_minor !== null ? c.approved_amount_minor : c.estimated_loss_minor)), h('small', null, D.t('claimed_sub')))),
      h('div', null, O.icon('check', 'big'), h('div', null, h('small', null, D.t('status')), D.chip(c.status), h('small', null, D.t('step_cur')))));

    var ld = c.loss || {}, inc = ld.incident || {};
    var policy = D.card(D.t('policy_cust'), 'doc', h('div', { class: 'desk-fields c2' },
      D.field(D.t('policy_no'), c.policy_number), D.field(D.t('policyholder'), c.customer_name),
      D.field(D.t('insurer'), c.carrier_name), D.field(D.t('priority'), D.type(c.priority)),
      D.field(D.t('currency'), c.currency), D.field(D.t('status'), D.chip(c.status))));
    var veh = ld.vehicle || (c.staff && c.staff.policy && c.staff.policy.risk_details && c.staff.policy.risk_details.vehicle) || null;
    var vehicle = D.card(D.t('vehicle'), 'motor', veh ? h('div', { class: 'desk-fields c2' }, Object.keys(veh).slice(0, 8).map(function (k) { return D.field(D.type(k), String(veh[k])); })) : D.note(D.t('vehicle_na')));
    var incident = D.card(D.t('incident'), 'pin', h('div', { class: 'desk-fields c2' },
      D.field(D.t('incident_type'), D.type(c.incident_type)), D.field(D.t('incident_date'), O.date(c.loss_occurred_at, true)),
      D.field(D.t('location'), c.loss_location, 'span2'),
      inc.police_report_number ? D.field(D.t('police_report'), inc.police_report_number) : null,
      inc.vehicle_drivable !== undefined ? D.field(D.t('drivable'), inc.vehicle_drivable ? D.t('yes') : D.t('no')) : null,
      D.field(D.t('description'), c.description, 'span2')));

    var docs = D.card(D.t('docs'), 'doc', D.note(D.t('docs_na')));
    var assess = D.card(D.t('assess_sum'), 'scale', assessBody(c));
    var progress = D.card(D.t('progress'), 'clock', D.timeline(c));
    var payments = D.card(D.t('payments'), 'card', payBody(c));

    var panels = {
      overview: h('div', { class: 'agrid' }, h('div', { class: 'desk-3' }, policy, vehicle, incident), h('div', { class: 'desk-3' }, progress, docs, assess)),
      documents: D.card(D.t('docs'), 'doc', D.note(D.t('docs_na'))),
      assessments: D.card(D.t('assess_sum'), 'scale', assessBody(c)),
      payments: payments,
      timeline: D.card(D.t('progress'), 'clock', D.timeline(c)),
    };
    var tabs = h('div', { class: 'tabs-u acard', role: 'tablist' });
    var host = h('div');
    [['overview', 't_overview'], ['documents', 't_documents'], ['assessments', 't_assess'], ['payments', 't_payments'], ['timeline', 't_timeline']].forEach(function (t, i) {
      tabs.appendChild(h('button', { type: 'button', role: 'tab', 'aria-selected': String(i === 0), onclick: function () { O.$$('[role=tab]', tabs).forEach(function (b) { b.setAttribute('aria-selected', 'false'); }); this.setAttribute('aria-selected', 'true'); O.clear(host).appendChild(panels[t[0]]); } }, D.t(t[1])));
    });
    host.appendChild(panels.overview);
    O.clear(box).append(h('div', { class: 'agrid' }, strip, tabs, host, actions(c)));
  }

  function assessBody(c) {
    var p = c.pending_decision;
    if (p) return h('div', null, h('p', { class: 'sub' }, D.t('pending_dec')), h('div', { class: 'desk-fields c2' },
      D.field(D.t('decision'), D.t('d_' + p.decision)), D.field(D.t('approved_amt'), D.money(p.approved_amount_minor)),
      D.field(D.t('reason'), ((window.DESK_T.reasons || {})[p.reason_code]) || D.type(p.reason_code)), D.field(D.t('proposed_at'), O.date(p.proposed_at, true) + (p.proposed_by_me ? ' · ' + D.t('proposed_by_me') : '')),
      D.field(D.t('notes'), p.rationale, 'span2')));
    if (c.assess && (c.assess.assessments || []).length) {
      var a = c.assess.assessments[c.assess.assessments.length - 1];
      return h('div', { class: 'desk-fields c2' }, D.field(D.t('status'), D.chip(a.status)), D.field(D.t('est_cost'), D.money(a.recommended_total_minor !== undefined ? a.recommended_total_minor : null)), D.field(D.t('notes'), a.rationale, 'span2'));
    }
    if (c.approved_amount_minor !== null) return h('div', { class: 'desk-fields c2' }, D.field(D.t('approved_amt'), D.money(c.approved_amount_minor), 'money ok'), D.field(D.t('status'), D.chip(c.status)));
    return h('div', null, h('p', { class: 'sub' }, D.t('no_assess')), c.staffView ? null : D.note(D.t('assess_na')));
  }
  function payBody(c) {
    if (!c.staffView) return D.note(D.t('payments_na'));
    if (!c.payments || !c.payments.length) return h('p', { class: 'sub' }, D.t('no_payments'));
    return h('ul', { class: 'pay-seq' }, c.payments.map(function (p) { return h('li', null, h('span', null, D.money(p.amount_minor), ' · ', p.external_reference || ''), D.chip(p.status)); }));
  }

  function actions(c) {
    var A = c.actions || [], base = D.base(c.id), bar = h('div', { class: 'desk-actions no-print' });
    var canPropose = A.indexOf('propose_decision') >= 0;
    var reject = h('a', { class: 'dbtn danger', href: base + '/approve?decision=DECLINE' }, O.icon('x'), D.t('act_reject'));
    if (!canPropose) { reject = h('button', { class: 'dbtn danger', type: 'button', disabled: true, title: D.t('not_supported') }, O.icon('x'), D.t('act_reject')); }
    var info = h('button', { class: 'dbtn warn', type: 'button', disabled: A.indexOf('request_information') < 0, title: A.indexOf('request_information') < 0 ? D.t('info_na') : null, onclick: function () {
      var btn = this;
      D.prompt(D.t('info_prompt'), D.t('info_ph')).then(function (note) {
        if (!note) return;
        D.act(btn, function () { return O.api('/mobile/partner/carrier/claims/' + c.id + '/request-information', { body: { note: note } }); }, D.t('info_sent'), function () { setTimeout(function () { location.reload(); }, 900); });
      });
    } }, O.icon('mail'), D.t('act_info'));
    bar.append(reject, info);
    if (A.indexOf('acknowledge') >= 0) bar.appendChild(h('button', { class: 'dbtn dbtn-outline', type: 'button', onclick: function () { D.act(this, function () { return O.api('/mobile/partner/carrier/claims/' + c.id + '/acknowledge', { method: 'POST' }); }, D.t('acked'), function () { setTimeout(function () { location.reload(); }, 900); }); } }, O.icon('check'), D.t('act_ack')));
    if (canPropose && !c.pending_decision) bar.appendChild(h('a', { class: 'dbtn good', href: base + '/assess' }, O.icon('scale'), D.t('act_assess')));
    if (c.pending_decision) bar.appendChild(h('a', { class: 'dbtn good', href: base + '/approve' }, O.icon('check'), D.t('act_approve')));
    // "Next stage" follows the claim's real status to the screen that handles it.
    var s = c.status, next = null;
    if (canPropose && !c.pending_decision) next = base + '/assess';
    else if (s === 'CARRIER_REVIEW') next = base + '/approve';
    else if (s === 'APPROVED' || s === 'PARTIALLY_APPROVED' || s === 'PAYMENT_PENDING') next = base + '/payment';
    else if (s === 'PAID' || s === 'CLOSED' || s === 'SETTLED') next = base + '/paid';
    bar.appendChild(next ? h('a', { class: 'dbtn dbtn-primary', href: next }, D.t('act_next'), O.icon('arrow')) : h('button', { class: 'dbtn dbtn-primary', type: 'button', disabled: true, title: D.t('no_next') }, D.t('act_next'), O.icon('arrow')));
    return bar;
  }
});
</script>
@endpush
