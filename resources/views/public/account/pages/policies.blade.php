{{-- /account/policies — the customer's policies (GET /mobile/wallet). --}}
@extends('public.account.layout', ['title' => __('account_policies.pol.title'), 'lede' => __('account_policies.pol.lede'), 'crumbs' => [[__('account_policies.pol.title'), null]], 'active' => 'policies'])
@section('content')
@include('public.account.partials.policies-assets')
<div class="stats" data-stats></div>
<section class="acard" data-page-body></section>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, T = OP.T, $ = Opes.$, box = $('[data-page-body]'), stats = $('[data-stats]');
  Opes.loading(box);
  return OP.policies().then(function (pols) {
    var cnt = { all: pols.length, ACTIVE: 0, EXPIRING: 0, EXPIRED: 0 };
    pols.forEach(function (p) { var s = OP.state(p); if (s === 'ACTIVE' || s === 'EXPIRING') cnt.ACTIVE++; if (s === 'EXPIRING') cnt.EXPIRING++; if (s === 'EXPIRED' || s === 'LAPSED') cnt.EXPIRED++; });
    Opes.clear(stats).append(OP.stat('doc', 'blue', T.pol.total, cnt.all, T.pol.total_d), OP.stat('check', 'green', T.dash.s_active, cnt.ACTIVE, T.dash.s_active_d),
      OP.stat('clock', 'orange', T.dash.s_exp, cnt.EXPIRING, T.dash.s_exp_d), OP.stat('x', 'red', T.expired, cnt.EXPIRED, ''));
    if (!pols.length) return Opes.empty(box, T.no_policies, OP.btn(T.get_quote, '/account/buy', 'dbtn-primary sm'));
    var tab = 'all', q = '';
    var tabs = h('div', { class: 'tabs-u op-tabs', role: 'tablist' });
    [['all', T.all, cnt.all], ['ACTIVE', T.active, cnt.ACTIVE], ['EXPIRING', T.expiring, cnt.EXPIRING], ['EXPIRED', T.expired, cnt.EXPIRED]].forEach(function (t) {
      tabs.appendChild(h('button', { type: 'button', role: 'tab', 'aria-selected': t[0] === tab ? 'true' : 'false', onclick: function () { tab = t[0]; Opes.$$('button', tabs).forEach(function (b) { b.setAttribute('aria-selected', b === this ? 'true' : 'false'); }, this); draw(); } }, t[1], h('span', { class: 'cnt' + (t[0] === 'EXPIRED' && t[2] ? ' bad' : '') }, t[2])));
    });
    var search = h('input', { type: 'search', placeholder: T.search + '…', 'aria-label': T.search, oninput: function () { q = this.value.toLowerCase(); draw(); } });
    var out = h('div');
    Opes.clear(box).append(h('div', { class: 'op-tabbar' }, tabs, OP.btn(T.get_quote, '/account/buy', 'dbtn-outline sm', 'compare')), h('div', { class: 'op-filters' }, h('label', { class: 'op-search' }, Opes.icon('search'), search)), out);
    function draw() {
      var rows = pols.filter(function (p) {
        var s = OP.state(p);
        if (tab === 'ACTIVE' && s !== 'ACTIVE' && s !== 'EXPIRING') return false;
        if (tab === 'EXPIRING' && s !== 'EXPIRING') return false;
        if (tab === 'EXPIRED' && s !== 'EXPIRED' && s !== 'LAPSED') return false;
        if (!q) return true;
        var r = OP.risk(p);
        return [OP.title(p), p.carrier_name, p.policy_number, r.name, r.reg, OP.line(OP.lineOf(p))].join(' ').toLowerCase().indexOf(q) >= 0;
      });
      Opes.clear(out);
      if (!rows.length) return Opes.empty(out, T.no_match);
      out.appendChild(OP.table([
        [T.pol.policy, function (p) { return h('div', { class: 'op-cell' }, h('span', { class: 'op-li' }, Opes.icon(OP.lineIcon(OP.lineOf(p)))), h('div', null, h('b', null, OP.title(p)), h('small', null, OP.line(OP.lineOf(p))))); }],
        [T.pol.insurer, function (p) { return p.carrier_name; }],
        [T.pol.number, function (p) { return h('a', { class: 'rowlink', href: '/account/policies/' + p.id }, p.policy_number); }],
        [T.pol.insured, function (p) { var r = OP.risk(p); return r.name ? h('div', null, r.name, r.reg ? h('small', { class: 'op-muted', style: 'display:block' }, r.reg) : null) : '—'; }],
        [T.pol.period, function (p) { return h('div', null, Opes.date(p.coverage_starts_at) + ' – ' + Opes.date(p.coverage_ends_at), h('small', { class: 'op-muted' + (p.days_to_expiry < 31 ? ' op-late' : ''), style: 'display:block' }, OP.daysNote(p))); }],
        [T.pol.premium, function (p) { return OP.mm(OP.total(p)); }],
        [T.pol.status, function (p) { return OP.chip(OP.state(p)); }],
        [T.pol.actions, function (p) { return OP.btn(T.view, '/account/policies/' + p.id); }]
      ], rows, 'op-stack'));
    }
    draw();
  }).catch(function (e) { Opes.fail(box, e); });
});
</script>
@endpush
