{{-- /account/policies/{id} — Policy Details (design 07_stage). GET /mobile/wallet/policies/{id},
     /mobile/policies/{id}/documents, /mobile/payments, /mobile/claims. --}}
@extends('public.account.layout', ['title' => __('account_policies.show.title'), 'lede' => __('account_policies.show.lede'), 'crumbs' => [[__('account_policies.pol.title'), '/account/policies'], [__('account_policies.show.title'), null]], 'active' => 'policies'])
@section('content')
@include('public.account.partials.policies-assets')
<div data-page-body>
  <div class="acard"><div class="acct-loading" role="status"><span class="spin"></span>{{ __('account.js.loading') }}</div></div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, T = OP.T, S = T.show, $ = Opes.$, box = $('[data-page-body]');
  var id = ctx.ids[0];
  var fr = Opes.locale === 'fr';
  return Promise.all([
    Opes.api('/mobile/wallet/policies/' + id),
    Opes.api('/mobile/policies/' + id + '/documents').catch(function () { return null; }),
    OP.payments().catch(function () { return null; }),
    OP.claims().catch(function () { return null; })
  ]).then(function (r) {
    var p = r[0], docs = r[1], pays = r[2], claims = r[3];
    var risk = OP.risk(p), f = risk.facts;
    var myPays = pays ? pays.filter(function (x) { return x.proposal_id === p.proposal_id; }) : null;
    var paid = myPays ? myPays.filter(OP.ok) : [];
    var firstPay = (myPays || []).filter(function (x) { return x.id === p.payment_intent_id; })[0] || paid[0];
    var offer = (p.proposal && p.proposal.offer) || {};
    var covs = (offer.coverage_snapshot && offer.coverage_snapshot.coverages) || [];
    var groups = (docs && docs.groups) || [];
    var allDocs = []; groups.forEach(function (g) { (g.documents || []).forEach(function (d) { allDocs.push(d); }); });
    if (!allDocs.length && p.documents) allDocs = p.documents;

    // ---- header card
    var head = h('section', { class: 'acard op-head' },
      h('div', { class: 'op-id' }, OP.mark(p.carrier_name), h('div', null, h('h2', null, p.carrier_name || '—'), h('p', null, OP.title(p)), OP.chip(OP.state(p)))),
      h('div', null, h('dl', { class: 'kv' },
        h('dt', null, S.number), h('dd', null, p.policy_number || '—'),
        risk.name ? [h('dt', null, S.vehicle), h('dd', null, risk.name, f.usage_type ? h('small', { class: 'op-muted', style: 'display:block;font-weight:500' }, [f.fiscal_power ? f.fiscal_power + ' CV' : null, OP.T.veh.usages[f.usage_type] || Opes.label(f.usage_type)].filter(Boolean).join(' • ')) : null)] : [h('dt', null, S.insured), h('dd', null, OP.line(OP.lineOf(p)))],
        risk.reg ? [h('dt', null, S.reg), h('dd', null, risk.reg)] : [h('dt', null, S.certificate), h('dd', null, p.certificate_number || (p.certificate && p.certificate.serial_number) || '—')]
      )),
      h('div', null, h('dl', { class: 'kv' },
        h('dt', null, S.start), h('dd', null, Opes.date(p.coverage_starts_at)),
        h('dt', null, S.end), h('dd', null, Opes.date(p.coverage_ends_at)),
        h('dt', null, S.premium), h('dd', null, OP.mm(OP.total(p))),
        h('dt', null, S.method), h('dd', null, firstPay ? OP.provider(firstPay.provider) : '—')
      )));

    // ---- blocks
    var COV_ICON = { THIRD_PARTY: 'users', OWN_DAMAGE: 'motor', THEFT_FIRE: 'lock', ASSISTANCE: 'headset', MEDICAL: 'health', DEATH: 'life', ACCIDENT: 'accident', BAGGAGE: 'travel' };
    function covName(c) { var n = c.name || c.code; return typeof n === 'object' ? (fr ? n.fr : n.en) || n.en : Opes.label(n); }
    function covTiles(list) {
      return h('div', { class: 'op-covs' }, list.map(function (c) {
        return h('div', { class: 'op-cov' }, Opes.icon(COV_ICON[c.code] || 'shield'), h('div', null, h('small', null, covName(c)), h('b', null, c.limit_minor ? OP.mm(c.limit_minor) : S.included)));
      }));
    }
    function coverageCard(full) {
      var c = OP.card(S.cov_t, S.cov_d);
      if (!covs.length) { c.appendChild(h('p', { class: 'op-muted' }, S.cov_none)); return c; }
      if (!full) { c.appendChild(covTiles(covs.slice(0, 6))); c.appendChild(h('button', { type: 'button', class: 'op-more', onclick: function () { show('coverage'); } }, S.cov_full, Opes.icon('arrow'))); return c; }
      c.appendChild(OP.table([
        [S.cover, function (x) { return h('b', null, covName(x)); }],
        [S.limit, function (x) { return x.limit_minor ? OP.mm(x.limit_minor) : S.included; }],
        [S.deductible, function (x) { return x.deductible_minor ? OP.mm(x.deductible_minor) : '0 FCFA'; }],
        ['', function (x) { return h('span', { class: 'st ' + (x.mandatory ? 'st-info' : 'st-muted') }, x.mandatory ? S.mandatory : S.optional); }]
      ], covs, 'op-stack'));
      return c;
    }
    function breakdownCard() {
      var b = offer.calculation_breakdown || [];
      var c = OP.card(S.breakdown);
      var rows = b.map(function (x) { return h('div', null, h('span', null, S['b_' + x.code] || Opes.label(x.code)), h('b', null, OP.mm(x.amount_minor))); });
      rows.push(h('div', { class: 'big' }, h('span', null, S.b_total), h('b', null, OP.mm(OP.total(p)))));
      c.appendChild(h('div', { class: 'op-sum' }, rows));
      return c;
    }
    function timelineCard() {
      var c = OP.card(S.tl_t, S.tl_d);
      var now = Date.now(), start = Date.parse(p.coverage_starts_at), end = Date.parse(p.coverage_ends_at);
      var items = [
        [S.tl_bought, Opes.date(p.issued_at || p.created_at, true), 'done'],
        firstPay && OP.ok(firstPay) ? [S.tl_paid, Opes.date(firstPay.updated_at || firstPay.created_at, true), 'done'] : null,
        [S.tl_active, Opes.date(p.coverage_starts_at) + ' – ' + Opes.date(p.coverage_ends_at), now >= start && now <= end ? 'cur' : (now > end ? 'done' : '')],
        [S.tl_end, Opes.date(p.coverage_ends_at), now > end ? 'done' : '']
      ].filter(Boolean);
      c.appendChild(h('ol', { class: 'timeline' }, items.map(function (i) { return h('li', { class: i[2] }, h('span', { class: 'dotc' }), h('b', null, i[0]), h('small', null, i[1])); })));
      return c;
    }
    function qaCard() {
      var c = OP.card(S.qa_t);
      var cert = p.certificate && p.certificate.download_url;
      c.appendChild(h('div', { class: 'op-qa' },
        allDocs.length ? h('button', { type: 'button', class: 'dbtn dbtn-primary', onclick: function () { OP.openAsync(function () { return Opes.api('/mobile/policies/' + id + '/documents/pack').then(function (d) { return d && d.url; }); }); } }, Opes.icon('download'), S.qa_doc) : null,
        cert ? h('a', { class: 'dbtn dbtn-outline', href: cert, target: '_blank', rel: 'noopener' }, Opes.icon('doc'), S.qa_cert) : null,
        p.certificate && p.certificate.verification_url ? h('a', { class: 'dbtn dbtn-outline', href: p.certificate.verification_url, target: '_blank', rel: 'noopener' }, Opes.icon('check'), S.qa_verify) : null,
        OP.btn(S.qa_pay, '/account/payments/new?policy=' + id, 'dbtn-navy', 'card'),
        OP.btn(S.qa_claim, '/account/claims/new?policy=' + id, 'dbtn-outline', 'shield'),
        OP.btn(S.qa_help, '/account/support?policy=' + id, 'dbtn-outline', 'headset')));
      return c;
    }
    function docTitle(d) { return (fr ? d.title_fr : null) || d.title || (T.docs.cat[d.category] || Opes.label(d.category || d.document_type_code)); }
    function docsCard(all) {
      var c = OP.card(S.docs_t, S.docs_d, all ? null : h('button', { type: 'button', class: 'rowlink op-more', style: 'width:auto;margin:0;border:0', onclick: function () { show('documents'); } }, T.view_all));
      if (!allDocs.length) { var e = h('div'); c.appendChild(e); Opes.empty(e, T.no_docs); return c; }
      if (!all) {
        c.appendChild(h('div', { class: 'op-docs' }, allDocs.slice(0, 4).map(function (d) {
          return h('div', { class: 'op-doc' }, Opes.icon('doc'), h('b', null, docTitle(d)), h('small', null, d.document_number || Opes.date(d.issued_at || d.created_at)),
            h('div', { class: 'op-acts' }, d.download_url ? h('a', { class: 'dbtn dbtn-outline sm', href: d.download_url, target: '_blank', rel: 'noopener' }, Opes.icon('download'), T.download) : null));
        })));
        return c;
      }
      c.appendChild(OP.table([
        [T.docs.name, function (d) { return h('span', { class: 'op-dname' }, Opes.icon('doc'), docTitle(d)); }],
        [T.docs.type, function (d) { return d.document_number || Opes.label(d.group || d.type || d.category); }],
        [T.docs.issued, function (d) { return Opes.date(d.issued_at || d.created_at); }],
        [T.docs.status, function (d) { return OP.chip(d.status || 'VALID'); }],
        [T.docs.actions, function (d) { return h('div', { class: 'op-acts' }, d.download_url ? h('a', { class: 'dbtn dbtn-outline sm', href: d.download_url, target: '_blank', rel: 'noopener' }, Opes.icon('download'), T.download) : null, d.verification_url ? h('a', { class: 'op-iconbtn', href: d.verification_url, target: '_blank', rel: 'noopener', title: S.qa_verify, 'aria-label': S.qa_verify }, Opes.icon('check')) : null); }]
      ], allDocs, 'op-stack'));
      return c;
    }
    function paysCard(all) {
      var c = OP.card(S.pay_t, null, all ? null : h('a', { class: 'rowlink', href: '/account/payments' }, T.view_all));
      if (!myPays) { var f0 = h('div'); c.appendChild(f0); Opes.fail(f0); return c; }
      if (!myPays.length) { var e = h('div'); c.appendChild(e); Opes.empty(e, T.no_payments); return c; }
      if (!all) {
        c.appendChild(h('div', { class: 'op-pays' }, myPays.slice(0, 3).map(function (x) { return h('div', null, h('span', null, Opes.date(x.created_at)), h('b', null, OP.mm(x.amount_minor)), OP.chip(x.status)); })));
        return c;
      }
      c.appendChild(OP.table([
        [T.pays.date, function (x) { return Opes.date(x.created_at, true); }],
        [T.pays.method, function (x) { return OP.provider(x.provider); }],
        [T.pays.ref, function (x) { return x.provider_reference; }],
        [T.pays.amount, function (x) { return OP.mm(x.amount_minor); }],
        [T.pays.status, function (x) { return OP.chip(x.status); }],
        [T.receipt, function (x) { return OP.ok(x) ? h('button', { type: 'button', class: 'op-iconbtn', 'aria-label': T.receipt, title: T.receipt, onclick: function () { OP.openReceipt(x.id); } }, Opes.icon('download')) : ''; }]
      ], myPays, 'op-stack'));
      return c;
    }
    function claimsCard() {
      var c = OP.card(S.claims_t, null, OP.btn(S.qa_claim, '/account/claims/new?policy=' + id, 'dbtn-outline sm', 'shield'));
      var mine = claims ? claims.filter(function (x) { return x.policy_id === id; }) : null;
      if (!mine) { var f0 = h('div'); c.appendChild(f0); Opes.fail(f0); return c; }
      if (!mine.length) { var e = h('div'); c.appendChild(e); Opes.empty(e, T.no_claims); return c; }
      c.appendChild(OP.table([
        [S.claim_no, function (x) { return h('a', { class: 'rowlink', href: '/account/claims/' + x.id }, x.claim_number || x.id.slice(0, 8)); }],
        [S.loss, function (x) { return Opes.date(x.loss_occurred_at); }],
        [T.pays.status, function (x) { return OP.chip(x.status); }],
        ['', function (x) { return OP.btn(T.view, '/account/claims/' + x.id); }]
      ], mine, 'op-stack'));
      return c;
    }

    // ---- tabs
    var panels = {
      overview: h('div', { class: 'op-ov' }, h('div', { class: 'col' }, coverageCard(false), docsCard(false)), h('div', { class: 'col' }, timelineCard(), paysCard(false)), h('div', { class: 'col' }, qaCard())),
      coverage: h('div', { class: 'agrid main-side' }, coverageCard(true), breakdownCard()),
      documents: docsCard(true),
      payments: paysCard(true),
      claims: claimsCard()
    };
    var ICONS = { overview: 'grid', coverage: 'shield', documents: 'doc', payments: 'card', claims: 'users' };
    var tabs = h('div', { class: 'tabs-u acard op-ptabs', role: 'tablist', style: 'padding:0 8px;margin:0' });
    Object.keys(panels).forEach(function (k) {
      panels[k].setAttribute('role', 'tabpanel');
      tabs.appendChild(h('button', { type: 'button', role: 'tab', 'data-tab': k, 'aria-selected': 'false', onclick: function () { show(k); } }, Opes.icon(ICONS[k]), ' ', S.tabs[k]));
    });
    function show(k) {
      Object.keys(panels).forEach(function (x) { panels[x].hidden = x !== k; });
      Opes.$$('[data-tab]', tabs).forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === k ? 'true' : 'false'); });
      if (history.replaceState) history.replaceState(null, '', k === 'overview' ? location.pathname : '#' + k);
    }
    Opes.clear(box).append(head, tabs);
    Object.keys(panels).forEach(function (k) { box.appendChild(panels[k]); });
    box.style.display = 'grid'; box.style.gap = '16px';
    show(panels[location.hash.slice(1)] ? location.hash.slice(1) : 'overview');
  }).catch(function (e) {
    if (e && e.status === 404) return Opes.empty(box, S.not_found, OP.btn(T.back, '/account/policies'));
    Opes.fail(box, e);
  });
});
</script>
@endpush
