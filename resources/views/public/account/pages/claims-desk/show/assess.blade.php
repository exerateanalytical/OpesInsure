{{-- /account/claims-desk/{id}/assess — design: screens/compare_buy_flow/19_stage.png (Assess Claim).
     Submit = POST /mobile/partner/carrier/claims/{id}/decisions (recommendation awaiting a second approver),
     or POST .../request-information; staff with claims.assessment.record record POST /claims/{id}/assessments. --}}
@php $K = __('account_desk'); @endphp
@extends('public.account.layout', ['title' => $K['assess_t'], 'lede' => $K['assess_lede'], 'crumbs' => [[$K['crumb_claims'], '/account/claims'], [$K['crumb_desk'], '/account/claims-desk'], [$K['crumb_claim'], '#'], [$K['crumb_assess'], null]], 'active' => 'desk'])
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
    var A = c.actions || [], canPropose = A.indexOf('propose_decision') >= 0 && !c.pending_decision, canInfo = A.indexOf('request_information') >= 0;
    var canStaff = O.can('claims.assessment.record');
    var open = canPropose || canInfo || canStaff;

    // Damage assessment
    var desc = h('textarea', { maxlength: '1000', required: true, placeholder: c.description || '' });
    if (c.description) desc.value = '';
    var count = h('small', { class: 'sub' }, '0/1,000');
    desc.addEventListener('input', function () { count.textContent = desc.value.length + '/1,000'; });
    var damage = D.card(D.t('damage'), 'motor', h('div', null, h('p', { class: 'sub' }, D.t('damage_sub')),
      c.description ? D.field(D.t('description'), c.description) : null,
      h('div', { style: 'margin-top:12px' }, h('small', { class: 'sub' }, D.t('photos')), D.docs(c, true)),
      D.itemFields(c),
      h('label', { class: 'afield-s', style: 'margin-top:12px' }, h('span', null, D.t('damage_desc'), ' ', h('i', null, '*')), desc, count)));

    // Repair estimate lines (entered here; sent as the decision amount + rationale)
    var tbody = h('tbody'), total = h('b', null, D.money(0));
    function addLine(label, amt) {
      var tr = h('tr', null,
        h('td', null, h('input', { type: 'text', maxlength: '80', value: label || '', 'aria-label': D.t('item'), oninput: sum })),
        h('td', null, h('input', { type: 'number', min: '0', step: '1', inputmode: 'numeric', value: amt || '', 'aria-label': D.t('item_cost'), oninput: sum })),
        h('td', null, h('button', { type: 'button', class: 'rm', 'aria-label': D.t('remove'), onclick: function () { tr.remove(); sum(); } }, O.icon('trash'))));
      tbody.appendChild(tr);
    }
    function lines() { return O.$$('tr', tbody).map(function (tr) { var i = O.$$('input', tr); return { label: i[0].value.trim(), amt: Math.max(0, Math.round(+i[1].value || 0)) }; }).filter(function (l) { return l.label || l.amt; }); }
    function sum() { var t = lines().reduce(function (a, l) { return a + l.amt; }, 0); total.textContent = O.money(t); return t; }
    addLine('', '');
    var estimate = D.card(D.t('estimate'), 'edit', h('div', null, h('p', { class: 'sub' }, D.t('estimate_sub')),
      h('div', { class: 'atable-wrap' }, h('table', { class: 'est-table' }, h('thead', null, h('tr', null, h('th', null, D.t('item')), h('th', null, D.t('item_cost')), h('th', null, ''))), tbody)),
      h('button', { type: 'button', class: 'dbtn dbtn-outline sm add-line', onclick: function () { addLine(); } }, '+ ' + D.t('add_item')),
      h('div', { class: 'est-total' }, h('span', null, D.t('total_est')), total),
      c.estimated_loss_minor !== null ? D.note(D.t('est_cost') + ': ' + D.money(c.estimated_loss_minor)) : null));

    // Side: status, checklist, recommendation
    var status = h('section', { class: 'acard' }, h('h2', null, D.t('claim_status'), D.chip(c.status)), c.pending_decision ? D.note(D.t('pending_dec')) : null);
    var chk = h('ul', { class: 'chk' }, (window.DESK_T.chk || []).map(function (t) { return h('li', null, h('label', null, h('input', { type: 'checkbox' }), t)); }));
    var checklist = h('section', { class: 'acard' }, h('h2', null, D.t('checklist')), chk, D.note(D.t('chk_note')));
    var recs = [['APPROVE', 'rec_approve', canPropose || canStaff], ['PARTIAL', 'rec_partial', canPropose || canStaff], ['INFO', 'rec_info', canInfo], ['DECLINE', 'rec_reject', canPropose]];
    var reason = h('select', { 'aria-label': D.t('reason_code') });
    var recBox = h('div', { class: 'recs' }, recs.map(function (r, i) {
      return h('label', { class: 'opt-card' }, h('input', { type: 'radio', name: 'rec', value: r[0], disabled: !r[2], checked: false, onchange: function () { O.clear(reason).append.apply(reason, D.reasonSelect(r[0])); reason.parentNode.hidden = r[0] === 'INFO'; } }),
        h('span', null, h('b', null, D.t(r[1])), h('small', null, D.t(r[1] + '_d'))));
    }));
    var reasonWrap = h('label', { class: 'afield-s', hidden: true }, h('span', null, D.t('reason_code')), reason);
    var submit = h('button', { type: 'button', class: 'dbtn dbtn-primary', disabled: !open, onclick: go }, D.t('submit_approval'), O.icon('arrow'));
    var rec = h('section', { class: 'acard' }, h('h2', null, D.t('recommended')), recBox, reasonWrap, open ? null : D.note(D.t('assess_closed'), 'bad'), h('div', { class: 'btnbar' }, submit));

    O.clear(box).append(h('div', { class: 'agrid' }, D.stepper(c),
      h('div', { class: 'desk-2' }, h('div', { class: 'agrid' }, D.infoCard(c), h('div', { class: 'agrid c2' }, damage, estimate)), h('div', { class: 'desk-side' }, status, checklist, rec))));

    function go() {
      var pick = O.$('input[name=rec]:checked', recBox), note = desc.value.trim();
      if (!pick) return O.alert(D.t('recommended'), 'bad');
      if (note.length < 5) { desc.focus(); return O.alert(D.t('desc_required'), 'bad'); }
      var v = pick.value, t = sum(), L = lines();
      if (v === 'INFO') return D.act(submit, function () { return O.api('/mobile/partner/carrier/claims/' + c.id + '/request-information', { body: { note: note } }); }, D.t('info_sent'), function () { setTimeout(function () { location.href = D.base(c.id); }, 1000); });
      if (v !== 'DECLINE' && t <= 0) return O.alert(D.t('amount_required'), 'bad');
      var rationale = note + (L.length ? '\n\n' + D.t('rationale_lines') + ':\n' + L.map(function (l) { return '- ' + (l.label || D.t('item')) + ': ' + O.money(l.amt); }).join('\n') + '\n' + D.t('total_est') + ': ' + O.money(t) : '');
      if (canPropose) {
        return D.act(submit, function () {
          return O.api('/mobile/partner/carrier/claims/' + c.id + '/decisions', { body: { decision: v, approved_amount_minor: v === 'DECLINE' ? null : t * 100, reason_code: reason.value, rationale: rationale.slice(0, 4000) } });
        }, D.t('submitted_ok'), function () { setTimeout(function () { location.href = D.base(c.id) + '/approve'; }, 1000); });
      }
      // Staff assessment record (claims.assessment.record): one head per estimate line.
      return D.act(submit, function () {
        return O.api('/claims/' + c.id + '/assessments', { body: { rationale: rationale.slice(0, 10000), heads: L.map(function (l, i) { return { head_code: (l.label || 'ITEM_' + (i + 1)).toUpperCase().replace(/[^A-Z0-9]+/g, '_').slice(0, 64), recommended_minor: l.amt * 100, note: l.label || null }; }) } });
      }, D.t('submitted_ok'), function () { setTimeout(function () { location.href = D.base(c.id); }, 1000); });
    }
  }
});
</script>
@endpush
