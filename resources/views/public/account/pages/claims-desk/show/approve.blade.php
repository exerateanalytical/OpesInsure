{{-- /account/claims-desk/{id}/approve — design: screens/compare_buy_flow/20_stage.png (Approve Claim).
     Approve = POST /mobile/partner/carrier/claims/{id}/decisions/{decision}/approve (carrier.authority.approve, maker-checker);
     no proposal yet = POST .../decisions; request info = POST .../request-information. --}}
@php $K = __('account_desk'); @endphp
@extends('public.account.layout', ['title' => $K['approve_t'], 'lede' => $K['approve_lede'], 'crumbs' => [[$K['crumb_claims'], '/account/claims'], [$K['crumb_desk'], '/account/claims-desk'], [$K['crumb_claim'], '#'], [$K['crumb_approve'], null]], 'active' => 'desk'])
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
    var A = c.actions || [], p = c.pending_decision;
    var amount = p ? p.approved_amount_minor : c.approved_amount_minor;

    var sum = D.card(D.t('assess_sum'), 'scale', h('table', { class: 'sum-table' }, h('tbody', null,
      h('tr', null, h('td', null, D.t('cov_valid')), h('td', null, c.policy_number || '—')),
      h('tr', null, h('td', null, D.t('incident_type')), h('td', null, D.type(c.incident_type))),
      h('tr', null, h('td', null, D.t('est_cost')), h('td', null, D.money(c.estimated_loss_minor))),
      p ? h('tr', null, h('td', null, D.t('decision')), h('td', null, D.t('d_' + p.decision))) : null,
      p ? h('tr', null, h('td', null, D.t('reason')), h('td', null, ((window.DESK_T.reasons || {})[p.reason_code]) || D.type(p.reason_code))) : null,
      h('tr', null, h('td', null, D.t('deductible')), h('td', null, D.t('deductible_na'))),
      h('tr', { class: 'tot' }, h('td', null, D.t('net_payable')), h('td', null, amount !== null && amount !== undefined ? D.money(amount) : '—')))));
    var est = D.card(D.t('estimate'), 'edit', p && p.rationale ? h('pre', { class: 'pd-box', style: 'white-space:pre-wrap;font:inherit;font-size:13.5px;margin:0' }, p.rationale) : h('p', { class: 'sub' }, D.t('no_assess')));
    var docs = D.card(D.t('docs'), 'doc', D.note(D.t('docs_na')));
    var hist = D.card(D.t('history'), 'clock', D.timeline(c));

    O.clear(box).append(h('div', { class: 'agrid' }, D.stepper(c),
      h('div', { class: 'desk-2' }, h('div', { class: 'agrid' }, D.infoCard(c), h('div', { class: 'agrid c2' }, sum, docs), hist), h('div', { class: 'desk-side' }, est, decision(c)))));
  }

  function decision(c) {
    var A = c.actions || [], p = c.pending_decision, base = D.base(c.id);
    var card = h('section', { class: 'acard' }, h('h2', null, D.t('approval')));
    if (p) {
      var can = A.indexOf('approve_decision') >= 0;
      var btn = h('button', { type: 'button', class: 'dbtn dbtn-primary', disabled: !can, onclick: function () {
        D.act(btn, function () { return O.api('/mobile/partner/carrier/claims/' + c.id + '/decisions/' + p.id + '/approve', { method: 'POST' }); }, D.t('approved_ok'), function (r) {
          var s = r && r.status; setTimeout(function () { location.href = base + (s === 'APPROVED' || s === 'PARTIALLY_APPROVED' ? '/payment' : ''); }, 1000);
        });
      } }, D.t('approve_btn'), O.icon('arrow'));
      card.append(h('div', { class: 'pd-box' }, h('div', { class: 'desk-fields c2' }, D.field(D.t('decision'), D.t('d_' + p.decision)), D.field(D.t('approved_amt'), D.money(p.approved_amount_minor)), D.field(D.t('proposed_at'), O.date(p.proposed_at, true)))),
        can ? null : D.note(p.proposed_by_me ? D.t('appr_self') : D.t('appr_noauth'), 'bad'),
        D.note(D.t('return_na')),
        h('div', { class: 'btnbar' }, btn));
      return card;
    }
    var canPropose = A.indexOf('propose_decision') >= 0, canInfo = A.indexOf('request_information') >= 0;
    if (!canPropose && !canInfo) { card.append(D.note(D.t('no_pending'))); if (c.status === 'APPROVED' || c.status === 'PARTIALLY_APPROVED') card.append(h('div', { class: 'btnbar' }, h('a', { class: 'dbtn dbtn-primary', href: base + '/payment' }, D.t('act_pay'), O.icon('arrow')))); return card; }
    var pre = ctx.params.get('decision');
    var reason = h('select', { 'aria-label': D.t('reason_code') }), amt = h('input', { type: 'number', min: '1', step: '1', inputmode: 'numeric', value: c.estimated_loss_minor ? Math.round(c.estimated_loss_minor / 100) : '' });
    var amtWrap = h('label', { class: 'afield-s' }, h('span', null, D.t('amount')), amt), reasonWrap = h('label', { class: 'afield-s' }, h('span', null, D.t('reason_code')), reason);
    var notes = h('textarea', { maxlength: '4000', required: true });
    var opts = [['APPROVE', 'rec_approve', canPropose], ['PARTIAL', 'rec_partial', canPropose], ['INFO', 'rec_info', canInfo], ['DECLINE', 'rec_reject', canPropose]];
    function sync(v) { O.clear(reason).append.apply(reason, D.reasonSelect(v)); amtWrap.hidden = v === 'DECLINE' || v === 'INFO'; reasonWrap.hidden = v === 'INFO'; }
    var recBox = h('div', { class: 'recs' }, opts.map(function (o) {
      var r = h('input', { type: 'radio', name: 'dec', value: o[0], disabled: !o[2], onchange: function () { sync(o[0]); } });
      if (pre === o[0] && o[2]) r.checked = true;
      return h('label', { class: 'opt-card' }, r, h('span', null, h('b', null, D.t(o[1])), h('small', null, D.t(o[1] + '_d'))));
    }));
    var first = O.$('input[name=dec]:checked', recBox) || O.$$('input[name=dec]', recBox).filter(function (x) { return !x.disabled; })[0];
    if (first) { first.checked = true; sync(first.value); }
    var btn = h('button', { type: 'button', class: 'dbtn dbtn-primary', onclick: function () {
      var v = (O.$('input[name=dec]:checked', recBox) || {}).value, note = notes.value.trim();
      if (note.length < 5) { notes.focus(); return O.alert(D.t('desc_required'), 'bad'); }
      if (v === 'INFO') return D.act(btn, function () { return O.api('/mobile/partner/carrier/claims/' + c.id + '/request-information', { body: { note: note } }); }, D.t('info_sent'), function () { setTimeout(function () { location.href = base; }, 1000); });
      var a = Math.round(+amt.value || 0);
      if (v !== 'DECLINE' && a <= 0) { amt.focus(); return O.alert(D.t('amount_required'), 'bad'); }
      D.act(btn, function () { return O.api('/mobile/partner/carrier/claims/' + c.id + '/decisions', { body: { decision: v, approved_amount_minor: v === 'DECLINE' ? null : a * 100, reason_code: reason.value, rationale: note } }); }, D.t('submitted_ok'), function () { setTimeout(function () { location.reload(); }, 1000); });
    } }, D.t('propose_btn'), O.icon('arrow'));
    card.append(D.note(D.t('propose_here')), recBox, amtWrap, reasonWrap, h('label', { class: 'afield-s' }, h('span', null, D.t('appr_notes'), ' ', h('i', null, '*')), notes), h('div', { class: 'btnbar' }, btn));
    return card;
  }
});
</script>
@endpush
