{{-- /account/customers — the agent's / broker's clients. GET /mobile/agent/clients (agent) or /mobile/broker/clients (broker), plus .../renewals. --}}
@php $K = __('account_agent'); @endphp
@extends('public.account.layout', ['title' => $K['customers_t'], 'lede' => $K['customers_lede'], 'crumbs' => [[$K['customers_t'], null]], 'active' => 'customers'])
@section('content')
@include('public.account.agent.assets')
<div data-agent class="agrid">
  <div class="desk-stats ag-stats4" data-stats></div>
  <div class="desk-main">
    <section class="acard" data-page-body>
      <div class="tabs-u" role="tablist" data-tabs></div>
      <div class="desk-tools" data-tools>
        <a class="dbtn dbtn-primary sm" href="/account/buy">@include('public.partials.i', ['n' => 'compare']){{ $K['js']['new_quote'] }}</a>
      </div>
      <div data-rows></div>
    </section>
    <aside class="desk-side">
      <section class="acard"><h2>{{ $K['js']['renewals'] }}</h2><p class="sub">{{ $K['js']['renewals_sub'] }}</p><div data-renewals></div></section>
      <section class="acard"><h2>{{ $K['js']['by_city'] }}</h2><div data-cities></div></section>
    </aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, O = Opes, h = O.h;
  if (!A.guard(ctx)) return;
  var box = O.$('[data-rows]'), all = [], state = { tab: 'all', q: '' };
  O.loading(box);
  if (!A.canQuote()) O.$('[data-tools] a').remove();
  O.$('[data-tools]').insertBefore(A.search(A.t('search_clients'), function (q) { state.q = q; render(); }), O.$('[data-tools]').firstChild);

  A.renewals().then(function (rows) {
    var host = O.clear(O.$('[data-renewals]'));
    if (!rows.length) return host.appendChild(h('p', { class: 'sub' }, A.t('no_renewals')));
    host.appendChild(h('ul', { class: 'ag-list' }, rows.slice(0, 6).map(function (r) {
      return h('li', null, h('div', null, h('a', { href: '/account/customers/' + encodeURIComponent(r.customer_id) }, r.customer_name), h('small', null, (r.policy_number || '—') + ' · ' + O.date(r.expires_at))),
        h('span', { class: 'st st-' + (r.days_remaining <= 15 ? 'bad' : 'warn') }, A.t('days_left', { n: r.days_remaining })));
    })));
  }).catch(function (e) { O.clear(O.$('[data-renewals]')).appendChild(h('p', { class: 'sub' }, A.errMsg(e))); });

  return A.clients().then(function (rows) {
    all = rows;
    var withPol = all.filter(function (c) { return c.policy_count > 0; }).length;
    var soon = all.filter(function (c) { var d = A.days(c.renewal_due_at); return d !== null && d <= 60; }).length;
    var pols = all.reduce(function (s, c) { return s + (c.policy_count || 0); }, 0);
    var fourth = A.mode() === 'broker'
      ? A.stat('r', 'card', A.t('stat_outstanding'), A.money(all.reduce(function (s, c) { return s + (c.outstanding_minor || 0); }, 0)), A.t('stat_outstanding_d'))
      : A.stat('p', 'shield', A.t('stat_kyc'), all.filter(function (c) { return /VERIFIED|APPROVED/.test(String(c.kyc_status)); }).length, A.t('stat_kyc_d'));
    A.stats(O.$('[data-stats]'), [A.stat('b', 'users', A.t('stat_clients'), all.length, A.t('stat_clients_d')), A.stat('g', 'check', A.t('stat_policies'), pols, A.t('stat_policies_d')),
      A.stat('o', 'clock', A.t('stat_renew'), soon, A.t('stat_renew_d')), fourth]);
    tabs(withPol, soon);
    var cities = A.group(all, function (c) { return c.city; }).slice(0, 6);
    O.clear(O.$('[data-cities]')).appendChild(A.bars(cities.map(function (c) { return [c[0], c[1]]; })));
    render();
  }).catch(function (e) { A.fail(box, e); });

  function tabs(withPol, soon) {
    A.tabs(O.$('[data-tabs]'), [['all', A.t('tab_all'), all.length], ['insured', A.t('tab_insured'), withPol], ['none', A.t('tab_none'), all.length - withPol], ['renew', A.t('tab_renew'), soon]], state.tab, function (t) { state.tab = t; render(); });
  }
  function render() {
    if (!all.length) return O.empty(box, A.t('no_clients'), h('a', { class: 'dbtn dbtn-primary sm', href: '/account/leads' }, A.t('go_leads')));
    var rows = all.filter(function (c) {
      if (state.tab === 'insured' && !(c.policy_count > 0)) return false;
      if (state.tab === 'none' && c.policy_count > 0) return false;
      if (state.tab === 'renew') { var d = A.days(c.renewal_due_at); if (d === null || d > 60) return false; }
      if (state.q && [c.full_name, c.phone_e164, c.city].join(' ').toLowerCase().indexOf(state.q) < 0) return false;
      return true;
    });
    if (!rows.length) return O.empty(box, A.t('no_match'));
    var broker = A.mode() === 'broker';
    O.clear(box).appendChild(A.table(['th_client', 'th_phone', 'th_city', broker ? 'th_outstanding' : 'th_kyc', 'th_policies', 'th_renewal', 'th_actions'], rows.map(function (c) {
      var href = '/account/customers/' + encodeURIComponent(c.id);
      return h('tr', null,
        h('td', null, h('a', { class: 'rowlink', href: href }, c.full_name)),
        h('td', null, c.phone_e164 || '—'),
        h('td', null, c.city || '—'),
        h('td', broker ? { class: 'amt' } : null, broker ? A.money(c.outstanding_minor) : O.chip(c.kyc_status, A.label(c.kyc_status))),
        h('td', null, String(c.policy_count || 0)),
        h('td', null, O.date(c.renewal_due_at)),
        h('td', { class: 'acts' }, h('a', { class: 'dbtn dbtn-outline sm', href: href }, A.t('view')), A.canQuote() ? h('a', { class: 'dbtn dbtn-primary sm', href: '/account/buy?customer=' + encodeURIComponent(c.id) }, A.t('quote')) : null));
    })));
  }
});
</script>
@endpush
