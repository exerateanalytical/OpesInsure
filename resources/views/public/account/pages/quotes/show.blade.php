{{-- /account/quotes/{quote} — "Compare Quotes" + detailed comparison (designs: compare_buy_flow/15_stage.png, 03_stage.png).
     GET /quotes/{id} -> rated offers side by side; POST /quotes/{id}/rate when not priced yet. --}}
@extends('public.account.layout', ['title' => __('account_buy.compare_t'), 'lede' => __('account_buy.compare_d'), 'crumbs' => [[__('account_buy.quotes'), '/account/quotes'], [__('account_buy.compare_t'), null]], 'active' => 'quotes'])
@include('public.account.buy.assets')
@section('content')
<div class="buy" data-page-body>
  <div data-steps></div>
  <div class="agrid main-side buy-grid">
    <div class="buy-main">
      <section class="acard">
        <div class="acard-h"><span>{{ __('account_buy.available_h') }}</span>
          <label class="bhead-tools">{{ __('account_buy.sort') }}
            <select data-sort><option value="rec">{{ __('account_buy.js.sort_rec') }}</option><option value="price">{{ __('account_buy.js.sort_price') }}</option><option value="covers">{{ __('account_buy.js.sort_covers') }}</option></select>
          </label>
        </div>
        <p class="sub">{{ __('account_buy.available_s') }}</p>
        <div data-offers></div>
      </section>
      <section class="acard" data-detail-card hidden>
        <h2>{{ __('account_buy.detail_h') }}</h2>
        <p class="sub">{{ __('account_buy.detail_s') }}</p>
        <div class="atable-wrap" data-detail></div>
      </section>
      <div class="binfo">@include('public.partials.i', ['n' => 'help'])<span>{{ __('account_buy.indicative') }}</span></div>
    </div>
    <aside class="buy-side">
      <div data-summary></div>
      <div data-selected></div>
    </aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var B = Buy, T = B.T, h = Opes.h, $ = Opes.$, id = ctx.ids[0], params = ctx.params;
  var box = $('[data-offers]'), quote = null, offers = [], sc = null, chosen = null, open = {};
  $('[data-steps]').appendChild(B.steps(2));
  if (params.get('rate_error')) Opes.alert(T.rate_failed.replace(':m', params.get('rate_error') === '1' ? '' : params.get('rate_error')), 'info');

  function load() {
    Opes.loading(box);
    return B.loadQuote(id).then(function (r) {
      quote = r.quote; offers = r.offers;
      return B.schema(quote.line_code).then(function (s) { sc = s; render(); });
    }).catch(function (e) { Opes.fail(box, e); });
  }

  function sorted(list) {
    var mode = $('[data-sort]').value;
    return list.slice().sort(function (a, b) {
      if (mode === 'price') return a.total_minor - b.total_minor;
      if (mode === 'covers') return B.covers(b).length - B.covers(a).length || a.total_minor - b.total_minor;
      return (a.comparison_rank || 99) - (b.comparison_rank || 99) || a.total_minor - b.total_minor;
    });
  }

  function side() {
    Opes.clear($('[data-summary]')).appendChild(B.summary({ quote: quote, offer: chosen, showSel: false, schema: sc, status: quote.status, edit: '/account/buy?line=' + encodeURIComponent(String(quote.line_code).toLowerCase()) }));
    var sel = $('[data-selected]'); Opes.clear(sel);
    if (!chosen) return;
    var accepted = chosen.status === 'ACCEPTED';
    var next = accepted ? B.qurl(id, 'review', { offer: chosen.id }) : B.qurl(id, 'customize', { offer: chosen.id });
    var pdf = h('button', { type: 'button', class: 'dbtn dbtn-outline' }, Opes.icon('download'), T.download_pdf);
    pdf.addEventListener('click', function () {
      var win = window.open('about:blank', '_blank');
      Opes.busy(pdf, true);
      Opes.api('/quotes/' + id + '/document', { blob: true }).then(function (b) { var u = URL.createObjectURL(b); if (win) win.location.href = u; else location.href = u; })
        .catch(function () { if (win) win.close(); Opes.alert(T.pdf_failed); }).finally(function () { Opes.busy(pdf, false); });
    });
    sel.appendChild(h('section', { class: 'acard bsel-card' }, h('div', { class: 'acard-h' }, h('span', null, T.selected_quote), chosen.comparison_rank === 1 ? h('span', { class: 'bbest' }, Opes.icon('check'), T.best_value) : null),
      B.mark(chosen), h('div', { class: 'bprice-big' }, h('b', null, B.minor(chosen.total_minor)), h('small', null, T.per_year)),
      accepted ? h('p', { class: 'b-muted' }, T.accepted_note) : null,
      h('div', { class: 'bactions' }, h('a', { class: 'dbtn dbtn-primary', href: next }, accepted ? T.to_review : T.continue_customize, Opes.icon('arrow')), pdf)));
  }

  function card(o) {
    var rec = o.comparison_rank === 1, hl = chosen && chosen.id === o.id;
    var detail = h('div', { class: 'bdetail', hidden: !open[o.id] },
      B.priceRows(o),
      B.covers(o).length ? h('div', null, h('h4', null, T.limit + ' / ' + T.deductible), h('ul', null, B.covers(o).map(function (c) {
        return h('li', null, B.tr(c.name) + ': ' + (c.limit_minor ? B.minor(c.limit_minor) : '—') + (c.deductible_minor ? ' · ' + T.deductible + ' ' + B.minor(c.deductible_minor) : ''));
      }))) : null,
      B.exclusions(o).length ? h('div', null, h('h4', null, T.exclusions), h('ul', null, B.exclusions(o).map(function (x) { return h('li', null, B.tr(x.name) || x.code); }))) : null);
    var more = h('button', { type: 'button', class: 'dbtn dbtn-outline', 'aria-expanded': open[o.id] ? 'true' : 'false' }, Opes.icon('doc'), open[o.id] ? T.hide_details : T.details);
    more.addEventListener('click', function () { open[o.id] = !open[o.id]; detail.hidden = !open[o.id]; more.setAttribute('aria-expanded', String(!!open[o.id])); more.lastChild.textContent = open[o.id] ? T.hide_details : T.details; });
    var pick = h('button', { type: 'button', class: 'dbtn ' + (hl || rec ? 'dbtn-primary' : 'dbtn-outline') }, hl ? T.selected : T.select_quote, Opes.icon(hl ? 'check' : 'arrow'));
    pick.addEventListener('click', function () {
      chosen = o;
      if (o.status === 'ACCEPTED') location.href = B.qurl(id, 'review', { offer: o.id });
      else location.href = B.qurl(id, 'customize', { offer: o.id });
    });
    return h('article', { class: 'boffer' + (rec ? ' rec' : '') + (hl && !rec ? ' hl' : '') },
      rec ? h('span', { class: 'ribbon' }, T.recommended) : (o.status === 'ACCEPTED' ? h('span', { class: 'ribbon' }, T.selected) : null),
      B.mark(o),
      h('div', { class: 'bprice-big' }, h('b', null, B.minor(o.total_minor)), h('small', null, T.per_year)),
      rec ? h('span', { class: 'bbest' }, Opes.icon('check'), T.best_value) : null,
      B.coverList(o, false, 8), detail, h('div', { class: 'bbtns' }, pick, more));
  }

  function table(list) {
    var codes = [], names = {};
    list.forEach(function (o) { B.covers(o).forEach(function (c) { if (codes.indexOf(c.code) < 0) { codes.push(c.code); names[c.code] = B.tr(c.name) || B.humanize(c.code); } }); });
    var cell = function (o, code) {
      var c = B.covers(o).filter(function (x) { return x.code === code; })[0];
      return c ? h('td', { class: 'yes' }, c.limit_minor ? B.minor(c.limit_minor) : T.included) : h('td', { class: 'no' }, T.not_included);
    };
    var money = function (label, key, strong) { return h('tr', null, h(strong ? 'td' : 'th', { scope: 'row' }, label), list.map(function (o) { return h('td', null, B.minor(o[key])); })); };
    return h('table', { class: 'atable bctable' },
      h('thead', null, h('tr', null, h('th', null, T.coverage_item), list.map(function (o) { return h('th', null, B.carrierName(o)); }))),
      h('tbody', null, codes.map(function (code) { return h('tr', null, h('td', null, names[code]), list.map(function (o) { return cell(o, code); })); }),
        money(T.base_premium, 'premium_minor'), money(T.taxes, 'tax_minor'), money(T.fees, 'fee_minor')),
      h('tfoot', null, money(T.total, 'total_minor', true)));
  }

  function rateBtn(label) {
    var b = h('button', { type: 'button', class: 'dbtn dbtn-primary' }, label || T.rate_now);
    b.addEventListener('click', function () {
      Opes.busy(b, true); Opes.alert('');
      Opes.api('/quotes/' + id + '/rate', { method: 'POST', body: {} }).then(load).catch(function (e) { Opes.busy(b, false); Opes.alert(e.message); });
    });
    return b;
  }

  function render() {
    var live = B.liveOffers(offers);
    chosen = B.pickOffer(offers, params);
    side();
    $('[data-detail-card]').hidden = live.length < 1;
    if (quote.is_expired || quote.status === 'EXPIRED') {
      Opes.alert(T.expired_t + ' — ' + T.expired_d, 'info');
    }
    if (!live.length) {
      var declined = offers.filter(function (o) { return o.status === 'DECLINED' || o.decline_reason_code; });
      if (!offers.length && !quote.rated_at) {
        return Opes.empty(box, T.not_rated_t + '. ' + T.not_rated_d, quote.is_expired ? h('a', { class: 'dbtn dbtn-primary', href: '/account/buy?line=' + quote.line_code.toLowerCase() }, T.change_details) : rateBtn());
      }
      Opes.empty(box, T.no_offers_t + '. ' + T.no_offers_d, h('a', { class: 'dbtn dbtn-outline', href: '/account/buy?line=' + quote.line_code.toLowerCase() }, T.change_details));
      if (declined.length) box.appendChild(h('ul', { class: 'b-muted' }, declined.map(function (o) { return h('li', null, T.declined_by.replace(':c', B.carrierName(o)) + (o.decline_reason_code ? ' (' + Opes.label(o.decline_reason_code) + ')' : '')); })));
      return;
    }
    Opes.clear(box).appendChild(h('div', { class: 'boffers' }, sorted(live).map(card)));
    Opes.clear($('[data-detail]')).appendChild(table(sorted(live)));
  }
  $('[data-sort]').addEventListener('change', function () { if (quote) render(); });
  return load();
});
</script>
@endpush
