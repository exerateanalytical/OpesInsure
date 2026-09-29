{{-- /account/agent/quotes/{id} — agent quote workspace: the client's quote and its priced offers (GET /quotes/{id}, book-scoped: another
     agent's client answers 403), with the next steps: AGT-024 compare (/account/quotes/{id}), AGT-022 configure coverage
     (/account/quotes/{id}/customize), AGT-029 build the proposal (/account/quotes/{id}/review), AGT-026 send, AGT-028 mark lost. --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['js']['b_quote'], 'lede' => $K['send_lede'], 'crumbs' => [[$K['dash_t'], '/account/agent'], [$K['js']['b_quote'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid"><section class="acard" data-page-body></section><section class="acard" data-offers></section></div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var id = ctx.ids[0], box = $('[data-page-body]'), ob = $('[data-offers]'), CLOSED = ['DECLINED', 'CANCELLED', 'EXPIRED', 'LOST', 'CONVERTED'];
  O.loading(box); O.loading(ob);
  O.api('/quotes/' + encodeURIComponent(id)).then(function (r) {
    var q = r.quote || {}, offers = r.offers || [], st = String(q.lifecycle_state || q.status || '').toUpperCase(), open = CLOSED.indexOf(st) < 0, e = encodeURIComponent(id);
    O.clear(box).append(h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('div', null, h('h2', null, (q.quote_number || '') + ' · ' + A.line(q.line_code)), h('small', null, O.date(q.created_at) + ' → ' + O.date(q.expires_at)))),
      h('div', { class: 'btns', style: 'display:flex;flex-wrap:wrap;gap:6px' }, O.chip(st, A.label(st)),
        offers.length ? L.link(L.t('q_compare'), '/account/quotes/' + e, null, 'compare') : null,
        open && offers.length ? L.link(L.t('q_customize'), '/account/quotes/' + e + '/customize', null, 'edit') : null,
        open && offers.length ? L.link(L.t('q_builder'), '/account/quotes/' + e + '/review', 'dbtn-primary', 'list') : null,
        open && O.can('quotes.send') ? L.link(L.t('q_send'), '/account/agent/quotes/' + e + '/send', null, 'arrow') : null,
        open && O.can('quotes.manage') ? L.link(L.t('q_lost'), '/account/agent/quotes/' + e + '/lost', null, 'x') : null)),
      !open ? h('p', { class: 'sub' }, L.t('q_closed')) : null);
    O.clear(ob).appendChild(h('h2', null, L.t('q_offers')));
    if (!offers.length) { var x = h('div'); ob.appendChild(x); O.empty(x, L.t('none')); return; }
    ob.appendChild(L.table(['insurer', 'product', 'premium', 'status'], offers.map(function (o) {
      return h('tr', null, h('td', null, h('b', null, ((o.carrier || {}).party || {}).display_name || '—'), o.comparison_rank === 1 ? h('span', { class: 'st st-ok' }, L.t('q_best')) : null),
        h('td', null, L.tr((o.product || {}).name) || '—'), h('td', { class: 'amt' }, A.money(o.total_minor)), h('td', null, O.chip(o.status, A.label(o.status))));
    })));
  }).catch(function (e) { A.fail(box, e); O.clear(ob); });
});
</script>
@endpush
