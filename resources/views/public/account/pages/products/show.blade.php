{{-- /account/products/{product} — CUST-023 Product Details: tabs Overview, Coverage, Exclusions, Eligibility, Requirements, Documents; "Get a quote".
     GET /catalogue/products/{product} (same catalogue endpoint the app and /account/buy use) + GET /mobile/catalogue/lines/{LINE}/risk-schema
     for the quote requirements. "Get a quote" → /account/buy?line=…&product=CODE. --}}
@extends('public.account.layout', ['title' => __('launch_customer.product.title'), 'lede' => __('launch_customer.product.lede'), 'crumbs' => [[__('launch_customer.needs.title'), '/account/needs'], [__('launch_customer.product.title'), null]], 'active' => 'quotes'])
@section('content')
@include('public.account.partials.launch-assets')
<section class="acard" data-page-body data-product></section>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, P = LC.T.prod, box = Opes.$('[data-page-body]'), id = ctx.ids[0];
  var lang = Opes.locale === 'fr' ? 'fr' : 'en';
  function loc(v) { if (v && typeof v === 'object') return v[lang] || v.en || v.fr || Object.values(v)[0] || ''; return v || ''; }
  function kv(rows) { return h('dl', { class: 'kv' }, rows.filter(function (r) { return r[1]; }).map(function (r) { return [h('dt', null, r[0]), h('dd', null, r[1])]; })); }
  Opes.loading(box);
  return Opes.api('/catalogue/products/' + encodeURIComponent(id)).then(function (p) {
    if (!p || !p.id) throw { message: P.not_found };
    var line = String(p.line_code || '').toUpperCase();
    var carrier = (p.carrier && ((p.carrier.party && p.carrier.party.display_name) || p.carrier.name)) || '';
    var quoteUrl = '/account/buy?line=' + encodeURIComponent(line.toLowerCase()) + '&product=' + encodeURIComponent(p.code || '');
    var covs = p.coverage_definitions || p.coverageDefinitions || [];
    if (!covs.length && Array.isArray(p.coverages)) covs = p.coverages;
    var excl = p.exclusions || [];
    var elig = p.eligibility_rules && typeof p.eligibility_rules === 'object' ? p.eligibility_rules : {};

    var panes = {
      overview: h('div', null, kv([[P.insurer, carrier], [P.line, OP.line(line)], [P.code, p.code], [P.version, p.version ? String(p.version) : ''],
        [P.from, p.effective_from ? Opes.date(p.effective_from) : ''], [P.until, p.effective_until ? Opes.date(p.effective_until) : ''], [P.reg, p.regulatory_reference || '']])),
      coverage: covs.length ? h('ul', { class: 'op-nlist' }, covs.map(function (c) {
        return h('li', null, h('span', { class: 'op-li' }, Opes.icon('shield')), h('div', null, h('b', null, loc(c.name) || c.code || ''), c.description ? h('small', { class: 'op-muted', style: 'display:block' }, loc(c.description)) : null),
          h('span', { class: 'st ' + (c.mandatory ? 'st-ok' : 'st-muted') }, c.mandatory ? P.mandatory : P.optional));
      })) : h('p', { class: 'op-muted' }, P.no_cov),
      exclusions: excl.length ? h('ul', { class: 'op-nlist' }, excl.map(function (x) { return h('li', null, h('span', { class: 'op-li' }, Opes.icon('x')), h('div', null, h('b', null, loc(x.name) || x.code || ''), x.description ? h('small', { class: 'op-muted', style: 'display:block' }, loc(x.description)) : null)); })) : h('p', { class: 'op-muted' }, P.no_excl),
      eligibility: Object.keys(elig).length ? kv(Object.keys(elig).map(function (k) { var v = elig[k]; return [Opes.label(k), Array.isArray(v) ? v.join(', ') : typeof v === 'object' && v ? JSON.stringify(v) : String(v)]; })) : h('p', { class: 'op-muted' }, P.no_elig),
      requirements: h('div', { 'data-reqs': '' }, h('div', { class: 'acct-loading', role: 'status' }, h('span', { class: 'spin' }))),
      documents: h('div', null, h('p', null, P.docs_d), h('ul', { style: 'display:grid;gap:6px;padding-left:18px' }, (P.docs || []).map(function (d) { return h('li', null, d); })))
    };
    var tabs = h('div', { class: 'op-tabs', role: 'tablist' }), body = h('div', { style: 'margin-top:16px' });
    Object.keys(panes).forEach(function (k, i) {
      var b = h('button', { type: 'button', role: 'tab', class: 'op-tab', 'data-tab': k, 'aria-selected': i === 0 ? 'true' : 'false', onclick: function () {
        Opes.$$('[data-tab]', tabs).forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
        Opes.clear(body).appendChild(panes[k]);
      } }, P.tabs[k]);
      tabs.appendChild(b);
    });
    body.appendChild(panes.overview);
    Opes.clear(box).append(h('div', { class: 'acard-h' }, h('div', { class: 'op-cell' }, h('span', { class: 'op-li' }, Opes.icon(OP.lineIcon(line))), h('div', null, h('h2', { style: 'margin:0' }, loc(p.name)), h('small', { class: 'op-muted' }, carrier))),
      OP.btn(P.get_quote, quoteUrl, 'dbtn-primary', 'compare')), tabs, body);

    Opes.api('/mobile/catalogue/lines/' + encodeURIComponent(line) + '/risk-schema').then(function (sc) {
      // GET /mobile/catalogue/lines/{code}/risk-schema answers {fields:[{key, label, label_en, label_fr, required}], required:[...]} (MobileRiskSchemaController).
      var fields = (sc && sc.fields) || [], req = (sc && sc.required) || [];
      var fr = Opes.locale === 'fr';
      Opes.clear(panes.requirements).appendChild(fields.length ? h('div', null, h('p', null, P.req_d), h('ul', { style: 'display:grid;gap:6px;padding-left:18px' }, fields.map(function (f) {
        return h('li', null, (fr ? f.label_fr : f.label_en) || loc(f.label) || Opes.label(f.key), f.required || req.indexOf(f.key) >= 0 ? h('small', { class: 'op-muted' }, ' (' + P.required + ')') : null);
      }))) : h('p', { class: 'op-muted' }, P.no_req));
    }).catch(function () { Opes.clear(panes.requirements).appendChild(h('p', { class: 'op-muted' }, P.no_req)); });
  }).catch(function (e) { Opes.fail(box, e && e.status === 404 ? { message: P.not_found } : e); });
});
</script>
@endpush
