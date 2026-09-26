{{-- /account/quotes/{quote}/review?offer=&proposal= — "Review & Payment" / "Review & Submit" (designs: compare_buy_flow/05_stage.png, 16_stage.png).
     Same purchase path as the mobile app: accept offer -> POST /proposals -> declarations (PUT disclosures, attest, submit)
     -> once payable, POST /payments {proposal_id, provider, payer_phone_e164, idempotency_key} + /initiate -> confirmation. --}}
@extends('public.account.layout', ['title' => __('account_buy.review_t'), 'lede' => __('account_buy.review_d'), 'crumbs' => [[__('account_buy.quotes'), '/account/quotes'], [__('account_buy.compare_t'), null], [__('account_buy.review_t'), null]], 'active' => 'quotes'])
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
  var quote = null, offers = [], offer = null, sc = null, proposal = null, answers = {};
  $('[data-steps]').appendChild(B.steps(4));
  Opes.$$('.crumbs span').forEach(function (s) { if (s.textContent === @json(__('account_buy.compare_t'))) s.replaceWith(h('a', { href: B.qurl(id) }, s.textContent)); });
  Opes.loading(main);

  var EDITABLE = ['DRAFT', 'DISCLOSURES_PENDING', 'DOCUMENTS_PENDING', 'MORE_INFORMATION'];
  var PAYABLE = ['APPROVED', 'PAYMENT_PENDING'];
  var PAID = ['PAID', 'ISSUED'];
  var PAY_OPEN = ['CREATED', 'PENDING_CUSTOMER', 'PROCESSING'];

  function setUrl() {
    var q = new URLSearchParams(location.search);
    if (offer) q.set('offer', offer.id);
    if (proposal) q.set('proposal', proposal.id);
    history.replaceState(null, '', location.pathname + '?' + q.toString());
  }
  function findProposal() {
    if (params.get('proposal')) return Opes.api('/proposals/' + encodeURIComponent(params.get('proposal'))).catch(function () { return null; });
    if (!offer || offer.status !== 'ACCEPTED') return Promise.resolve(null);
    return Opes.list('/mobile/proposals').then(function (r) {
      var mine = r.items.filter(function (p) { return p.quote_id === id && ['WITHDRAWN', 'EXPIRED', 'DECLINED'].indexOf(p.status) < 0; })
        .sort(function (a, b) { return String(b.created_at).localeCompare(String(a.created_at)); })[0];
      return mine ? Opes.api('/proposals/' + mine.id) : null;
    }).catch(function () { return null; });
  }
  function reloadProposal() { return Opes.api('/proposals/' + proposal.id).then(function (p) { proposal = p; render(); }); }

  B.loadQuote(id).then(function (r) {
    quote = r.quote; offers = r.offers; offer = B.pickOffer(offers, params);
    return Promise.all([B.schema(quote.line_code), findProposal()]);
  }).then(function (res) {
    sc = res[0]; proposal = res[1];
    if (proposal && proposal.quote_offer_id) offer = offers.filter(function (o) { return o.id === proposal.quote_offer_id; })[0] || offer;
    if (!offer) return Opes.empty(main, T.no_offers_t, h('a', { class: 'dbtn dbtn-primary', href: B.qurl(id) }, T.rate_now));
    answers = Object.assign({}, (proposal && proposal.disclosures && !Array.isArray(proposal.disclosures)) ? proposal.disclosures : {});
    setUrl(); render();
  }).catch(function (e) { Opes.fail(main, e); });

  function terms() { return (proposal && proposal.terms_snapshot) || offer; }

  function render() {
    Opes.clear(main); Opes.clear(sideBox);
    var t = terms(), status = proposal ? String(proposal.status).toUpperCase() : null;

    // 1. Plan
    main.appendChild(h('section', { class: 'acard' }, h('div', { class: 'acard-h' }, h('span', null, T.review_plan_h),
        !proposal ? h('a', { class: 'dbtn dbtn-outline sm', href: B.qurl(id, 'customize', { offer: offer.id }) }, Opes.icon('edit'), T.edit_plan) : Opes.chip(status, T.proposal_state.replace(':n', proposal.proposal_number || ''))),
      h('p', { class: 'sub' }, T.review_plan_s),
      h('div', { class: 'bplan' }, B.mark(offer), h('div', { class: 'bprice-big' }, offer.comparison_rank === 1 ? h('span', { class: 'bbest' }, Opes.icon('check'), T.best_value) : null, h('b', null, B.minor(t.total_minor)), h('small', null, T.per_year)))));

    // 2. Coverage
    var cov = ((t.coverage_snapshot) || offer.coverage_snapshot || {}).coverages || [];
    main.appendChild(h('section', { class: 'acard' }, h('h2', null, T.cov_summary_h), h('p', { class: 'sub' }, T.cov_summary_s),
      cov.length ? h('div', { class: 'atable-wrap' }, h('table', { class: 'atable' }, h('thead', null, h('tr', null, h('th', null, T.coverage_item), h('th', { style: 'text-align:right' }, T.your_limit))),
        h('tbody', null, cov.map(function (c) { return h('tr', null, h('td', null, B.tr(c.name) || B.humanize(c.code), c.optional ? h('small', { class: 'b-muted' }, ' · ' + T.optional) : null), h('td', { style: 'text-align:right;font-weight:600' }, c.limit_minor ? B.minor(c.limit_minor) : T.included)); })))) : h('p', { class: 'b-muted' }, T.no_covers)));

    // 3. Insured details
    var rows = B.factRows(sc, quote.risk_facts);
    main.appendChild(h('section', { class: 'acard' }, h('div', { class: 'acard-h' }, h('span', null, T.risk_h), h('a', { class: 'dbtn dbtn-outline sm', href: '/account/buy?line=' + String(quote.line_code).toLowerCase() }, Opes.icon('edit'), T.change_details)),
      h('p', { class: 'sub' }, T.risk_s), h('dl', { class: 'kv' }, rows.map(function (r) { return [h('dt', null, r[0]), h('dd', null, r[1])]; }))));

    // 4. Declarations
    var qs = (proposal && (proposal.questions || (proposal.disclosure_schema || {}).questions)) || [];
    var canAnswer = proposal && ['DRAFT', 'DISCLOSURES_PENDING'].indexOf(status) >= 0;
    if (proposal && qs.length && EDITABLE.indexOf(status) >= 0) main.appendChild(declarations(qs, canAnswer));

    // Side: payment summary
    sideBox.appendChild(h('section', { class: 'acard' }, h('div', { class: 'acard-h' }, h('span', null, T.pay_summary)), B.priceRows(t),
      h('div', { class: 'btotal' }, h('span', null, T.total_amount), h('b', null, B.minor(t.total_minor))),
      h('div', { class: 'bok' }, Opes.icon('check'), T.no_hidden)));
    sideBox.appendChild(action(status, qs, canAnswer));
  }

  function declarations(qs, canAnswer) {
    return h('section', { class: 'acard' }, h('h2', null, T.disclosure_h), h('p', { class: 'sub' }, T.disclosure_s), qs.map(function (q) {
      var name = 'dq-' + q.code, type = String(q.type || 'boolean').toLowerCase(), ctl;
      if (type === 'boolean') {
        ctl = h('div', { class: 'yn', role: 'radiogroup' }, [[true, T.yes], [false, T.no]].map(function (x) {
          var r = h('input', { type: 'radio', name: name, checked: answers[q.code] === x[0], disabled: !canAnswer });
          r.addEventListener('change', function () { answers[q.code] = x[0]; });
          return h('label', null, r, x[1]);
        }));
      } else if (q.options && q.options.length) {
        ctl = h('select', { disabled: !canAnswer }, h('option', { value: '' }, T.choose), q.options.map(function (o) { var v = o.value !== undefined ? o.value : o; return h('option', { value: v, selected: String(answers[q.code]) === String(v) }, B.tr(o.label) || v); }));
        ctl.addEventListener('change', function () { answers[q.code] = ctl.value; });
        ctl = h('label', { class: 'afield-s' }, ctl);
      } else {
        ctl = h('input', { type: type === 'number' ? 'number' : type === 'date' ? 'date' : 'text', value: answers[q.code] || '', disabled: !canAnswer });
        ctl.addEventListener('input', function () { answers[q.code] = type === 'number' ? Number(ctl.value) : ctl.value; });
        ctl = h('label', { class: 'afield-s' }, ctl);
      }
      return h('div', { class: 'bq' }, h('b', null, B.tr(q.label) || B.humanize(q.code), q.required ? h('i', { style: 'color:#D0342C;font-style:normal' }, ' *') : null), ctl);
    }));
  }

  function action(status, qs, canAnswer) {
    var card = h('section', { class: 'acard' });
    // A. No proposal yet: accept the offer and open the application.
    if (!proposal) {
      var tick = h('input', { type: 'checkbox' });
      var go = h('button', { type: 'button', class: 'dbtn dbtn-primary bbig' }, Opes.icon('lock'), T.confirm_plan, Opes.icon('arrow'));
      go.addEventListener('click', function () {
        Opes.alert('');
        if (!tick.checked) return Opes.alert(T.terms_required);
        Opes.busy(go, true);
        var accept = offer.status === 'ACCEPTED' ? Promise.resolve() : Opes.api('/quotes/' + id + '/offers/' + offer.id + '/accept', { method: 'POST', body: {} });
        accept.then(function () { return Opes.api('/proposals', { body: { quote_offer_id: offer.id, party_id: quote.party_id } }); })
          .then(function (p) { offer.status = 'ACCEPTED'; proposal = p; setUrl(); return reloadProposal(); })
          .catch(function (e) { Opes.busy(go, false); Opes.alert(e.message); });
      });
      card.appendChild(h('label', { class: 'bterms' }, tick, h('span', { html: T.terms })));
      card.appendChild(go);
      return card;
    }
    // B. Declarations / documents, then submit to the insurer.
    if (EDITABLE.indexOf(status) >= 0) {
      var docs = (proposal.required_documents || []).filter(function (d) { return !d.satisfied && !d.fulfilled && d.status !== 'VERIFIED' && d.status !== 'SATISFIED'; });
      var attest = h('input', { type: 'checkbox', checked: !!proposal.attested_at });
      var sub = h('button', { type: 'button', class: 'dbtn dbtn-primary bbig' }, T.submit_app, Opes.icon('arrow'));
      sub.addEventListener('click', function () {
        Opes.alert('');
        var missing = qs.filter(function (q) { return q.required !== false && (answers[q.code] === undefined || answers[q.code] === ''); });
        if ((canAnswer && missing.length) || !attest.checked) return Opes.alert(T.attest_required);
        Opes.busy(sub, true);
        var step = canAnswer && qs.length ? Opes.api('/proposals/' + proposal.id + '/disclosures', { method: 'PUT', body: { answers: answers } }) : Promise.resolve();
        step.then(function () { return proposal.attested_at ? null : Opes.api('/proposals/' + proposal.id + '/disclosures/attest', { method: 'POST', body: {} }); })
          .then(function () { return Opes.api('/proposals/' + proposal.id + '/submit', { method: 'POST', body: {} }); })
          .then(reloadProposal)
          .catch(function (e) { Opes.busy(sub, false); Opes.alert(e.message); reloadProposal().catch(function () {}); });
      });
      if (docs.length) card.appendChild(h('div', { class: 'bnote' }, h('b', null, T.docs_needed), h('ul', null, docs.map(function (d) { return h('li', null, B.tr(d.name || d.label) || Opes.label(d.code || d.requirement_code)); })), h('small', null, T.docs_where)));
      card.appendChild(h('label', { class: 'bterms' }, attest, h('span', null, T.attest)));
      card.appendChild(sub);
      return card;
    }
    // C. Payable: mobile money.
    if (PAYABLE.indexOf(status) >= 0) {
      var pays = proposal.payments || [];
      var open = pays.filter(function (p) { return PAY_OPEN.indexOf(p.status) >= 0; })[0];
      var failed = !open && pays.some(function (p) { return B.PAY_FAILED.indexOf(p.status) >= 0; });
      if (open) card.appendChild(h('div', { class: 'bwait' }, h('span', { class: 'spin' }), h('span', null, T.pay_wait_d.replace(':a', B.minor(open.amount_minor)), ' ', h('a', { href: B.qurl(id, 'confirmation', { proposal: proposal.id }) }, T.see_status))));
      if (failed) card.appendChild(h('p', { class: 'bnote' }, T.pay_failed_prev));
      card.appendChild(h('h2', null, T.pay_method_h));
      var provider = 'mtn_momo';
      card.appendChild(h('div', { class: 'bpay', role: 'radiogroup' }, [['mtn_momo', 'mtn', 'MoMo', T.mtn, T.mtn_s], ['orange_money', 'orange', 'OM', T.orange, T.orange_s]].map(function (x) {
        var r = h('input', { type: 'radio', name: 'provider', value: x[0], checked: x[0] === provider });
        r.addEventListener('change', function () { provider = x[0]; });
        return h('label', { class: 'opt-card' }, r, h('span', { class: 'op ' + x[1], 'aria-hidden': 'true' }, x[2]), h('span', null, h('b', null, x[3]), h('small', null, x[4])));
      })));
      var user = (ctx.session.user || {});
      var phone = h('input', { type: 'tel', inputmode: 'tel', autocomplete: 'tel', value: user.phone_e164 || '+237', placeholder: '+237 6XX XX XX XX' });
      card.appendChild(h('label', { class: 'afield-s', style: 'margin-top:12px' }, h('span', null, T.payer_phone, h('i', null, ' *')), phone, h('small', { class: 'b-muted' }, T.phone_hint)));
      var pay = h('button', { type: 'button', class: 'dbtn dbtn-primary bbig', style: 'margin-top:14px' }, Opes.icon('lock'), T.pay_btn, Opes.icon('arrow'));
      pay.addEventListener('click', function () {
        Opes.alert('');
        var digits = phone.value.replace(/[^\d+]/g, '');
        if (/^[26]\d{8}$/.test(digits)) digits = '+237' + digits;
        if (/^237/.test(digits)) digits = '+' + digits;
        if (!/^\+237[26]\d{8}$/.test(digits)) return Opes.alert(T.phone_invalid);
        Opes.busy(pay, true);
        var tries = 0;
        (function create() {
          return Opes.api('/payments', { body: { proposal_id: proposal.id, provider: provider, payer_phone_e164: digits, idempotency_key: B.payKey(proposal.id, provider, digits) } }).then(function (p) {
            if (B.PAY_FAILED.indexOf(p.status) >= 0 && tries++ < 2) { B.bumpAttempt(proposal.id); return create(); }
            return p.status === 'CREATED' ? Opes.api('/payments/' + p.id + '/initiate', { method: 'POST', body: {} }) : p;
          });
        })().then(function (p) {
          location.href = B.qurl(id, 'confirmation', { proposal: proposal.id, payment: p && p.id });
        }).catch(function (e) { Opes.busy(pay, false); Opes.alert(e.message); });
      });
      card.appendChild(h('p', { class: 'b-muted', style: 'margin-top:10px' }, T.pay_prompt));
      card.appendChild(pay);
      return card;
    }
    // D. Other states: honest status.
    var msg = PAID.indexOf(status) >= 0 ? T.proposal_paid : ['SUBMITTED', 'UNDER_REVIEW', 'RESUBMITTED'].indexOf(status) >= 0 ? T.proposal_review
      : status === 'INFORMATION_REQUIRED' ? T.proposal_info : status === 'COUNTEROFFERED' ? T.proposal_counter : status === 'DECLINED' ? T.proposal_declined : T.proposal_closed;
    card.appendChild(h('div', { class: 'acard-h' }, h('span', null, T.proposal_state.replace(':n', proposal.proposal_number || '')), Opes.chip(status)));
    card.appendChild(h('p', null, msg));
    if (PAID.indexOf(status) >= 0 || (proposal.payments || []).length) card.appendChild(h('a', { class: 'dbtn dbtn-primary bbig', href: B.qurl(id, 'confirmation', { proposal: proposal.id }) }, T.see_status, Opes.icon('arrow')));
    else { var chk = h('button', { type: 'button', class: 'dbtn dbtn-outline' }, Opes.icon('refresh'), T.check_again); chk.addEventListener('click', function () { Opes.busy(chk, true); reloadProposal(); }); card.appendChild(chk); }
    return card;
  }
});
</script>
@endpush
