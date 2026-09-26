{{-- /account/claims-desk — design: screens/compare_buy_flow/23_stage.png (Claims Management). Queue: GET /mobile/carrier/claims. --}}
@php $K = __('account_desk'); @endphp
@extends('public.account.layout', ['title' => $K['list_t'], 'lede' => $K['list_lede'], 'crumbs' => [[$K['crumb_claims'], '/account/claims'], [$K['crumb_desk'], null]], 'active' => 'desk'])
@section('content')
@include('public.account.desk.assets')
<div data-desk class="agrid">
  <div class="desk-stats" data-stats></div>
  <div class="desk-main">
    <section class="acard" data-page-body>
      <div class="tabs-u" role="tablist" data-tabs></div>
      <div class="desk-tools">
        <label class="desk-search">@include('public.partials.i', ['n' => 'search'])<input type="search" data-q placeholder="{{ $K['js']['search_ph'] }}" aria-label="{{ $K['js']['search_ph'] }}"></label>
        <button type="button" class="dbtn dbtn-primary sm" data-export>@include('public.partials.i', ['n' => 'download']){{ $K['js']['export'] }}</button>
      </div>
      <div class="desk-filters">
        <label class="afield-s"><span>{{ $K['js']['f_type'] }}</span><select data-f="type"><option value="">{{ $K['js']['f_type_all'] }}</option></select></label>
        <label class="afield-s"><span>{{ $K['js']['f_insurer'] }}</span><select data-f="insurer"><option value="">{{ $K['js']['f_insurer_all'] }}</option></select></label>
        <label class="afield-s"><span>{{ $K['js']['f_priority'] }}</span><select data-f="priority"><option value="">{{ $K['js']['f_priority_all'] }}</option></select></label>
        <button type="button" class="dbtn dbtn-outline sm" data-reset>{{ $K['js']['reset'] }}</button>
      </div>
      <div data-rows></div>
      <div class="desk-pager" data-pager></div>
    </section>
    <aside class="desk-side">
      <section class="acard"><h2>{{ $K['js']['analytics'] }}</h2><p class="sub">{{ $K['js']['analytics_sub'] }}</p><div data-donut></div></section>
      <section class="acard"><h2>{{ $K['js']['by_type'] }}</h2><div data-bars></div></section>
      <section class="acard"><h2>{{ $K['js']['recent'] }}</h2><div data-recent></div></section>
    </aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var D = Desk, O = Opes, h = O.h;
  if (!D.guard(ctx)) return;
  var box = O.$('[data-rows]');
  O.loading(box);
  var all = [], details = {}, state = { tab: 'all', q: '', type: '', insurer: '', priority: '', page: 1, per: 8 };
  var TABS = [['all', 'tab_all'], ['new', 'tab_new'], ['assess', 'tab_assess'], ['review', 'tab_review'], ['approved', 'tab_approved'], ['settled', 'tab_settled'], ['rejected', 'tab_rejected']];
  var COLORS = { new: '#1E63E9', assess: '#F59E0B', review: '#7C3AED', approved: '#0EA5E9', settled: '#16A34A', rejected: '#E5484D' };

  function typeOf(x) { var s = x.subject || ''; return s.indexOf('·') >= 0 ? s.split('·').pop().trim() : ''; }
  function custOf(x) { var s = x.subject || ''; return s.indexOf('·') >= 0 ? s.split('·')[0].trim() : s; }

  return O.list('/mobile/carrier/claims').then(function (r) {
    all = r.items;
    stats(); tabs(); filters(); analytics(); render();
    // Amount, policy and activity need the per-claim record: fetch it for the queue (max 100 rows from the API).
    return Promise.all(all.map(function (x) { return O.api('/mobile/partner/carrier/claims/' + x.id).then(function (d) { details[x.id] = d; }).catch(function () {}); }))
      .then(function () { render(); recent(); });
  }).catch(function (e) { D.fail(box, e); });

  function stats() {
    var n = function (b) { return all.filter(function (x) { return D.bucket(x.status) === b; }).length; };
    var cards = [['b', 'doc', 'stat_total', all.length], ['o', 'clock', 'stat_assess', n('new') + n('assess')], ['p', 'scale', 'stat_review', n('review')], ['g', 'check', 'stat_settled', n('settled') + n('approved')], ['r', 'x', 'stat_rejected', n('rejected')]];
    O.clear(O.$('[data-stats]')).append.apply(O.$('[data-stats]'), cards.map(function (c) {
      return h('div', { class: 'statc' }, h('span', { class: 'sq ' + c[0] }, O.icon(c[1])), h('div', null, h('small', null, D.t(c[2])), h('b', null, String(c[3])), h('small', null, D.t('stat_hint'))));
    }));
  }
  function tabs() {
    var el = O.clear(O.$('[data-tabs]'));
    TABS.forEach(function (t) {
      var cnt = t[0] === 'all' ? all.length : all.filter(function (x) { return D.bucket(x.status) === t[0]; }).length;
      el.appendChild(h('button', { type: 'button', role: 'tab', 'aria-selected': String(state.tab === t[0]), onclick: function () { state.tab = t[0]; state.page = 1; tabs(); render(); } }, D.t(t[1]) + ' (' + cnt + ')'));
    });
  }
  function filters() {
    var uniq = function (f) { var s = {}; all.forEach(function (x) { var v = f(x); if (v) s[v] = 1; }); return Object.keys(s).sort(); };
    var fill = function (k, vals, lab) { var sel = O.$('[data-f="' + k + '"]'); vals.forEach(function (v) { sel.appendChild(h('option', { value: v }, lab ? lab(v) : v)); }); sel.addEventListener('change', function () { state[k] = sel.value; state.page = 1; render(); }); };
    fill('type', uniq(typeOf), D.type);
    fill('insurer', uniq(function (x) { return x.carrier_name; }));
    fill('priority', uniq(function (x) { return x.priority; }), D.type);
    var q = O.$('[data-q]'); q.addEventListener('input', function () { state.q = q.value.trim().toLowerCase(); state.page = 1; render(); });
    O.$('[data-reset]').addEventListener('click', function () { state.q = state.type = state.insurer = state.priority = ''; state.page = 1; q.value = ''; O.$$('[data-f]').forEach(function (s) { s.value = ''; }); render(); });
    O.$('[data-export]').addEventListener('click', exportCsv);
  }
  function visible() {
    return all.filter(function (x) {
      var d = details[x.id] || {};
      if (state.tab !== 'all' && D.bucket(x.status) !== state.tab) return false;
      if (state.type && typeOf(x) !== state.type) return false;
      if (state.insurer && x.carrier_name !== state.insurer) return false;
      if (state.priority && x.priority !== state.priority) return false;
      if (state.q && [x.reference, x.subject, d.policy_number, d.customer_name].join(' ').toLowerCase().indexOf(state.q) < 0) return false;
      return true;
    });
  }
  function render() {
    var rows = visible(), per = state.per, pages = Math.max(1, Math.ceil(rows.length / per));
    if (state.page > pages) state.page = pages;
    var slice = rows.slice((state.page - 1) * per, state.page * per);
    if (!all.length) return O.empty(box, D.t('no_queue'));
    if (!rows.length) { O.empty(box, D.t('no_claims')); O.clear(O.$('[data-pager]')); return; }
    var tbl = h('table', { class: 'atable desk-table' },
      h('thead', null, h('tr', null, ['th_number', 'th_customer', 'th_policy', 'th_type', 'th_date', 'th_status', 'th_amount', 'th_actions'].map(function (k) { return h('th', { scope: 'col' }, D.t(k)); }))),
      h('tbody', null, slice.map(function (x) {
        var d = details[x.id] || {}, href = D.base(x.id);
        return h('tr', null,
          h('td', null, h('a', { class: 'rowlink', href: href }, x.reference || '—')),
          h('td', null, d.customer_name || custOf(x), x.carrier_name ? h('span', { class: 'muted' }, x.carrier_name) : null),
          h('td', null, d.policy_number || '—'),
          h('td', null, D.type(typeOf(x))),
          h('td', null, O.date(x.submitted_at, true)),
          h('td', null, D.chip(x.status)),
          h('td', { class: 'amt' }, d.approved_amount_minor !== undefined ? D.money(d.approved_amount_minor !== null ? d.approved_amount_minor : d.estimated_loss_minor) : '…'),
          h('td', null, h('a', { class: 'dbtn dbtn-outline sm', href: href, 'aria-label': D.t('open') + ' ' + x.reference }, D.t('open'))));
      })));
    O.clear(box).appendChild(h('div', { class: 'atable-wrap' }, tbl));
    var pg = O.clear(O.$('[data-pager]')), from = (state.page - 1) * per + 1, to = Math.min(rows.length, state.page * per);
    var nav = h('nav', { 'aria-label': 'Pagination' }, h('button', { type: 'button', 'aria-label': D.t('prev'), disabled: state.page === 1, onclick: function () { state.page--; render(); } }, '‹'));
    for (var i = 1; i <= pages; i++) (function (p) { nav.appendChild(h('button', { type: 'button', 'aria-current': String(p === state.page), onclick: function () { state.page = p; render(); } }, String(p))); })(i);
    nav.appendChild(h('button', { type: 'button', 'aria-label': D.t('next'), disabled: state.page === pages, onclick: function () { state.page++; render(); } }, '›'));
    var sel = h('select', { 'aria-label': D.t('per_page'), onchange: function () { state.per = +this.value; state.page = 1; render(); } }, [8, 20, 50].map(function (n) { return h('option', { value: n, selected: n === per }, String(n)); }));
    pg.append(h('span', null, D.t('showing', { from: from, to: to, total: rows.length })), nav, h('label', null, D.t('show') + ' ', sel, ' ' + D.t('per_page')));
  }
  function analytics() {
    var host = O.clear(O.$('[data-donut]'));
    if (!all.length) { host.appendChild(h('p', { class: 'sub' }, D.t('no_queue'))); O.clear(O.$('[data-bars]')); return; }
    var counts = {}; all.forEach(function (x) { var b = D.bucket(x.status); counts[b] = (counts[b] || 0) + 1; });
    var ns = 'http://www.w3.org/2000/svg', svg = document.createElementNS(ns, 'svg'), R = 15.9, off = 0;
    svg.setAttribute('viewBox', '0 0 42 42');
    var bg = document.createElementNS(ns, 'circle'); bg.setAttribute('cx', 21); bg.setAttribute('cy', 21); bg.setAttribute('r', R); bg.setAttribute('fill', 'none'); bg.setAttribute('stroke', '#EEF2F8'); bg.setAttribute('stroke-width', 5); svg.appendChild(bg);
    var legend = h('ul', { class: 'legend' });
    TABS.slice(1).forEach(function (t) {
      var n = counts[t[0]] || 0; if (!n) return;
      var pct = n / all.length * 100, c = document.createElementNS(ns, 'circle');
      c.setAttribute('cx', 21); c.setAttribute('cy', 21); c.setAttribute('r', R); c.setAttribute('fill', 'none'); c.setAttribute('stroke', COLORS[t[0]]); c.setAttribute('stroke-width', 5);
      c.setAttribute('stroke-dasharray', pct + ' ' + (100 - pct)); c.setAttribute('stroke-dashoffset', String(-off)); off += pct; svg.appendChild(c);
      legend.appendChild(h('li', null, h('i', { style: 'background:' + COLORS[t[0]] }), h('span', null, D.t(t[1]), h('small', null, n + ' (' + pct.toFixed(1) + '%)'))));
    });
    host.appendChild(h('div', { class: 'donut-wrap' }, h('div', { class: 'donut' }, svg, h('div', { class: 'c' }, h('b', null, String(all.length)), h('small', null, D.t('total_claims')))), legend));
    var byType = {}; all.forEach(function (x) { var t = typeOf(x) || '—'; byType[t] = (byType[t] || 0) + 1; });
    var max = Math.max.apply(null, Object.keys(byType).map(function (k) { return byType[k]; }));
    var pal = ['#1E63E9', '#F59E0B', '#E5484D', '#0EA5E9', '#7C3AED', '#16A34A'];
    O.clear(O.$('[data-bars]')).appendChild(h('div', { class: 'bars' }, Object.keys(byType).sort(function (a, b) { return byType[b] - byType[a]; }).map(function (k, i) {
      return h('div', { class: 'row' }, h('span', null, D.type(k)), h('span', { class: 'tr' }, h('span', { style: 'width:' + (byType[k] / max * 100) + '%;background:' + pal[i % pal.length] })), h('b', null, String(byType[k])));
    })));
  }
  function recent() {
    var ev = [];
    all.forEach(function (x) { ((details[x.id] || {}).timeline || []).forEach(function (e) { ev.push({ x: x, e: e }); }); });
    ev.sort(function (a, b) { return new Date(b.e.occurred_at) - new Date(a.e.occurred_at); });
    var host = O.clear(O.$('[data-recent]'));
    if (!ev.length) return host.appendChild(h('p', { class: 'sub' }, D.t('no_activity')));
    host.appendChild(h('ul', { class: 'acts' }, ev.slice(0, 5).map(function (a) {
      return h('li', null, h('span', { class: 'ico' }, O.icon(D.bucket(a.e.to_status) === 'settled' ? 'check' : 'doc')), h('div', null, h('a', { href: D.base(a.x.id) }, ((window.DESK_T.ev || {})[a.e.to_status] || D.st(a.e.to_status)) + ' · ' + a.x.reference), h('small', null, O.date(a.e.occurred_at, true))));
    })));
  }
  function exportCsv() {
    var rows = visible(), esc = function (v) { v = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var lines = [[D.t('th_number'), D.t('th_customer'), D.t('th_policy'), D.t('th_type'), D.t('th_date'), D.t('th_status'), D.t('th_amount') + ' (XAF)'].map(esc).join(',')];
    rows.forEach(function (x) { var d = details[x.id] || {}; var amt = d.approved_amount_minor !== null && d.approved_amount_minor !== undefined ? d.approved_amount_minor : d.estimated_loss_minor; lines.push([x.reference, d.customer_name || custOf(x), d.policy_number, D.type(typeOf(x)), x.submitted_at, D.st(x.status), amt !== undefined && amt !== null ? amt / 100 : ''].map(esc).join(',')); });
    var a = h('a', { href: URL.createObjectURL(new Blob([lines.join('\r\n')], { type: 'text/csv' })), download: 'claims-desk.csv' }); document.body.appendChild(a); a.click(); a.remove();
  }
});
</script>
@endpush
