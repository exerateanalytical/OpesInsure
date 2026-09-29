{{-- /account/search[?q=…&types=policies,claims] — SHR-001 Global Search + SHR-002 Advanced Search (entity-type filter, results per type).
     GET /search?q=…&types[]=…&limit=… — the app's search endpoint (GlobalSearchService: permission + data scope; a customer only
     ever searches their own rows). Hits open the customer's own detail pages. --}}
@extends('public.account.layout', ['title' => __('launch_customer.search_p.title'), 'lede' => __('launch_customer.search_p.lede'), 'crumbs' => [[__('launch_customer.search_p.title'), null]], 'active' => ''])
@section('content')
@include('public.account.partials.launch-assets')
<section class="acard" data-search-form></section>
<section class="acard" data-page-body data-search-results style="margin-top:16px"></section>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, S = LC.T.search, fb = Opes.$('[data-search-form]'), box = Opes.$('[data-page-body]');
  var ROUTE = { policies: '/account/policies/', claims: '/account/claims/', quotes: '/account/quotes/', documents: null, vehicles: '/account/vehicles', risk_assets: '/account/vehicles' };
  var agentish = ctx.kind !== 'customer';
  if (agentish) ROUTE.customers = '/account/customers/'; else delete S.types.customers; // a customer's scope never returns customers
  var want = (ctx.params.get('types') || '').split(',').filter(function (t) { return S.types[t]; });
  var q = h('input', { type: 'search', name: 'q', value: ctx.params.get('q') || '', minlength: 2, maxlength: 100, required: true, placeholder: S.placeholder, 'aria-label': S.label, style: 'flex:1;min-width:200px' });
  var limit = h('select', { name: 'limit', 'aria-label': 'limit' }, [5, 10, 20].map(function (n) { return h('option', { value: n, selected: String(n) === (ctx.params.get('limit') || '10') }, String(n)); }));
  var sheet = h('details', { 'data-filter': '' }, h('summary', { class: 'dbtn dbtn-outline sm', style: 'list-style:none;cursor:pointer' }, Opes.icon('grid'), S.filter),
    h('fieldset', { style: 'border:0;padding:12px 0;display:flex;flex-wrap:wrap;gap:12px' }, h('legend', { style: 'font-weight:700' }, S.types_label),
      Object.keys(S.types).map(function (t) { return h('label', { style: 'display:flex;gap:6px;align-items:center' }, h('input', { type: 'checkbox', name: 'types', value: t, checked: want.indexOf(t) >= 0 }), S.types[t]); }),
      h('label', { style: 'display:flex;gap:6px;align-items:center' }, limit)));
  var form = h('form', { role: 'search', style: 'display:grid;gap:8px', onsubmit: function (e) {
    e.preventDefault();
    var types = Opes.$$('input[name=types]:checked', form).map(function (i) { return i.value; });
    var u = new URLSearchParams({ q: q.value.trim() }); if (types.length) u.set('types', types.join(',')); u.set('limit', limit.value);
    history.replaceState(null, '', location.pathname + '?' + u.toString());
    run(q.value.trim(), types, limit.value);
  } }, h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' }, q, h('button', { type: 'submit', class: 'dbtn dbtn-primary' }, Opes.icon('search'), S.submit)), sheet, h('small', { class: 'op-muted' }, S.hint));
  Opes.clear(fb).appendChild(form);

  function run(text, types, lim) {
    if (text.length < 2) { Opes.empty(box, S.too_short); return; }
    Opes.loading(box);
    var qs = new URLSearchParams({ q: text, limit: String(lim || 10) }); types.forEach(function (t) { qs.append('types[]', t); });
    Opes.api('/search?' + qs.toString()).then(function (r) {
      var flat = Array.isArray(r && r.results) ? r.results : [];
      Opes.clear(box);
      if (!flat.length) { Opes.empty(box, S.none); return; }
      box.appendChild(h('p', { class: 'op-muted' }, LC.fmt(S.results, { n: flat.length })));
      var by = {}; flat.forEach(function (x) { (by[x.type] = by[x.type] || []).push(x); });
      Object.keys(by).forEach(function (t) {
        box.append(h('h2', { style: 'font-size:16px;margin:16px 0 8px' }, (S.types[t] || Opes.label(t)) + ' (' + by[t].length + ')'),
          h('ul', { class: 'op-nlist', 'data-hits': t }, by[t].map(function (x) {
            var base = ROUTE[t], href = base ? (base.slice(-1) === '/' ? base + x.id : base) : null;
            return h('li', null, h('div', null, h('b', null, x.title || x.id), x.subtitle ? h('small', { class: 'op-muted', style: 'display:block' }, x.subtitle) : null),
              x.status ? OP.chip(x.status) : null,
              t === 'documents' ? h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { OP.openDocument(x.id); } }, OP.T.view) : href ? OP.btn(OP.T.view, href) : null);
          })));
      });
    }).catch(function (e) { Opes.fail(box, e); });
  }
  if (q.value.trim().length >= 2) run(q.value.trim(), want, limit.value); else Opes.empty(box, S.hint);
});
</script>
@endpush
