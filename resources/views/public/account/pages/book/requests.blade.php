{{-- /account/book/requests — AGT-043 Endorsement Tracking (agent): every service request, endorsement and cancellation on the
     policies of the agent's book, newest first. GET /mobile/partner/agent/service-requests (book-scoped). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['requests_t'], 'lede' => $K['requests_lede'], 'crumbs' => [[__('account_agent.book_t'), '/account/book'], [$K['requests_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
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
  var box = O.$('[data-rows]'), all = [], q = '', open = ['REQUESTED', 'PAYMENT_PENDING', 'PENDING_APPROVAL'];
  function render() {
    var rows = all.filter(function (t) { return !q || JSON.stringify([t.transaction_number, t.policy_number, t.type, t.status, t.reason]).toLowerCase().indexOf(q) >= 0; });
    if (!all.length) return O.empty(box, S.t('no_requests'));
    if (!rows.length) return O.empty(box, S.t('no_match'));
    O.clear(box).appendChild(S.table(['th_reference', 'th_policy', 'th_type', 'th_status', 'th_reason', 'th_created', 'th_updated'], rows.map(function (t) {
      return S.row([h('a', { href: '/account/book/requests/' + S.enc(t.id) }, h('b', null, t.transaction_number || '—')), h('a', { href: S.policyUrl(t.policy_id) }, t.policy_number || '—'),
        S.label(t.type), S.chip(t.status), t.reason || '—', O.date(t.created_at), O.date(t.updated_at)]);
    })));
  }
  O.$('[data-tools]').appendChild(A.search(S.t('search_requests'), function (v) { q = v; render(); }));
  O.loading(box);
  return S.requests().then(function (rows) {
    all = rows;
    var pending = rows.filter(function (t) { return open.indexOf(t.status) >= 0; }).length;
    O.$('[data-tools]').appendChild(h('small', { class: 'sub' }, S.t('requests_open', { n: pending, t: rows.length })));
    render();
  }).catch(function (e) { A.fail(box, e); });
});
</script>
@endpush
