{{-- /account/quotes — the user's quotes (GET /mobile/quotes) with status and resume links, and proposals the insurer
     counter-offered (GET /mobile/proposals, POST /mobile/proposals/{id}/counteroffer/{accept|decline}). --}}
@extends('public.account.layout', ['title' => __('account_buy.list_t'), 'lede' => __('account_buy.list_d'), 'crumbs' => [[__('account_buy.quotes'), null]], 'active' => 'quotes'])
@include('public.account.buy.assets')
@push('scripts')
<script>window.OPES_CUST = {!! json_encode(__('account_customer.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};</script>
@endpush
@section('content')
<section class="acard" data-counter hidden style="margin-bottom:16px"></section>
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
  /** Resume: POST /mobile/quotes/{id}/resume checks the quote can still be resumed (not expired / closed), then opens it. */
  function resumeBtn(q, r, expired) {
    if (expired) return h('a', { class: 'dbtn dbtn-outline sm', href: r[0] }, r[1], Opes.icon('arrow'));
    var b = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-resume': q.id }, r[1], Opes.icon('arrow'));
    b.addEventListener('click', function () {
      Opes.busy(b, true);
      Opes.api('/mobile/quotes/' + encodeURIComponent(q.id) + '/resume', { method: 'POST', body: {} })
        .then(function () { location.href = r[0]; })
        .catch(function (e) { Opes.busy(b, false); Opes.alert(e.message); });
    });
    return b;
  }
  /** Cancel: DELETE /mobile/quotes/{id} (QuoteService::cancel, own quotes only); open offers are withdrawn. */
  function cancelBtn(q) {
    var b = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', 'data-cancel': q.id }, Opes.icon('x'), T.cancel_quote);
    b.addEventListener('click', function () {
      if (!window.confirm(T.cancel_q)) return;
      Opes.busy(b, true);
      Opes.api('/mobile/quotes/' + encodeURIComponent(q.id), { method: 'DELETE' })
        .then(function () { Opes.alert(T.cancelled, 'ok'); load(); })
        .catch(function (e) { Opes.busy(b, false); Opes.alert(e.message); });
    });
    return b;
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
            h('td', null, h('div', { class: 'op-acts', style: 'display:flex;gap:6px;flex-wrap:wrap' }, resumeBtn(q, r, expired), expired || /^(CANCELLED|DECLINED|ACCEPTED|CONVERTED|EXPIRED|BOUND)$/.test(String(q.status).toUpperCase()) ? null : cancelBtn(q))));
        })))));
      if (last > 1) box.appendChild(h('div', { class: 'bpager' },
        page > 1 ? h('a', { class: 'dbtn dbtn-outline sm', href: '?page=' + (page - 1) }, T.prev) : null,
        page < last ? h('a', { class: 'dbtn dbtn-outline sm', href: '?page=' + (page + 1) }, T.next) : null));
    }).catch(function (e) { Opes.fail(box, e); });
  }
  load();

  // Proposals the insurer counter-offered: accept (then pay) or decline.
  var C = window.OPES_CUST.counter, cb = Opes.$('[data-counter]');
  function money(v) { return v === null || v === undefined ? '—' : Opes.money(v, { minor: true }); }
  function counters() {
    return Opes.list('/mobile/proposals', { per_page: 100 }).then(function (r) {
      var rows = (r.items || []).filter(function (p) { return String(p.status).toUpperCase() === 'COUNTEROFFERED'; });
      cb.hidden = !rows.length; if (!rows.length) return;
      Opes.clear(cb).append(h('h2', null, C.title), h('p', { class: 'sub' }, C.text));
      rows.forEach(function (p) {
        var co = p.counter_offer || {};
        function answer(a, btn) {
          if (a === 'decline' && !window.confirm(C.decline_q)) return;
          Opes.busy(btn, true);
          Opes.api('/mobile/proposals/' + encodeURIComponent(p.id) + '/counteroffer/' + a, { body: {} }).then(function () {
            if (a === 'accept') { location.href = '/account/payments/new?proposal=' + encodeURIComponent(p.id); return; }
            Opes.alert(C.declined, 'ok'); return counters();
          }).catch(function (err) { Opes.busy(btn, false); Opes.alert(err.message); });
        }
        var ya = h('button', { type: 'button', class: 'dbtn dbtn-primary sm', 'data-counter-accept': p.id, onclick: function () { answer('accept', ya); } }, Opes.icon('check'), C.accept);
        var no = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { answer('decline', no); } }, Opes.icon('x'), C.decline);
        cb.appendChild(h('div', { class: 'op-case', style: 'display:flex;gap:12px;align-items:center;flex-wrap:wrap' },
          h('div', { style: 'flex:1;min-width:200px' }, h('b', null, (p.carrier_name || '') + ' — ' + (p.product_name || B.lineName(p.line_code))),
            h('small', { class: 'b-muted', style: 'display:block' }, C.was + ' : ' + money(p.total_minor) + ' · ' + C.now + ' : ' + money(co.total_minor)), co.notes ? h('small', { style: 'display:block' }, co.notes) : null),
          ya, no));
      });
    }).catch(function () { cb.hidden = true; });
  }
  counters();
});
</script>
@endpush
