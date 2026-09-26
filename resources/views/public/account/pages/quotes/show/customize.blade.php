{{-- /account/quotes/{quote}/customize?offer= — "Customize Your Plan" (design: compare_buy_flow/04_stage.png).
     Every change (coverage level, optional covers = risk_facts.selected_coverages, limits = risk_facts.cover_limits)
     amends THIS quote (PATCH /quotes/{id}) and re-rates it (POST /quotes/{id}/rate); every price shown comes from
     the rating API. A limit is editable only where the offer says limit_adjustable (the insurer's tariff prices it);
     deductibles and other limits stay read-only. --}}
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

  // Amend this quote's facts and re-rate it with every insurer, then reopen the same plan (offer ids change on re-rate).
  function reprice(quote, patch, offer, btn) {
    var facts = Object.assign({}, quote.risk_facts, patch);
    Opes.busy(btn, true); Opes.alert(T.repricing, 'info');
    var keep = offer && offer.product ? offer.product.code : '';
    Opes.api('/quotes/' + quote.id, { method: 'PATCH', body: { risk_facts: facts } })
      .then(function () { return Opes.api('/quotes/' + quote.id + '/rate', { method: 'POST', body: {} }); })
      .then(function () { location.href = B.qurl(quote.id, 'customize', { product: keep }); })
      .catch(function (e) { Opes.busy(btn, false); Opes.alert((e && e.message) || Opes.t.error); });
  }

  function coverLabel(c) { return B.tr(c.name) || B.humanize(c.code); }
  function coverCharge(offer, code) {
    var l = (offer.calculation_breakdown || []).filter(function (x) { return x.kind === 'COVERAGE' && x.code === code; })[0];
    return l ? l.amount_minor : null;
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
        if (!isCur) btn.addEventListener('click', function () { var p = {}; p[key] = o.value; reprice(quote, p, offer, btn); });
        return h('div', { class: 'blevel' + (isCur ? ' cur' : '') }, Opes.icon(LEVEL_ICON[o.value] || 'shield'), h('b', null, B.optLabel(o)),
          (T.cover_desc || {})[o.value] ? h('small', null, T.cover_desc[o.value]) : null,
          isCur ? h('strong', null, B.minor(offer.total_minor)) : null, btn);
      })));
    }
    main.appendChild(levelCard);

    // 2. Optional covers (risk_facts.selected_coverages; absent = every optional cover the offer lists)
    var snap = offer.coverage_snapshot || {}, cov = B.covers(offer), avail = snap.optional_available || [];
    var optional = cov.filter(function (c) { return c.optional; }).concat(avail);
    var chosen = Array.isArray(quote.risk_facts.selected_coverages) ? quote.risk_facts.selected_coverages.slice()
      : cov.filter(function (c) { return c.optional; }).map(function (c) { return c.code; });
    main.appendChild(h('section', { class: 'acard', 'data-optional-covers': '' }, h('h2', null, T.opt_h), h('p', { class: 'sub' }, optional.length ? T.opt_s : T.opt_none),
      optional.length ? h('div', { class: 'atable-wrap' }, h('table', { class: 'atable' }, h('tbody', null, optional.map(function (c) {
        var on = chosen.indexOf(c.code) >= 0, charge = on ? coverCharge(offer, c.code) : null;
        var btn = h('button', { type: 'button', class: 'dbtn sm ' + (on ? 'dbtn-outline' : 'dbtn-primary'), 'data-cover-toggle': c.code, 'aria-pressed': on ? 'true' : 'false' }, on ? T.remove_cover : T.add_cover);
        btn.addEventListener('click', function () {
          var next = on ? chosen.filter(function (x) { return x !== c.code; }) : chosen.concat([c.code]);
          reprice(quote, { selected_coverages: next }, offer, btn);
        });
        return h('tr', null, h('td', null, h('b', null, coverLabel(c)), h('br'), h('small', { class: 'b-muted' },
            charge !== null ? T.cover_charge.replace(':amount', B.minor(charge)) : c.tariff_priced === false ? T.cover_no_charge : '')),
          h('td', null, on ? Opes.chip('ACTIVE', T.included) : h('span', { class: 'st st-info' }, T.not_included)), h('td', null, btn));
      })))) : null));

    // 3. Coverage limits (editable only where the tariff prices the limit: limit_adjustable)
    var limits = Object.assign({}, quote.risk_facts.cover_limits || {});
    main.appendChild(h('section', { class: 'acard' }, h('h2', null, T.limits_h), h('p', { class: 'sub' }, T.limits_s),
      cov.length ? h('div', { class: 'atable-wrap' }, h('table', { class: 'atable' },
        h('thead', null, h('tr', null, h('th', null, T.coverage_item), h('th', null, T.limit), h('th', null, T.deductible), h('th', null, ''))),
        h('tbody', null, cov.map(function (c) {
          var limitCell;
          if (c.limit_adjustable) {
            var inp = h('input', { type: 'number', min: '1', step: '1', style: 'max-width:160px', value: c.limit_minor ? Math.round(c.limit_minor / 100) : '', 'aria-label': T.limit + ' ' + coverLabel(c), 'data-cover-limit': c.code });
            var go = h('button', { type: 'button', class: 'dbtn dbtn-outline sm' }, T.limit_apply);
            go.addEventListener('click', function () {
              var v = Number(inp.value);
              if (!(v > 0) || Math.floor(v) !== v) return Opes.alert(T.limit_invalid);
              var next = Object.assign({}, limits); next[c.code] = v * 100;
              reprice(quote, { cover_limits: next }, offer, go);
            });
            limitCell = h('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap' }, inp, go);
          } else {
            limitCell = h('span', null, c.limit_minor ? B.minor(c.limit_minor) : T.included, h('br'), h('small', { class: 'b-muted' }, T.limit_fixed));
          }
          return h('tr', null, h('td', null, coverLabel(c)), h('td', null, limitCell),
            h('td', null, c.deductible_minor ? B.minor(c.deductible_minor) : '—'), h('td', null, c.optional ? h('span', { class: 'st st-info' }, T.optional) : Opes.chip('ACTIVE', T.included)));
        })))) : h('p', { class: 'b-muted' }, T.no_covers),
      cov.some(function (c) { return !c.limit_adjustable; }) ? h('p', { class: 'bnote' }, T.limits_note) : null));

    // 4. Other plans
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
