{{-- /account/book/claims — AGT-051 Claims Portfolio (agent): claims on the policies of the agent's book with status totals, a status
     filter and search; rows open the claim detail. GET /mobile/partner/agent/claims (book-scoped). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['claims_t'], 'lede' => $K['claims_lede'], 'crumbs' => [[__('account_agent.book_t'), '/account/book'], [$K['claims_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
  <div class="desk-stats ag-stats4" data-stats></div>
  <section class="acard" data-page-body>
    <div class="desk-tools" data-tools></div>
    <div data-rows></div>
  </section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var box = O.$('[data-rows]'), all = [], q = '', st = ctx.params.get('status') || '';
  var CLOSED = ['CLOSED', 'PAID', 'REJECTED', 'WITHDRAWN', 'SETTLED', 'DECLINED'];
  function render() {
    var rows = all.filter(function (c) {
      return (!st || (st === 'OPEN' ? CLOSED.indexOf(c.status) < 0 : c.status === st)) && (!q || JSON.stringify([c.claim_number, c.customer_name, c.policy_number, (c.carrier_short_name || c.carrier_name)]).toLowerCase().indexOf(q) >= 0);
    });
    if (!all.length) return O.empty(box, S.t('no_claims'), A.canFileClaim() ? S.btn(S.t('start_claim'), '/account/customers', 'dbtn-primary') : null);
    if (!rows.length) return O.empty(box, S.t('no_match'));
    O.clear(box).appendChild(S.table(['th_claim', 'th_client', 'th_policy', 'th_insurer', 'th_estimate', 'th_approved', 'th_status', 'th_submitted'], rows.map(function (c) {
      return S.row([h('a', { href: S.claimUrl(c.id) }, h('b', null, c.claim_number || '—')), c.customer_name || '—', h('a', { href: S.policyUrl(c.policy_id) }, c.policy_number || '—'), (c.carrier_short_name || c.carrier_name) || '—',
        h('span', { class: 'amt' }, S.money(c.estimated_loss_minor)), h('span', { class: 'amt' }, S.money(c.approved_amount_minor)), S.chip(c.status), O.date(c.submitted_at)]);
    })));
  }
  O.loading(box);
  return A.claims().then(function (rows) {
    all = rows;
    var open = rows.filter(function (c) { return CLOSED.indexOf(c.status) < 0; }).length, sum = function (k) { return rows.reduce(function (s, c) { return s + (c[k] || 0); }, 0); };
    A.stats(O.$('[data-stats]'), [A.stat('b', 'shield', S.t('stat_claims'), rows.length), A.stat('o', 'clock', S.t('stat_open'), open),
      A.stat('p', 'scale', S.t('stat_estimated'), S.money(sum('estimated_loss_minor'))), A.stat('g', 'check', S.t('stat_approved'), S.money(sum('approved_amount_minor')))]);
    var statuses = rows.map(function (c) { return c.status; }).filter(function (s, i, a) { return a.indexOf(s) === i; });
    var sel = h('select', { 'aria-label': S.t('filter'), onchange: function () { st = this.value; render(); } }, h('option', { value: '' }, S.t('flt_all')), h('option', { value: 'OPEN' }, S.t('flt_open')),
      statuses.map(function (s) { return h('option', { value: s }, S.label(s)); }));
    sel.value = st;
    O.$('[data-tools]').append(A.search(S.t('search_claims'), function (v) { q = v; render(); }), sel);
    render();
  }).catch(function (e) { A.fail(box, e); });
});
</script>
@endpush
