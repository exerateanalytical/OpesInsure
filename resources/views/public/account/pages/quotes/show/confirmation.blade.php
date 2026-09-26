{{-- /account/quotes/{quote}/confirmation?proposal=&payment= — "Policy Confirmed" (design: compare_buy_flow/06_stage.png).
     Polls GET /mobile/purchases/{proposal}/status (authoritative; also settles demo payments) until the policy is issued or
     the payment fails; then shows policy details and the policy documents (GET /mobile/documents, POST /mobile/documents/{id}/access). --}}
@extends('public.account.layout', ['title' => __('account_buy.confirm_t'), 'lede' => __('account_buy.confirm_d'), 'crumbs' => [[__('account_buy.quotes'), '/account/quotes'], [__('account_buy.review_t'), null], [__('account_buy.confirm_t'), null]], 'active' => 'quotes'])
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
  var main = $('[data-main]'), sideBox = $('[data-side]'), stepsBox = $('[data-steps]');
  var proposalId = params.get('proposal'), quote = null, sc = null, payment = null, receipt = null, docs = null, polls = 0, timer = null;
  var WAIT = ['PAYMENT_PENDING', 'PAYMENT_PROCESSING', 'ISSUANCE_PENDING'];
  Opes.$$('.crumbs span').forEach(function (s) { if (s.textContent === @json(__('account_buy.review_t'))) s.replaceWith(h('a', { href: B.qurl(id, 'review', { proposal: proposalId }) }, s.textContent)); });
  Opes.loading(main);

  function hero(title, lede) {
    $('#acct-title').textContent = title;
    var l = $('.acct-hero .lede'); if (l) l.textContent = lede;
  }

  var pQuote = B.loadQuote(id).then(function (r) { quote = r.quote; return B.schema(quote.line_code); }).then(function (s) { sc = s; }).catch(function () {});
  var pProposal = proposalId ? Promise.resolve(proposalId) : Opes.list('/mobile/proposals').then(function (r) {
    var p = r.items.filter(function (x) { return x.quote_id === id; }).sort(function (a, b) { return String(b.created_at).localeCompare(String(a.created_at)); })[0];
    return p ? p.id : null;
  });
  Promise.all([pProposal, pQuote]).then(function (res) {
    proposalId = res[0];
    if (!proposalId) return Opes.empty(main, T.no_proposal, h('a', { class: 'dbtn dbtn-primary', href: B.qurl(id) }, T.to_review));
    poll();
  }).catch(function (e) { Opes.fail(main, e); });

  function poll() {
    clearTimeout(timer);
    return Opes.api('/mobile/purchases/' + encodeURIComponent(proposalId) + '/status').then(function (st) {
      var pid = (st.payment && st.payment.id) || params.get('payment');
      var extra = [];
      if (pid && (!payment || payment.id !== pid || WAIT.indexOf(st.status) >= 0)) extra.push(Opes.api('/payments/' + pid).then(function (p) { payment = p; }).catch(function () {}));
      if (st.status === 'POLICY_ISSUED' && pid && !receipt) extra.push(Opes.api('/mobile/payments/' + pid + '/receipt').then(function (r) { receipt = r; }).catch(function () {}));
      if (st.status === 'POLICY_ISSUED' && st.policy && !docs) extra.push(Opes.list('/mobile/documents', { owner_type: 'policy', owner_id: st.policy.id, per_page: 50 }).then(function (r) {
        docs = r.items.filter(function (d) { return d.policy_id === st.policy.id && !d.superseded_by_document_id; });
      }).catch(function () { docs = []; }));
      return Promise.all(extra).then(function () { render(st); });
    }).then(function () {}, function (e) { Opes.fail(main, e); });
  }

  function render(st) {
    var s = st.status, issued = s === 'POLICY_ISSUED', failed = s === 'PAYMENT_FAILED', paid = s === 'ISSUANCE_PENDING' || issued;
    var amount = B.minor((st.payment && st.payment.amount_minor) || (payment && payment.amount_minor));
    hero(issued ? T.confirmed_t : failed ? T.failed_t : paid ? T.issuing_t : T.pending_t, issued ? T.confirmed_d : failed ? T.failed_d : paid ? T.issuing_d : T.pending_d);
    Opes.clear(stepsBox).appendChild(B.steps(issued ? 5 : 4));
    if (issued) Opes.$$('.buy-steps li').forEach(function (li) { li.className = 'done'; });
    Opes.clear(main); Opes.clear(sideBox);

    // Policy details
    var pol = st.policy || {}, facts = (quote && quote.risk_facts) || {}, line = quote && quote.line_code;
    var left = h('div', null, pol.status ? Opes.chip(pol.status) : Opes.chip(s), B.mark({ carrier_name: st.carrier_name, product_name: st.product_name }));
    var kv = h('dl', { class: 'kv' });
    function row(k, v) { if (v !== null && v !== undefined && v !== '' && v !== '—') { kv.appendChild(h('dt', null, k)); kv.appendChild(h('dd', null, v)); } }
    row(T.policy_number, pol.policy_number); row(T.start, st.coverage_starts_at || pol.coverage_starts_at ? Opes.date(st.coverage_starts_at || pol.coverage_starts_at) : null);
    row(T.certificate, pol.certificate_number); row(T.end, st.coverage_ends_at || pol.coverage_ends_at ? Opes.date(st.coverage_ends_at || pol.coverage_ends_at) : null);
    row(T.coverage, B.coverType(sc, facts)); row(T.premium_paid, amount);
    if (line) row(B.lineName(line), B.riskTitle(line, facts)); row(T.method, payment && (T.providers[payment.provider] || payment.provider));
    row(T.facts.registration_number, facts.registration_number); row(T.transaction, (receipt && receipt.reference) || (payment && payment.provider_reference));
    row(T.receipt, receipt && receipt.receipt_number); row(T.purchase_date, pol.issued_at ? Opes.date(pol.issued_at, true) : payment && payment.created_at ? Opes.date(payment.created_at, true) : null);
    main.appendChild(h('section', { class: 'acard' }, h('h2', null, T.policy_h), h('p', { class: 'sub' }, issued ? T.policy_active : T.policy_pending), h('div', { class: 'bpolicy' }, left, kv)));

    // Documents
    var docCard = h('section', { class: 'acard' }, h('h2', null, T.docs_h), h('p', { class: 'sub' }, T.docs_s));
    if (issued && docs && docs.length) {
      docCard.appendChild(h('div', { class: 'bdocs' }, docs.map(function (d) {
        var name = d.title || (T.doc_names || {})[d.document_type_code || d.category] || Opes.label(d.document_type_code || d.category);
        var dl = h('button', { type: 'button', class: 'dbtn dbtn-outline sm' }, Opes.icon('download'), T.download);
        dl.addEventListener('click', function () {
          // Open the tab inside the click (popup blockers), then point it at the short-lived signed URL.
          var win = window.open('about:blank', '_blank');
          Opes.busy(dl, true);
          Opes.api('/mobile/documents/' + d.id + '/access', { method: 'POST', body: {} }).then(function (a) {
            var url = a && (a.url || a.download_url);
            if (url && win) win.location.href = url; else if (!url) { if (win) win.close(); Opes.alert(T.doc_failed); } else Opes.openDoc(a);
          }).catch(function () { if (win) win.close(); Opes.alert(T.doc_failed); }).finally(function () { Opes.busy(dl, false); });
        });
        return h('div', { class: 'bdoc' }, Opes.icon('doc'), h('b', null, name), d.document_number ? h('small', null, d.document_number) : null, dl);
      })));
    } else docCard.appendChild(h('p', { class: 'b-muted' }, T.docs_none));
    main.appendChild(docCard);

    main.appendChild(h('section', { class: 'acard bimportant' }, h('h2', null, Opes.icon('shield'), T.important_h), h('ul', null, T.important.map(function (x) { return h('li', null, Opes.icon('check'), x); }))));

    // Side: payment status
    var tone = issued || paid ? '' : failed ? 'bad' : 'warn';
    var pcard = h('section', { class: 'acard bpaid ' + tone }, h('div', { class: 'row' }, h('span', { class: 'okc' }, Opes.icon(failed ? 'x' : paid ? 'check' : 'clock')),
      h('div', null, h('b', null, paid ? T.pay_ok_t : failed ? T.pay_bad_t : T.pay_wait_t), h('p', null, (paid ? T.pay_ok_d : failed ? T.pay_bad_d : T.pay_wait_d).replace(':a', amount)))));
    if (failed) pcard.appendChild(h('a', { class: 'dbtn dbtn-outline', href: B.qurl(id, 'review', { proposal: proposalId }) }, Opes.icon('refresh'), T.try_again));
    else if (!issued) {
      var chk = h('button', { type: 'button', class: 'dbtn dbtn-outline' }, Opes.icon('refresh'), T.check_again);
      chk.addEventListener('click', function () { Opes.busy(chk, true); polls = 0; poll(); });
      pcard.appendChild(chk);
    } else if (pol.id) pcard.appendChild(h('a', { class: 'dbtn dbtn-outline', href: '/account/policies/' + pol.id }, T.open_policy, Opes.icon('arrow')));
    sideBox.appendChild(pcard);
    if (WAIT.indexOf(s) >= 0 && polls > 45) sideBox.appendChild(h('p', { class: 'bwait' }, T.still_pending));
    if (issued) sideBox.appendChild(h('section', { class: 'acard' }, h('h2', null, T.next_h || @json(__('account_buy.next_h'))),
      h('ul', { class: 'bsteps-list' }, T.next_steps.map(function (x) { return h('li', null, Opes.icon('check'), h('span', null, h('b', null, x[0]), h('small', null, x[1]))); })),
      h('a', { class: 'dbtn dbtn-primary', style: 'margin-top:14px;width:100%;justify-content:center', href: '/account/policies' }, T.my_policies)));

    // Keep polling while the operator / insurer is working: every 4s for ~3 min, then every 20s.
    if (WAIT.indexOf(s) >= 0) { polls++; timer = setTimeout(poll, polls > 45 ? 20000 : 4000); }
  }
});
</script>
@endpush
