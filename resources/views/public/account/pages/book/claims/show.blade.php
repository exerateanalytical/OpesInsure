{{-- /account/book/claims/{id} — AGT-053 Claim Details (agent): a claim on a book policy — amounts, loss, status timeline and the
     evidence on file (AGT-054). Read-only: adjudication belongs to the insurer. GET /mobile/partner/agent/claims/{id} (book-scoped). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['claim_t'], 'lede' => $K['claim_lede'], 'crumbs' => [[$K['claims_t'], '/account/book/claims'], [$K['claim_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.servicing-assets')
@include('public.partials.pending-scan')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
  <section class="acard"><h2>{{ $K['js']['sec_timeline'] }}</h2><div data-timeline></div></section>
  <section class="acard"><h2>{{ $K['js']['sec_evidence'] }}</h2><div data-evidence></div></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var box = O.$('[data-page-body]'), tl = O.$('[data-timeline]'), ev = O.$('[data-evidence]');
  [box, tl, ev].forEach(O.loading);
  return S.claim(ctx.ids[0]).then(function (c) {
    O.clear(box).append(
      h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('span', { class: 'av' }, O.icon('shield')), h('div', null, h('h2', null, c.claim_number || '—'), h('small', null, [c.customer_name, (c.carrier_short_name || c.carrier_name)].filter(Boolean).join(' · ')))),
        h('div', { class: 'btns' }, S.chip(c.status), S.btn(S.t('evidence_help'), S.claimUrl(c.id) + '/evidence', 'dbtn-primary', 'doc'))),
      h('hr', { class: 'ag-hr' }),
      h('div', { class: 'ag-fields' },
        A.field(S.t('f_client'), S.clientLink(c.customer_id, c.customer_name)), A.field(S.t('f_policy'), h('a', { href: S.policyUrl(c.policy_id) }, c.policy_number || '—')),
        A.field(S.t('f_line'), A.line(c.line_code)), A.field(S.t('f_priority'), S.label(c.priority)),
        A.field(S.t('f_loss_date'), O.date(c.loss_occurred_at)), A.field(S.t('f_submitted'), O.date(c.submitted_at, true)), A.field(S.t('f_location'), c.loss_location),
        A.field(S.t('f_estimate'), S.money(c.estimated_loss_minor)), A.field(S.t('f_approved'), S.money(c.approved_amount_minor))),
      c.description ? h('p', null, h('b', null, S.t('f_description') + ': '), c.description) : null,
      h('p', { class: 'sub' }, S.t('claim_readonly')));
    var events = c.timeline || [];
    if (!events.length) O.empty(tl, S.t('no_events'));
    else O.clear(tl).appendChild(S.table(['th_when', 'th_event', 'th_status'], events.map(function (e) { return S.row([O.date(e.occurred_at, true), S.label(e.type), e.to_status ? S.chip(e.to_status) : '—']); })));
    var docs = c.evidence || [], held = window.OpesPendingScan && OpesPendingScan.render(c.pending_evidence); // S4
    if (!docs.length && held) O.clear(ev).appendChild(held);
    else if (!docs.length) O.empty(ev, S.t('no_evidence'), S.btn(S.t('evidence_help'), S.claimUrl(c.id) + '/evidence', 'dbtn-primary'));
    else O.clear(ev).appendChild(S.table(['th_document', 'th_status', 'th_scan', 'th_submitted', 'th_verified'], docs.map(function (d) {
      return S.row([h('b', null, S.label(d.role)), S.chip(d.status), S.label(d.scan_status), O.date(d.submitted_at), O.date(d.verified_at)]);
    })));
    if (docs.length && held) ev.appendChild(held);
  }).catch(function (e) { A.fail(box, e); O.clear(tl); O.clear(ev); });
});
</script>
@endpush
