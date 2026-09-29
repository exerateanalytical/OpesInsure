{{-- /account/book/policies/{id}/documents — AGT-041 Policy Documents (agent): issued documents of one book policy that an
     intermediary may see (DocumentAccessPolicy::intermediaryMay), with signed download links.
     GET /mobile/partner/agent/policies/{id} + /mobile/partner/agent/clients/{customer}/documents (filtered by policy). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['documents_t'], 'lede' => $K['documents_lede'], 'crumbs' => [[__('account_agent.book_t'), '/account/book?tab=policies'], [$K['documents_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.servicing-assets')
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
  return S.policy(ctx.ids[0]).then(function (p) {
    var head = S.policyHead(p), list = h('div');
    O.clear(box).append(head, h('hr', { class: 'ag-hr' }), list);
    if (!p.customer_id) return O.empty(list, S.t('no_documents'));
    O.loading(list);
    return A.clientDocuments(p.customer_id).then(function (rows) {
      rows = rows.filter(function (d) { return d.policy_id === p.id; });
      if (!rows.length) return O.empty(list, S.t('no_documents'));
      O.clear(list).appendChild(S.table(['th_document', 'th_number', 'th_status', 'th_issued', 'th_actions'], rows.map(function (d) {
        return S.row([h('b', null, (O.locale === 'fr' && d.title_fr) || d.title || '—'), d.document_number || '—', S.chip(d.status), O.date(d.issued_at),
          d.download_url ? h('a', { class: 'dbtn dbtn-outline sm', href: d.download_url, target: '_blank', rel: 'noopener' }, O.icon('download'), S.t('download')) : S.t('not_current')]);
      })));
    }).catch(function (e) { A.fail(list, e); });
  }).catch(function (e) { A.fail(box, e); });
});
</script>
@endpush
