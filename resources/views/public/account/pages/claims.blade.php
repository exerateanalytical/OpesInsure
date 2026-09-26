{{-- /account/claims — design screens/compare_buy_flow/08_stage.png. Data: GET /mobile/claims (all pages) + GET /mobile/claims/drafts (Drafts tab). --}}
@php $L = __('account_claims.js'); @endphp
@extends('public.account.layout', ['title' => __('account_claims.list.title'), 'lede' => __('account_claims.list.lede'), 'crumbs' => [[__('account_claims.list.title'), null]], 'active' => 'claims'])
@include('public.account.claims.assets')
@section('content')
<div class="cl-top">
  <div class="cl-stats" data-stats>
    @foreach([['total', 'doc', 'blue'], ['approved', 'check', 'green'], ['progress', 'clock', 'amber'], ['rejected', 'x', 'red'], ['paid', 'piggy', 'violet']] as [$k, $ic, $tone])
      <div class="statc cl-stat"><span class="cl-sq {{ $tone }}">@include('public.partials.i', ['n' => $ic])</span><div><small class="lbl">{{ $L['stats'][$k] }}</small><b data-stat="{{ $k }}">–</b><small data-stat-sub="{{ $k }}">&nbsp;</small></div></div>
    @endforeach
  </div>
  <a class="dbtn dbtn-primary cl-newbtn" href="/account/claims/new">@include('public.partials.i', ['n' => 'edit']){{ $L['file_new'] }}</a>
</div>

