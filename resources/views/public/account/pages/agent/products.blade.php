{{-- /account/agent/products[?product=] — AGT-018 Product Details. What the agent may sell: GET /distribution/catalogue?include_blocked=1
     (same call as the app's agent catalogue: sellability, reasons, commission) and the chosen product's covers and exclusions
     (GET /catalogue/products/{id}). "Quote this product" opens /account/buy with the product preselected. --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['products_t'], 'lede' => $K['products_lede'], 'crumbs' => [[$K['dash_t'], '/account/agent'], [$K['products_t'], null]], 'active' => 'dashboard'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <div class="desk-main">
    <section class="acard" data-page-body>
      <div class="desk-tools" data-tools></div>
      <div data-rows></div>
    </section>
    <aside class="desk-side"><section class="acard" data-detail></section></aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var box = $('[data-rows]'), det = $('[data-detail]'), all = [], q = '', customer = ctx.params.get('customer');
  $('[data-tools]').appendChild(A.search(L.t('p_search'), function (v) { q = v; render(); }));
  O.empty(det, L.t('p_pick'));
  O.loading(box);
  O.api('/distribution/catalogue', { query: { include_blocked: 1, line_code: ctx.params.get('line') ? ctx.params.get('line').toUpperCase() : undefined } }).then(function (rows) {
    all = (rows && rows.items) || rows || [];
    render();
    if (ctx.params.get('product')) show(ctx.params.get('product'));
  }).catch(function (e) { A.fail(box, e); });

  function render() {
    var rows = all.filter(function (p) { return !q || [L.tr(p.name), p.code, (p.carrier_short_name || p.carrier_name), p.line_code].join(' ').toLowerCase().indexOf(q) >= 0; })
      .sort(function (a, b) { return Number(b.sellable) - Number(a.sellable) || String(a.line_code).localeCompare(String(b.line_code)); });
    if (!rows.length) return O.empty(box, L.t('none'));
    O.clear(box).appendChild(L.table(['line', 'product', 'insurer', 'status', 'actions'], rows.map(function (p) {
      return h('tr', null, h('td', null, A.line(p.line_code)), h('td', null, h('b', null, L.tr(p.name) || p.code)), h('td', null, (p.carrier_short_name || p.carrier_name) || '—'),
        h('td', null, p.sellable ? h('span', { class: 'st st-ok' }, L.t('p_sellable')) : h('span', { class: 'st st-bad', title: (p.reasons || []).join(', ') }, L.t('p_blocked'))),
        h('td', { class: 'acts' }, h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-product': p.product_id, onclick: function () { show(p.product_id); } }, L.t('n_details'))));
    })));
  }
  function show(pid) {
    var item = all.filter(function (p) { return p.product_id === pid; })[0] || {};
    history.replaceState(null, '', location.pathname + '?product=' + encodeURIComponent(pid) + (customer ? '&customer=' + encodeURIComponent(customer) : ''));
    O.loading(det);
    O.api('/catalogue/products/' + encodeURIComponent(pid)).then(function (p) {
      var covers = p.coverage_definitions || p.coverageDefinitions || [], excl = p.exclusions || [];
      O.clear(det).append(h('h2', null, L.tr(p.name) || p.code), h('p', { class: 'sub' }, [A.line(p.line_code), ((item.carrier_short_name || item.carrier_name) || ((p.carrier || {}).party || {}).display_name)].filter(Boolean).join(' · ')),
        h('div', { class: 'ag-fields' }, A.field(L.t('status'), item.sellable ? L.t('p_sellable') : L.t('p_blocked')),
          A.field(L.t('p_commission'), item.commission_basis_points != null ? (item.commission_basis_points / 100) + ' %' : null), item.requires_carrier_approval ? A.field(L.t('p_approval'), L.t('pr_yes')) : null),
        h('h3', null, L.t('p_covers')), covers.length ? h('ul', null, covers.map(function (c) { var m = c.mandatory !== undefined ? c.mandatory : (c.pivot || {}).mandatory; return h('li', null, L.tr(c.name) || c.code, h('small', { class: 'muted' }, ' · ' + (m ? L.t('p_mandatory') : L.t('p_optional')))); })) : h('p', { class: 'sub' }, L.t('none')),
        h('h3', null, L.t('p_excl')), excl.length ? h('ul', null, excl.map(function (x) { return h('li', null, L.tr(x.name) || x.code); })) : h('p', { class: 'sub' }, L.t('none')),
        item.sellable && A.canQuote() ? L.link(L.t('p_quote'), '/account/buy?product=' + encodeURIComponent(p.code) + '&line=' + encodeURIComponent(String(p.line_code || '').toLowerCase()) + (customer ? '&customer=' + encodeURIComponent(customer) : ''), 'dbtn-primary', 'compare') : null);
    }).catch(function (e) { A.fail(det, e); });
  }
});
</script>
@endpush
