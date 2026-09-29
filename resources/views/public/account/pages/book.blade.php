{{-- /account/book — the partner's book (UI audit 2026-09-27): quotes, proposals, policies and claims of the clients attributed to
     the signed-in agent or broker. Agent: GET /mobile/partner/agent/quotes|proposals|policies|claims. Broker: GET /mobile/partner/broker/quotes|proposals|policies|claims.
     Every list is scoped server-side to the caller's own book (PartnerWorkspaceScope::bookPartyIds). --}}
@php $K = __('account_agent'); $servicing = [['/account/book/claims', __('launch_agent_b.claims_t')], ['/account/book/requests', __('launch_agent_b.requests_t')], ['/account/book/payments', __('launch_agent_b.payments_t')]]; @endphp
@extends('public.account.layout', ['title' => $K['book_t'], 'lede' => $K['book_lede'], 'crumbs' => [[$K['book_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body>
    <div class="tabs-u" role="tablist" data-tabs></div>
    <div class="desk-tools" data-tools></div>
    <div data-rows></div>
  </section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, O = Opes, h = O.h;
  if (!A.guard(ctx)) return;
  var box = O.$('[data-rows]'), data = {}, state = { tab: 'quotes', q: '' };
  // Agent servicing detail pages (AGT-040 / AGT-053); brokers service from the broker portal, so their rows stay plain.
  var detail = function (base, id, label) { return A.mode() === 'agent' && id ? h('a', { href: base + encodeURIComponent(id) }, h('b', null, label || '—')) : h('b', null, label || '—'); };
  var client = function (r) { return r.customer_id ? h('a', { href: '/account/customers/' + encodeURIComponent(r.customer_id) }, r.customer_name || '—') : (r.customer_name || '—'); };
  var VIEWS = {
    quotes: [['th_client', 'th_line', 'th_offers', 'th_best', 'th_status', 'th_created', 'th_actions'], function (q) {
      return [client(q), A.line(q.line_code), String(q.offers || 0), h('span', { class: 'amt' }, A.money(q.best_premium_minor)), O.chip(q.status, A.label(q.status)), O.date(q.created_at), collect(q)];
    }],
    proposals: [['th_proposal', 'th_client', 'th_insurer', 'th_premium', 'th_status', 'th_submitted'], function (p) {
      return [h('b', null, p.proposal_number || '—'), client(p), (p.carrier_short_name || p.carrier_name) || '—', h('span', { class: 'amt' }, A.money(p.total_minor)), O.chip(p.status, A.label(p.status)), O.date(p.submitted_at || p.created_at)];
    }],
    policies: [['th_policy', 'th_client', 'th_insurer', 'th_premium', 'th_status', 'th_expires'], function (p) {
      return [detail('/account/book/policies/', p.id, p.policy_number), client(p), (p.carrier_short_name || p.carrier_name) || '—', h('span', { class: 'amt' }, A.money(p.premium_minor)), O.chip(p.status, A.label(p.status)), O.date(p.coverage_ends_at)];
    }],
    claims: [['th_claim', 'th_client', 'th_policy', 'th_estimate', 'th_status', 'th_loss_date'], function (c) {
      return [detail('/account/book/claims/', c.id, c.claim_number), c.customer_name || '—', c.policy_number || '—', h('span', { class: 'amt' }, A.money(c.estimated_loss_minor)), O.chip(c.status, A.label(c.status)), O.date(c.loss_occurred_at)];
    }],
  };
  // Premium collection (agents): prompt the client to pay an assisted-sale quote — POST /mobile/agent/sales/{id}/payment-request (same as the app).
  function collect(q) {
    if (A.mode() === 'broker' || !q.assisted || !O.can('agent.clients.manage') || !q.best_premium_minor) return q.payment_status ? A.label(q.payment_status) : '—';
    if (q.payment_status === 'CUSTOMER_PROMPTED' || q.payment_status === 'PAID') return O.chip(q.payment_status, A.label(q.payment_status));
    return h('button', { type: 'button', class: 'dbtn dbtn-primary sm', 'data-collect': q.id, onclick: function () {
      var btn = this; O.busy(btn, true); O.alert('');
      O.api('/mobile/agent/sales/' + encodeURIComponent(q.id) + '/payment-request', { body: {} }).then(function (s) {
        q.payment_status = s.payment_status; render(); O.alert(A.t('payment_requested', { name: q.customer_name || '' }), 'ok');
      }).catch(function (e) { O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
    } }, A.t('collect_premium'));
  }
  var LOAD = { quotes: A.quotes, proposals: A.proposals, policies: A.policies, claims: A.claims };

  function text(r) { return JSON.stringify([r.customer_name, r.policy_number, r.proposal_number, r.claim_number, (r.carrier_short_name || r.carrier_name), r.status]).toLowerCase(); }
  function render() {
    var rows = data[state.tab];
    if (rows === undefined) { O.loading(box); return; }
    if (rows instanceof Error || (rows && rows.status)) return A.fail(box, rows);
    var v = VIEWS[state.tab], shown = rows.filter(function (r) { return !state.q || text(r).indexOf(state.q) >= 0; });
    if (!rows.length) return O.empty(box, A.t('no_book'));
    if (!shown.length) return O.empty(box, A.t('no_match'));
    O.clear(box).appendChild(A.table(v[0], shown.map(function (r) { return h('tr', null, v[1](r).map(function (c) { return h('td', null, c); })); })));
  }
  function tabs() {
    A.tabs(O.$('[data-tabs]'), ['quotes', 'proposals', 'policies', 'claims'].map(function (k) { return [k, A.t('tab_' + k), data[k] && data[k].length !== undefined ? data[k].length : '…']; }), state.tab, function (k) { state.tab = k; render(); });
  }
  O.$('[data-tools]').appendChild(A.search(A.t('search_book'), function (q) { state.q = q; render(); }));
  if (A.mode() === 'agent') O.$('[data-tools]').append.apply(O.$('[data-tools]'), @json($servicing).map(function (l) { return h('a', { class: 'dbtn dbtn-outline sm', href: l[0] }, l[1]); }));
  var initial = ctx.params.get('tab'); if (VIEWS[initial]) state.tab = initial;
  tabs(); render();
  Object.keys(LOAD).forEach(function (k) {
    LOAD[k]().then(function (rows) { data[k] = rows; }, function (e) { data[k] = e || new Error(); }).then(function () { tabs(); if (k === state.tab) render(); });
  });
});
</script>
@endpush
