{{-- /account/agent — AGT-004 Agent Dashboard (agents are sent here from /account). Every figure comes from the agent's own book:
     GET /mobile/agent/dashboard (metrics), /mobile/partner/agent/leads|quotes|proposals, /mobile/agent/commissions, /mobile/agent/renewals. --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['dash_t'], 'lede' => $K['dash_lede'], 'crumbs' => [[$K['dash_t'], null]], 'active' => 'dashboard'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <div class="stats" data-stats></div>
  <section class="acard" data-quick></section>
  <div class="ag-pipe" data-pipe></div>
  <div class="desk-main">
    <div>
      <section class="acard" data-quotes></section>
      <section class="acard" data-props></section>
    </div>
    <aside class="desk-side">
      <section class="acard" data-pay></section>
      <section class="acard" data-ren></section>
    </aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var q = $('[data-quotes]'), p = $('[data-props]'), pay = $('[data-pay]'), ren = $('[data-ren]');
  var head = function (el, key, href) { O.clear(el).appendChild(h('div', { class: 'acard-h' }, h('h2', null, L.t(key)), href ? h('a', { class: 'rowlink', href: href }, L.t('view_all')) : null)); };
  O.clear($('[data-quick]')).append(h('h2', null, L.t('d_quick')), h('div', { class: 'btns', style: 'display:flex;flex-wrap:wrap;gap:8px' },
    L.link(L.t('q_new_quote'), '/account/buy', 'dbtn-primary', 'compare'), L.link(L.t('q_new_lead'), '/account/leads', null, 'target'), L.link(L.t('q_actions'), '/account/agent-actions', null, 'bell'),
    L.link(L.t('q_needs'), '/account/agent/needs', null, 'check'), L.link(L.t('q_products'), '/account/agent/products', null, 'doc'), L.link(L.t('q_builder'), '/account/agent/proposals/new', null, 'list')));
  [q, p, pay, ren].forEach(O.loading);
  var OPEN_Q = ['DRAFT', 'OFFERED', 'RATED', 'GENERATED', 'SENT', 'VIEWED', 'QUOTED'];
  var IN_PROG = ['DRAFT', 'DISCLOSURES_PENDING', 'DOCUMENTS_PENDING', 'SUBMITTED', 'UNDER_REVIEW', 'INFORMATION_REQUIRED', 'RESUBMITTED', 'COUNTEROFFERED'];
  Promise.all([L.soft(A.dashboard()), L.soft(O.list('/mobile/partner/agent/leads').then(function (r) { return r.items; })), L.soft(A.quotes()), L.soft(A.proposals()),
    L.soft(O.list('/mobile/agent/commissions').then(function (r) { return r.items; })), L.soft(A.renewals())]).then(function (r) {
    var metrics = ((r[0] || {}).metrics) || [], leads = r[1] || [], quotes = r[2] || [], props = r[3] || [], comm = r[4] || [], rens = r[5] || [];
    var m = function (label) { var x = metrics.filter(function (i) { return i.label === label; })[0]; return x ? x.value : '—'; };
    var awaiting = quotes.filter(function (x) { return OPEN_Q.indexOf(String(x.status).toUpperCase()) >= 0 && (A.days(x.expires_at) === null || A.days(x.expires_at) >= 0); });
    var inprog = props.filter(function (x) { return IN_PROG.indexOf(String(x.status).toUpperCase()) >= 0; });
    var due = props.filter(function (x) { return ['APPROVED', 'PAYMENT_PENDING'].indexOf(String(x.status).toUpperCase()) >= 0; });
    var sum = function (st) { return comm.filter(function (c) { return st.indexOf(String(c.status).toUpperCase()) >= 0; }).reduce(function (s, c) { return s + (c.amount_minor || 0); }, 0); };
    A.stats($('[data-stats]'), [
      A.stat('blue', 'users', L.t('d_clients'), m('Clients')), A.stat('navy', 'target', L.t('d_leads'), leads.filter(function (l) { return l.status !== 'CONVERTED' && l.status !== 'LOST'; }).length),
      A.stat('orange', 'compare', L.t('d_quotes'), awaiting.length), A.stat('blue', 'doc', L.t('d_proposals'), inprog.length),
      A.stat(due.length ? 'red' : 'green', 'card', L.t('d_pay'), due.length), A.stat('green', 'piggy', L.t('d_comm'), A.money(sum(['AVAILABLE', 'VESTED'])), L.t('d_comm_p') + ' ' + A.money(sum(['PENDING']))),
      A.stat(rens.length ? 'orange' : 'green', 'refresh', L.t('d_ren'), rens.length)]);
    var STAGES = ['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTE', 'NEGOTIATION', 'CONVERTED', 'LOST'];
    O.clear($('[data-pipe]')).append.apply($('[data-pipe]'), STAGES.map(function (s) { return h('a', { href: '/account/leads', style: 'text-decoration:none' }, h('small', null, A.label(s)), h('b', null, String(leads.filter(function (l) { return l.status === s; }).length))); }));

    head(q, 'd_quotes_h', '/account/book');
    if (!awaiting.length) { var e1 = h('div'); q.appendChild(e1); O.empty(e1, L.t('no_quotes')); }
    else q.appendChild(L.table(['client', 'line', 'premium', 'status', 'expires', 'actions'], awaiting.slice(0, 6).map(function (x) {
      return h('tr', null, h('td', null, h('b', null, x.customer_name)), h('td', null, A.line(x.line_code)), h('td', { class: 'amt' }, A.money(x.best_premium_minor)), h('td', null, O.chip(x.status)), h('td', null, O.date(x.expires_at)),
        h('td', { class: 'acts' }, L.link(L.t('open'), '/account/agent/quotes/' + encodeURIComponent(x.id))));
    })));

    head(p, 'd_props_h', '/account/book');
    if (!inprog.length) { var e2 = h('div'); p.appendChild(e2); O.empty(e2, L.t('no_props')); }
    else p.appendChild(L.table(['pr_number', 'client', 'insurer', 'status', 'actions'], inprog.slice(0, 6).map(function (x) {
      return h('tr', null, h('td', null, h('b', null, x.proposal_number || '—')), h('td', null, x.customer_name), h('td', null, (x.carrier_short_name || x.carrier_name) || '—'), h('td', null, O.chip(x.status, A.label(x.status))),
        h('td', { class: 'acts' }, L.link(L.t('open'), '/account/agent/proposals/' + encodeURIComponent(x.id))));
    })));

    head(pay, 'd_pay_h', '/account/book');
    if (!due.length) { var e3 = h('div'); pay.appendChild(e3); O.empty(e3, L.t('no_pay')); }
    else pay.appendChild(h('ul', { class: 'op-notes' }, due.slice(0, 5).map(function (x) { return h('li', null, h('b', null, x.customer_name), h('span', null, (x.proposal_number || '') + ' · ' + A.money(x.total_minor))); })));

    head(ren, 'd_ren_h', '/account/customers');
    if (!rens.length) { var e4 = h('div'); ren.appendChild(e4); O.empty(e4, L.t('no_ren')); }
    else ren.appendChild(h('ul', { class: 'op-notes' }, rens.slice(0, 5).map(function (x) { return h('li', null, h('b', null, x.customer_name), h('span', null, (x.policy_number || '') + ' · ' + O.date(x.expires_at))); })));
  }).catch(function (e) { A.fail(q, e); });
});
</script>
@endpush
