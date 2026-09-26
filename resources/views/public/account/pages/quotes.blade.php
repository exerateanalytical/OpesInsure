{{-- /account/quotes — the user's quotes (GET /mobile/quotes) with status and resume links. --}}
@extends('public.account.layout', ['title' => __('account_buy.list_t'), 'lede' => __('account_buy.list_d'), 'crumbs' => [[__('account_buy.quotes'), null]], 'active' => 'quotes'])
@include('public.account.buy.assets')
@section('content')
<section class="acard">
  <div class="acard-h"><span>{{ __('account_buy.list_t') }}</span><a class="dbtn dbtn-primary sm" href="/account/buy">+ {{ __('account_buy.new_quote') }}</a></div>
  <div data-page-body></div>
</section>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var B = Buy, T = B.T, h = Opes.h, box = Opes.$('[data-page-body]'), page = Number(ctx.params.get('page')) || 1;
  if (ctx.params.get('saved')) Opes.alert(T.saved, 'ok');

  function resume(q) {
    var st = String(q.status).toUpperCase();
    if (q.is_expired || st === 'EXPIRED') return [B.qurl(q.id), T.open];
    if (st === 'ACCEPTED') return [B.qurl(q.id, 'review'), T.resume];
    return [B.qurl(q.id), T.resume];
  }
  function load() {
    Opes.loading(box);
    Opes.api('/mobile/quotes', { raw: true, query: { page: page } }).then(function (j) {
      var d = (j && j.data) || {}, items = Array.isArray(d) ? d : (d.data || []), last = d.last_page || (j.meta && j.meta.last_page) || 1;
      if (!items.length) return Opes.empty(box, T.no_quotes, h('a', { class: 'dbtn dbtn-primary', href: '/account/buy' }, T.start_quote));
      Opes.clear(box).appendChild(h('div', { class: 'atable-wrap' }, h('table', { class: 'atable' },
        h('thead', null, h('tr', null, [T.col_quote, T.col_product, T.col_risk, T.col_status, T.col_created, T.col_expires, T.col_action].map(function (x) { return h('th', null, x); }))),
        h('tbody', null, items.map(function (q) {
          var r = resume(q), expired = q.is_expired || q.status === 'EXPIRED';
          return h('tr', null,
            h('td', null, h('a', { class: 'rowlink', href: r[0] }, q.quote_number || String(q.id).slice(0, 8).toUpperCase())),
            h('td', null, h('span', { class: 'bmark', style: 'display:inline-flex;gap:8px' }, Opes.icon(B.lineIcon(q.line_code)), B.lineName(q.line_code))),
            h('td', null, B.riskTitle(q.line_code, q.risk_facts), B.riskSub(q.line_code, q.risk_facts) ? h('small', { class: 'b-muted', style: 'display:block' }, B.riskSub(q.line_code, q.risk_facts)) : null),
            h('td', null, expired ? Opes.chip('EXPIRED', T.expired) : Opes.chip(q.status)),
            h('td', null, Opes.date(q.created_at)),
            h('td', null, Opes.date(q.expires_at)),
            h('td', null, h('a', { class: 'dbtn dbtn-outline sm', href: r[0] }, r[1], Opes.icon('arrow'))));
        })))));
      if (last > 1) box.appendChild(h('div', { class: 'bpager' },
        page > 1 ? h('a', { class: 'dbtn dbtn-outline sm', href: '?page=' + (page - 1) }, T.prev) : null,
        page < last ? h('a', { class: 'dbtn dbtn-outline sm', href: '?page=' + (page + 1) }, T.next) : null));
    }).catch(function (e) { Opes.fail(box, e); });
  }
  load();
});
</script>
@endpush
