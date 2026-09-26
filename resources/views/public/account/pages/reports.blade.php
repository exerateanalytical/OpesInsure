{{-- /account/reports — production figures computed only from API data.
     Agent: GET /mobile/agent/dashboard + /mobile/partner/agent/policies + /mobile/partner/agent/quotes.
     Broker: GET /mobile/broker/dashboard + /mobile/broker/production + /mobile/partner/broker/quotes. --}}
@php $K = __('account_agent'); @endphp
@extends('public.account.layout', ['title' => $K['reports_t'], 'lede' => $K['reports_lede'], 'crumbs' => [[$K['reports_t'], null]], 'active' => 'reports'])
@section('content')
@include('public.account.agent.assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body><h2>{{ $K['js']['overview'] }}</h2><p class="sub">{{ $K['js']['overview_sub'] }}</p><div data-kpis></div></section>
  <div class="desk-stats ag-stats4" data-stats></div>
  <section class="acard"><h2>{{ $K['js']['by_month'] }}</h2><p class="sub">{{ $K['js']['by_month_sub'] }}</p><div data-months></div></section>
  <div class="ag-2">
    <section class="acard"><h2>{{ $K['js']['by_insurer'] }}</h2><div data-insurers></div></section>
    <section class="acard"><h2>{{ $K['js']['by_status'] }}</h2><div data-status></div></section>
  </div>
  <div class="ag-2">
    <section class="acard"><h2>{{ $K['js']['top_clients'] }}</h2><div data-clients></div></section>
    <section class="acard"><h2>{{ $K['js']['quote_funnel'] }}</h2><div data-funnel></div></section>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, O = Opes, h = O.h;
  if (!A.guard(ctx)) return;
  var broker = A.mode() === 'broker';
  O.loading(O.$('[data-kpis]'));

  A.dashboard().then(function (d) {
    var m = (d && d.metrics) || [];
    if (!m.length) return O.empty(O.$('[data-kpis]'), A.t('no_data'));
    O.clear(O.$('[data-kpis]')).appendChild(h('div', { class: 'ag-kpis' }, m.map(function (x) { return h('div', { class: 't-' + (x.tone || 'neutral') }, h('small', null, (T().metrics || {})[x.label] || x.label), h('b', null, x.value)); })));
  }).catch(function (e) { A.fail(O.$('[data-kpis]'), e); });

  ['[data-months]', '[data-insurers]', '[data-status]', '[data-clients]', '[data-funnel]'].forEach(function (s) { O.loading(O.$(s)); });
  var prod = broker ? O.list('/mobile/broker/production').then(function (r) { return r.items; }) : A.policies();
  prod.then(function (pols) {
    var premium = function (p) { return p.premium_minor || 0; };
    var total = pols.reduce(function (s, p) { return s + premium(p); }, 0);
    var active = pols.filter(function (p) { return p.status === 'ACTIVE'; });
    var year = pols.filter(function (p) { return p.issued_at && new Date(p.issued_at) >= new Date(Date.now() - 365 * 86400000); });
    A.stats(O.$('[data-stats]'), [A.stat('b', 'doc', A.t('r_policies'), pols.length, A.t('r_policies_d')), A.stat('g', 'check', A.t('r_active'), active.length, A.t('r_active_d')),
      A.stat('p', 'card', A.t('r_premium'), A.money(total), A.t('r_premium_d')), A.stat('o', 'clock', A.t('r_12m'), A.money(year.reduce(function (s, p) { return s + premium(p); }, 0)), A.t('r_12m_d'))]);

    // Last 12 months of written premium (by issue month).
    var months = [], now = new Date();
    for (var i = 11; i >= 0; i--) { var dt = new Date(now.getFullYear(), now.getMonth() - i, 1); months.push({ k: dt.getFullYear() + '-' + dt.getMonth(), d: dt, v: 0, n: 0 }); }
    pols.forEach(function (p) { if (!p.issued_at) return; var dt = new Date(p.issued_at), k = dt.getFullYear() + '-' + dt.getMonth(); months.forEach(function (mo) { if (mo.k === k) { mo.v += premium(p); mo.n++; } }); });
    var max = Math.max.apply(null, months.map(function (mo) { return mo.v; }));
    var mh = O.clear(O.$('[data-months]'));
    if (!max) mh.appendChild(h('p', { class: 'sub' }, A.t('no_production')));
    else mh.appendChild(h('div', { class: 'ag-cols', role: 'img', 'aria-label': A.t('by_month') }, months.map(function (mo) {
      var lab = new Intl.DateTimeFormat(O.locale === 'fr' ? 'fr-FR' : 'en-GB', { month: 'short' }).format(mo.d);
      return h('div', { title: lab + ': ' + A.money(mo.v) + ' · ' + mo.n }, h('b', null, mo.n ? String(mo.n) : ''), h('i', { style: 'height:' + (mo.v / max * 120) + 'px' }), h('small', null, lab));
    })));

    O.clear(O.$('[data-insurers]')).appendChild(A.bars(A.group(pols, function (p) { return p.carrier_name; }, premium).slice(0, 8).map(function (g) { return [g[0], g[1], A.money(g[1])]; })));
    O.clear(O.$('[data-status]')).appendChild(A.bars(A.group(pols, function (p) { return A.label(p.status); }).map(function (g) { return [g[0], g[1]]; }), '#0EA5E9'));
    O.clear(O.$('[data-clients]')).appendChild(A.bars(A.group(pols, function (p) { return p.customer_name; }, premium).slice(0, 6).map(function (g) { return [g[0], g[1], A.money(g[1])]; }), '#16A34A'));
  }).catch(function (e) { ['[data-months]', '[data-insurers]', '[data-status]', '[data-clients]'].forEach(function (s) { A.fail(O.$(s), e); }); });

  A.quotes().then(function (qs) {
    var host = O.clear(O.$('[data-funnel]'));
    if (!qs.length) return host.appendChild(h('p', { class: 'sub' }, A.t('no_quotes')));
    host.appendChild(A.bars(A.group(qs, function (q) { return A.label(q.status); }).map(function (g) { return [g[0], g[1]]; }), '#F59E0B'));
    host.appendChild(h('p', { class: 'ag-note' }, A.t('quotes_total', { n: qs.length })));
  }).catch(function (e) { A.fail(O.$('[data-funnel]'), e); });

  function T() { return window.AGENT_T || {}; }
});
</script>
@endpush