<div class="agrid main-side cl-main">
  <section class="acard cl-listcard" aria-label="{{ __('account_claims.list.title') }}">
    <div class="tabs-u" role="tablist" data-tabs>
      @foreach(['all', 'progress', 'approved', 'rejected', 'draft'] as $t)
        <button type="button" role="tab" data-tab="{{ $t }}" aria-selected="{{ $t === 'all' ? 'true' : 'false' }}">{{ $L['tabs'][$t] }}@if($t !== 'all') <em class="cl-count t-{{ $t }}" data-count="{{ $t }}">0</em>@endif</button>
      @endforeach
    </div>
    <div class="cl-filters">
      <label class="cl-search">@include('public.partials.i', ['n' => 'search'])<input type="search" data-f="q" placeholder="{{ $L['search'] }}" aria-label="{{ $L['search'] }}"></label>
      <select data-f="line" aria-label="{{ $L['all_types'] }}"><option value="">{{ $L['all_types'] }}</option></select>
      <select data-f="status" aria-label="{{ $L['all_statuses'] }}"><option value="">{{ $L['all_statuses'] }}</option></select>
      <div class="cl-dates"><input type="date" data-f="from" aria-label="{{ $L['from'] }}" title="{{ $L['from'] }}">@include('public.partials.i', ['n' => 'arrow'])<input type="date" data-f="to" aria-label="{{ $L['to'] }}" title="{{ $L['to'] }}"></div>
    </div>
    <div data-page-body><div class="acct-loading" role="status"><span class="spin"></span>{{ __('account.js.loading') }}</div></div>
    <div class="cl-pager" data-pager hidden>
      <span data-showing></span>
      <div class="cl-pages" data-pages></div>
      <select data-f="per" aria-label="{{ str_replace(':n', '10', $L['per_page']) }}">@foreach([10, 20, 50] as $pp)<option value="{{ $pp }}">{{ str_replace(':n', $pp, $L['per_page']) }}</option>@endforeach</select>
    </div>
  </section>

  <aside class="cl-side">
    <section class="acard">
      <h2>{{ $L['steps_card']['t'] }}</h2>
      <p class="sub">{{ $L['steps_card']['d'] }}</p>
      <ol class="cl-steps-v">
        @foreach($L['steps_card']['steps'] as $i => [$st, $sd])
          <li><span class="num">{{ $i + 1 }}</span><div><b>{{ $st }}</b><small>{{ $sd }}</small></div></li>
        @endforeach
      </ol>
      <a class="dbtn dbtn-primary cl-block" href="/account/claims/new">{{ $L['steps_card']['start'] }}@include('public.partials.i', ['n' => 'arrow'])</a>
    </section>
    <section class="acard cl-help">
      <div class="cl-help-h"><span class="cl-hic">@include('public.partials.i', ['n' => 'headset'])</span><div><b>{{ $L['help']['t'] }}</b><small>{{ $L['help']['d'] }}</small></div></div>
      <div class="cl-2btn">
        <a class="dbtn dbtn-outline sm" href="/contact?topic=claims">@include('public.partials.i', ['n' => 'phone']){{ $L['help']['call'] }}</a>
        <a class="dbtn dbtn-outline sm" href="/account/support">@include('public.partials.i', ['n' => 'chat']){{ $L['help']['chat'] }}</a>
      </div>
    </section>
    <section class="acard">
      <h2 class="cl-guide-h"><span>@include('public.partials.i', ['n' => 'doc']){{ $L['guide']['t'] }}</span></h2>
      <ul class="cl-guide">
        @foreach($L['guide']['links'] as [$gl, $gh])
          <li><a href="{{ $gh }}">@include('public.partials.i', ['n' => 'doc'])<span>{{ $gl }}</span>@include('public.partials.i', ['n' => 'chev'])</a></li>
        @endforeach
      </ul>
    </section>
  </aside>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var K = OpesClaims, T = K.T, h = Opes.h, $ = Opes.$, $$ = Opes.$$;
  var body = $('[data-page-body]');
  var state = { tab: 'all', page: 1, per: 10 };
  var claims = [];

  function reported(c) { return c.__draft ? c.updated_at : (c.submitted_at || c.created_at); }
  /** A saved draft (claim_drafts) shaped like a claim row. */
  function fromDraft(d) {
    var pl = d.payload || {};
    return { __draft: true, id: d.id, status: 'DRAFT', policy: d.policy || null, updated_at: d.updated_at, incident_type: pl.incident_type,
      incident_location: pl.incident_location, estimated_loss_minor: pl.estimated_loss_minor, approved_amount_minor: null };
  }
  function setStats() {
    var n = claims.filter(function (c) { return !c.__draft; }).length, cnt = { progress: 0, approved: 0, rejected: 0, draft: 0 }, paid = 0;
    claims.forEach(function (c) {
      var b = K.bucket(c); if (cnt[b] !== undefined) cnt[b]++;
      var s = K.up(c.status);
      if ((s === 'PAID' || s === 'CLOSED') && c.approved_amount_minor) paid += Number(c.approved_amount_minor);
    });
    function pct(x) { return n ? (Math.round(x / n * 1000) / 10) + '%' : '0%'; }
    var v = { total: [n, T.stats.all_time], approved: [cnt.approved, pct(cnt.approved)], progress: [cnt.progress, pct(cnt.progress)],
      rejected: [cnt.rejected, pct(cnt.rejected)], paid: [Opes.money(paid / 100), T.stats.all_claims] };
    Object.keys(v).forEach(function (k) { $('[data-stat="' + k + '"]').textContent = v[k][0]; $('[data-stat-sub="' + k + '"]').textContent = v[k][1]; });
    Object.keys(cnt).forEach(function (k) { var e = $('[data-count="' + k + '"]'); if (e) e.textContent = cnt[k]; });
  }
  function fillFilters() {
    var lines = {}, sts = {};
    claims.forEach(function (c) { var l = K.line(c.policy); if (l) lines[l] = K.lineLabel(c.policy); sts[K.shown(c)] = K.statusLabel(K.shown(c)); });
    var ls = $('[data-f="line"]'), ss = $('[data-f="status"]');
    Object.keys(lines).forEach(function (k) { ls.appendChild(h('option', { value: k }, lines[k])); });
    Object.keys(sts).forEach(function (k) { ss.appendChild(h('option', { value: k }, sts[k])); });
  }
  function val(f) { var e = $('[data-f="' + f + '"]'); return e ? e.value : ''; }
  function filtered() {
    var q = val('q').trim().toLowerCase(), line = val('line'), st = val('status'), from = val('from'), to = val('to');
    return claims.filter(function (c) {
      if (state.tab !== 'all' && K.bucket(c) !== state.tab) return false;
      if (line && K.line(c.policy) !== line) return false;
      if (st && K.shown(c) !== st) return false;
      var d = (reported(c) || '').slice(0, 10);
      if (from && d < from) return false;
      if (to && d > to) return false;
      if (q) {
        var hay = [c.claim_number, K.itemTitle(c.policy), K.itemSub(c.policy), c.policy && c.policy.policy_number, K.typeLabel(K.incidentType(c)), K.statusLabel(K.shown(c)), c.incident_location].join(' ').toLowerCase();
        if (hay.indexOf(q) < 0) return false;
      }
      return true;
    }).sort(function (a, b) { return String(reported(b)).localeCompare(String(reported(a))); });
  }
  function discard(c, btn) {
    if (!window.confirm(T.drafts.discard_confirm)) return;
    Opes.busy(btn, true);
    Opes.api('/mobile/claims/drafts/' + encodeURIComponent(c.id), { method: 'DELETE' }).then(function () {
      claims = claims.filter(function (x) { return x !== c; }); setStats(); render(); Opes.alert(T.drafts.discarded, 'ok');
    }).catch(function (e) { Opes.busy(btn, false); Opes.alert(e.message); });
  }
  function row(c) {
    var p = c.policy || {}, t = K.incidentType(c), href = c.__draft ? '/account/claims/new?draft=' + encodeURIComponent(c.id) : '/account/claims/' + encodeURIComponent(c.id);
    var dt = reported(c);
    return h('tr', null,
      h('td', null, h('a', { class: 'rowlink', href: href }, c.__draft ? T.drafts.untitled : (c.claim_number || '—'))),
      h('td', null, Opes.date(dt), h('small', { class: 'cl-sub' }, dt ? new Intl.DateTimeFormat(Opes.locale === 'fr' ? 'fr-FR' : 'en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Douala' }).format(new Date(dt)) : '')),
      h('td', null, K.itemTitle(p), h('small', { class: 'cl-sub' }, p.policy_number || '')),
      h('td', null, h('span', { class: 'cl-type' }, Opes.icon(K.typeIcon(t)), K.typeLabel(t))),
      h('td', null, K.chip(K.shown(c))),
      h('td', { class: 'num' }, K.amount(c.estimated_loss_minor)),
      h('td', { class: 'num' }, K.amount(c.approved_amount_minor)),
      h('td', null, c.__draft ? h('span', { style: 'display:inline-flex;gap:6px' }, h('a', { class: 'dbtn dbtn-outline sm cl-view', href: href, 'data-resume': c.id }, T.drafts.resume),
        (function () { var b = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-discard': c.id, onclick: function () { discard(c, b); } }, T.drafts.discard); return b; })())
        : h('a', { class: 'dbtn dbtn-outline sm cl-view', href: href }, T.view)));
  }
  function render() {
    var rows = filtered(), pager = $('[data-pager]');
    if (!rows.length) { pager.hidden = true; return Opes.empty(body, state.tab === 'draft' ? T.drafts.none : claims.length ? T.none_filtered : T.none, claims.length ? null : h('a', { class: 'dbtn dbtn-primary sm', href: '/account/claims/new' }, T.file_new)); }
    var pages = Math.max(1, Math.ceil(rows.length / state.per));
    if (state.page > pages) state.page = pages;
    var start = (state.page - 1) * state.per, slice = rows.slice(start, start + state.per);
    var C = T.cols;
    Opes.clear(body).appendChild(h('div', { class: 'atable-wrap' }, h('table', { class: 'atable cl-table' },
      h('thead', null, h('tr', null, [C.no, C.reported, C.policy, C.type, C.status, C.claimed, C.approved, C.actions].map(function (x) { return h('th', { scope: 'col' }, x); }))),
      h('tbody', null, slice.map(row)))));
    pager.hidden = false;
    $('[data-showing]').textContent = K.fmt(T.showing, { from: start + 1, to: start + slice.length, total: rows.length });
    var pg = Opes.clear($('[data-pages]'));
    pg.appendChild(h('button', { type: 'button', class: 'pgb', 'aria-label': T.prev, disabled: state.page <= 1 ? true : null, onclick: function () { state.page--; render(); } }, Opes.icon('chev-left')));
    for (var i = 1; i <= pages; i++) (function (i) { pg.appendChild(h('button', { type: 'button', class: 'pgb' + (i === state.page ? ' on' : ''), 'aria-current': i === state.page ? 'page' : null, onclick: function () { state.page = i; render(); } }, String(i))); })(i);
    pg.appendChild(h('button', { type: 'button', class: 'pgb', 'aria-label': T.next, disabled: state.page >= pages ? true : null, onclick: function () { state.page++; render(); } }, Opes.icon('chev')));
  }

  $$('[data-tab]').forEach(function (b) { b.addEventListener('click', function () {
    state.tab = b.dataset.tab; state.page = 1;
    $$('[data-tab]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); }); render();
  }); });
  $$('[data-f]').forEach(function (e) { e.addEventListener(e.tagName === 'INPUT' && e.type === 'search' ? 'input' : 'change', function () {
    if (e.dataset.f === 'per') state.per = Number(e.value) || 10; state.page = 1; render();
  }); });

  return Promise.all([K.all('/mobile/claims'), Opes.api('/mobile/claims/drafts').catch(function () { return []; })]).then(function (r) {
    var drafts = Array.isArray(r[1]) ? r[1] : (r[1] && r[1].data) || [];
    claims = r[0].concat(drafts.map(fromDraft));
    if (location.hash === '#drafts') { var dt = $('[data-tab="draft"]'); if (dt) { state.tab = 'draft'; $$('[data-tab]').forEach(function (x) { x.setAttribute('aria-selected', x === dt ? 'true' : 'false'); }); } } setStats(); fillFilters(); render();
  }).catch(function (e) { Opes.fail(body, e); });
});
</script>
@endpush
