{{-- /account/needs — CUST-025 Needs Assessment: a short dynamic questionnaire (follow-up questions depend on the needs picked)
     that feeds the eligible products from GET /catalogue/products?line_code=… (the catalogue endpoint the app and /account/buy use).
     Each result links to CUST-023 /account/products/{id} and to /account/buy?line=…&product=CODE. --}}
@extends('public.account.layout', ['title' => __('launch_customer.needs.title'), 'lede' => __('launch_customer.needs.lede'), 'crumbs' => [[__('account_buy.quotes'), '/account/quotes'], [__('launch_customer.needs.title'), null]], 'active' => 'quotes'])
@section('content')
@include('public.account.partials.launch-assets')
<div class="agrid main-side op-ms380">
  <section class="acard" data-page-body data-needs></section>
  <section class="acard" data-results></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, N = LC.T.needs, box = Opes.$('[data-page-body]'), res = Opes.$('[data-results]');
  var LIVE = /^(ACTIVE|PUBLISHED|APPROVED|LIVE)$/;
  // Follow-up question per need: [answer key, question, options]
  var FOLLOW = { MOTOR: ['use', N.q_use, N.use], TRAVEL: ['trip', N.q_trip, N.trip], HOME: ['home', N.q_home, N.home], HEALTH: ['people', N.q_people, N.people], BUSINESS: ['staff', N.q_staff, N.staff] };

  function radios(name, opts, multi) {
    return h('div', { style: 'display:flex;flex-wrap:wrap;gap:8px' }, Object.keys(opts).map(function (k) {
      return h('label', { class: 'dbtn dbtn-outline sm', style: 'cursor:pointer' }, h('input', { type: multi ? 'checkbox' : 'radio', name: name, value: k, style: 'margin-right:6px' }), opts[k]);
    }));
  }
  var follow = h('div', { 'data-follow': '', style: 'display:grid;gap:12px' });
  var form = h('form', { style: 'display:grid;gap:16px', onsubmit: function (e) { e.preventDefault(); run(); } },
    h('fieldset', { style: 'border:0;padding:0;display:grid;gap:8px' }, h('legend', { style: 'font-weight:700' }, N.q1), radios('what', N.what, true)),
    h('fieldset', { style: 'border:0;padding:0;display:grid;gap:8px' }, h('legend', { style: 'font-weight:700' }, N.q2), radios('who', N.who, false)),
    follow,
    h('div', { class: 'btnbar' }, h('button', { type: 'submit', class: 'dbtn dbtn-primary' }, Opes.icon('compare'), N.see), h('button', { type: 'reset', class: 'dbtn dbtn-outline', onclick: function () { setTimeout(paintFollow, 0); Opes.clear(res); } }, N.restart)));
  function picked() { return Opes.$$('input[name=what]:checked', form).map(function (i) { return i.value; }); }
  function paintFollow() {
    Opes.clear(follow);
    picked().forEach(function (w) { var f = FOLLOW[w]; if (f) follow.appendChild(h('fieldset', { style: 'border:0;padding:0;display:grid;gap:8px', 'data-q': f[0] }, h('legend', { style: 'font-weight:700' }, f[1]), radios(f[0], f[2], false))); });
  }
  form.addEventListener('change', function (e) { if (e.target.name === 'what') paintFollow(); });
  Opes.clear(box).appendChild(form);

  function run() {
    var what = picked(), who = (Opes.$('input[name=who]:checked', form) || {}).value || 'INDIVIDUAL';
    if (!what.length) { Opes.alert(N.pick); return; }
    if (who === 'BUSINESS' && what.indexOf('BUSINESS') < 0) what.push('BUSINESS');
    var use = (Opes.$('input[name=use]:checked', form) || {}).value;
    Opes.loading(res);
    Promise.all(what.map(function (line) { return Opes.list('/catalogue/products', { line_code: line }).then(function (r) { return r.items; }).catch(function () { return []; }); })).then(function (lists) {
      var all = [].concat.apply([], lists).filter(function (p) { return !p.status || LIVE.test(String(p.status).toUpperCase()); }).filter(function (p) {
        var e = p.eligibility_rules || {}, types = e.customer_types || e.party_types || null;
        if (Array.isArray(types) && types.length) { var want = who === 'BUSINESS' ? /ORG|BUSINESS|COMPANY|CORPORATE/ : /IND|PERSON|NATURAL/; if (!types.some(function (t) { return want.test(String(t).toUpperCase()); })) return false; }
        if (use && Array.isArray(e.vehicle_uses) && e.vehicle_uses.length && e.vehicle_uses.indexOf(use) < 0) return false;
        return true;
      });
      Opes.clear(res).append(h('h2', null, N.result_t), h('p', { class: 'op-muted' }, LC.fmt(N.result_d, { n: all.length })));
      if (!all.length) { res.appendChild(h('p', null, N.none)); res.appendChild(OP.btn(N.ask, '/account/support', 'dbtn-outline', 'headset')); return; }
      res.appendChild(h('ul', { class: 'op-nlist', 'data-products': '' }, all.map(function (p) {
        var line = String(p.line_code || '').toUpperCase(), carrier = (p.carrier && (p.carrier.brand_short_name || (p.carrier.party && p.carrier.party.display_name) || p.carrier.name)) || '';
        var name = p.name && typeof p.name === 'object' ? (p.name[Opes.locale] || p.name.en || '') : p.name;
        return h('li', null, h('span', { class: 'op-li' }, Opes.icon(OP.lineIcon(line))), h('div', null, h('b', null, name), h('small', { class: 'op-muted', style: 'display:block' }, [OP.line(line), carrier].filter(Boolean).join(' · '))),
          h('div', { style: 'display:flex;gap:6px;flex-wrap:wrap' }, OP.btn(N.details, '/account/products/' + p.id), OP.btn(N.quote, '/account/buy?line=' + line.toLowerCase() + '&product=' + encodeURIComponent(p.code || ''), 'dbtn-primary sm')));
      })));
    });
  }
});
</script>
@endpush
