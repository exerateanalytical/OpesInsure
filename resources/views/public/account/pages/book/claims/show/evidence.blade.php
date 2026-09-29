{{-- /account/book/claims/{id}/evidence — AGT-054 Claim Evidence Assistance (agent): what the insurer usually needs for this type of
     claim, what is already on file and its review status, so the agent can help the client complete the file. Uploads are made by the
     claimant (app or /account/claims/{id}); the agent never uploads on their behalf. GET /mobile/partner/agent/claims/{id}. --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['evidence_t'], 'lede' => $K['evidence_lede'], 'crumbs' => [[$K['claims_t'], '/account/book/claims'], [$K['evidence_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.servicing-assets')
@include('public.partials.pending-scan')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var box = O.$('[data-page-body]');
  O.loading(box);
  return S.claim(ctx.ids[0]).then(function (c) {
    var line = String(c.line_code || 'MOTOR').toUpperCase(), need = (S.t('evidence_by_line') || {})[line] || (S.t('evidence_by_line') || {}).MOTOR || [];
    var onFile = {}; (c.evidence || []).forEach(function (d) { onFile[d.role] = d; });
    var rows = need.map(function (r) {
      var d = onFile[r[0]];
      return S.row([h('b', null, r[1]), r[2] ? S.t('required') : S.t('optional'), d ? S.chip(d.status) : S.chip('MISSING'), r[3]]);
    });
    (c.evidence || []).filter(function (d) { return !need.some(function (r) { return r[0] === d.role; }); }).forEach(function (d) {
      rows.push(S.row([h('b', null, S.label(d.role)), S.t('optional'), S.chip(d.status), '—']));
    });
    var missing = need.filter(function (r) { return r[2] && !onFile[r[0]]; }).length;
    O.clear(box).append(
      h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('span', { class: 'av' }, O.icon('doc')), h('div', null, h('h2', null, c.claim_number || '—'), h('small', null, [c.customer_name, A.line(c.line_code)].filter(Boolean).join(' · ')))),
        h('div', { class: 'btns' }, S.chip(c.status), S.btn(S.t('back_claim'), S.claimUrl(c.id)))),
      h('hr', { class: 'ag-hr' }),
      h('p', null, missing ? S.t('evidence_missing', { n: missing }) : S.t('evidence_complete')),
      S.table(['th_document', 'th_need', 'th_status', 'th_guidance'], rows),
      (window.OpesPendingScan && OpesPendingScan.render(c.pending_evidence)) || null, // S4
      h('p', { class: 'sub' }, S.t('evidence_note')));
  }).catch(function (e) { A.fail(box, e); });
});
</script>
@endpush
