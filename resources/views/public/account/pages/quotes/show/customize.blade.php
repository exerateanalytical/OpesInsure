{{-- /account/quotes/{quote}/customize?offer= — "Customize Your Plan" (design: compare_buy_flow/04_stage.png).
     Coverage level = the line's cover-level risk fact; changing it prices the same details
     at the new level as a new quote (POST /quotes + rate). Limits are the offer's tariff limits, read-only:
     the API has no per-cover limit adjustment. --}}
@extends('public.account.layout', ['title' => __('account_buy.customize_t'), 'lede' => __('account_buy.customize_d'), 'crumbs' => [[__('account_buy.quotes'), '/account/quotes'], [__('account_buy.compare_t'), null], [__('account_buy.customize_t'), null]], 'active' => 'quotes'])
@include('public.account.buy.assets')
@section('content')
<div class="buy" data-page-body>
  <div data-steps></div>
  <div class="agrid main-side buy-grid">
    <div class="buy-main" data-main></div>
    <aside class="buy-side" data-side></aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var B = Buy, T = B.T, h = Opes.h, $ = Opes.$, id = ctx.ids[0], params = ctx.params;
  var main = $('[data-main]'), sideBox = $('[data-side]');
  $('[data-steps]').appendChild(B.steps(3));
  // Compare crumb links back to this quote.
  Opes.$$('.crumbs span').forEach(function (s) { if (s.textContent === @json(__('account_buy.compare_t'))) s.replaceWith(h('a', { href: B.qurl(id) }, s.textContent)); });
  Opes.loading(main);

  var LEVEL_KEYS = ['cover_type', 'cover_package', 'plan_type', 'cover_scope'];
  var LEVEL_ICON = { THIRD_PARTY: 'shield', THIRD_PARTY_FIRE_THEFT: 'lock', COMPREHENSIVE: 'star' };

  B.loadQuote(id).then(function (r) {
    return B.schema(r.quote.line_code).then(function (sc) { render(r.quote, r.offers, sc); });
  }).catch(function (e) { Opes.fail(main, e); });

  function reprice(quote, key, value, offer, btn) {
    var facts = Object.assign({}, quote.risk_facts); facts[key] = value;
    Opes.busy(btn, true); Opes.alert(T.repricing, 'info');
    var keep = offer && offer.product ? offer.product.code : '';
    // A new quote with the changed level, priced by every insurer. (PATCH /quotes/{id} + re-rate is not used: re-rating
    // an amended quote currently fails on the quote_offers_one_per_tariff unique constraint.)
    // customer_id accepts the party id (QuoteController maps either).
    Opes.api('/quotes', { body: { customer_id: quote.party_id, line_code: quote.line_code, channel: ctx.kind === 'agent' ? 'AGENT' : 'B2C', risk_facts: facts, risk_asset_id: quote.risk_asset_id || undefined } })
      .then(function (q) { return Opes.api('/quotes/' + q.id + '/rate', { method: 'POST', body: {} }).then(function () { return q.id; }); })
      .then(function (qid) {
        location.href = B.qurl(qid, 'customize', { product: keep });
      }).catch(function (e) { Opes.busy(btn, false); Opes.alert((e && e.message) || Opes.t.error); });
  }

  function render(quote, offers, sc) {
    var live = B.liveOffers(offers), offer = B.pickOffer(offers, params);
    if (params.get('offer') && (!offer || offer.id !== params.get('offer'))) Opes.alert(T.offer_gone, 'info');
    if (!offer) return Opes.empty(main, T.no_offers_t, h('a', { class: 'dbtn dbtn-primary', href: B.qurl(id) }, T.rate_now));
    Opes.clear(main); Opes.clear(sideBox);

    // 1. Coverage level
    var key = LEVEL_KEYS.filter(function (k) { var f = B.fieldOf(sc, k); return f && f.options && f.options.length; })[0];
    var levelCard = h('section', { class: 'acard' }, h('h2', null, T.level_h), h('p', { class: 'sub' }, key ? T.level_s : T.no_levels));
    if (key) {
      var cur = String(quote.risk_facts[key] || '');
      levelCard.appendChild(h('div', { class: 'blevels' }, B.fieldOf(sc, key).options.map(function (o) {
        var isCur = String(o.value) === cur;
        var btn = h('button', { type: 'button', class: 'dbtn ' + (isCur ? 'dbtn-primary' : 'dbtn-outline'), disabled: isCur, title: isCur ? T.level_same : null }, isCur ? T.selected : T.choose.replace('…', ''), isCur ? Opes.icon('check') : null);
        if (!isCur) btn.addEventListener('click', function () { reprice(quote, key, o.value, offer, btn); });
        return h('div', { class: 'blevel' + (isCur ? ' cur' : '') }, Opes.icon(LEVEL_ICON[o.value] || 'shield'), h('b', null, B.optLabel(o)),
          (T.cover_desc || {})[o.value] ? h('small', null, T.cover_desc[o.value]) : null,
          isCur ? h('strong', null, B.minor(offer.total_minor)) : null, btn);
      })));
    }
    main.appendChild(levelCard);

    // 2. Coverage limits (read-only)
    var cov = B.covers(offer);
    main.appendChild(h('section', { class: 'acard' }, h('h2', null, T.limits_h), h('p', { class: 'sub' }, T.limits_s),
      cov.length ? h('div', { class: 'atable-wrap' }, h('table', { class: 'atable' },
        h('thead', null, h('tr', null, h('th', null, T.coverage_item), h('th', null, T.limit), h('th', null, T.deductible), h('th', null, ''))),
        h('tbody', null, cov.map(function (c) {
          return h('tr', null, h('td', null, B.tr(c.name) || B.humanize(c.code)), h('td', null, c.limit_minor ? B.minor(c.limit_minor) : T.included),
            h('td', null, c.deductible_minor ? B.minor(c.deductible_minor) : '—'), h('td', null, c.mandatory ? Opes.chip('ACTIVE', T.included) : c.optional ? h('span', { class: 'st st-info' }, T.optional) : Opes.chip('ACTIVE', T.included)));
        })))) : h('p', { class: 'b-muted' }, T.no_covers),
      h('p', { class: 'bnote' }, T.limits_note)));

    // 3. Other plans
    var others = live.filter(function (o) { return o.id !== offer.id; });
    if (others.length) main.appendChild(h('section', { class: 'acard' }, h('h2', null, T.others_h), h('p', { class: 'sub' }, T.others_s),
      h('div', { class: 'bothers' }, others.map(function (o) {
        return h('a', { class: 'bother', href: B.qurl(id, 'customize', { offer: o.id }) }, h('span', { class: 'bmark' }, h('span', { class: 'bm-t' }, h('b', null, B.carrierName(o)), h('strong', null, B.minor(o.total_minor)))), Opes.icon('chev'));
      }))));

    // Side: selected plan + estimated premium
    sideBox.appendChild(h('section', { class: 'acard' }, h('div', { class: 'acard-h' }, h('span', null, T.your_plan)), B.mark(offer), h('div', { style: 'margin-top:12px' }, B.coverList(offer, true, 6)),
      h('a', { class: 'dbtn dbtn-outline sm', style: 'margin-top:12px', href: B.qurl(id, '', { offer: offer.id }) }, T.details, Opes.icon('arrow'))));
    sideBox.appendChild(h('section', { class: 'acard' }, h('div', { class: 'acard-h' }, h('span', null, T.estimated_h), offer.comparison_rank === 1 ? h('span', { class: 'bbest' }, Opes.icon('check'), T.best_value) : null),
      h('div', { class: 'bprice-big', style: 'border:0;padding:0;margin:6px 0 12px' }, h('b', null, B.minor(offer.total_minor)), h('small', null, T.per_year)),
      B.priceRows(offer), h('div', { class: 'btotal' }, h('span', null, T.total), h('b', null, B.minor(offer.total_minor))),
      h('a', { class: 'dbtn dbtn-primary bbig', style: 'margin-top:14px', href: B.qurl(id, 'review', { offer: offer.id }) }, T.to_review_btn, Opes.icon('arrow'))));
    sideBox.appendChild(B.summary({ quote: quote, schema: sc, status: quote.status }));
  }
});
</script>
@endpush
