{{-- /account/agent-actions — AGT-005 Action Centre. Built from the agent's own book only:
     GET /mobile/partner/agent/leads|quotes|proposals, /mobile/agent/renewals, /mobile/agent/clients (all scoped to the calling agent). --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['actions_t'], 'lede' => $K['actions_lede'], 'crumbs' => [[$K['dash_t'], '/account/agent'], [$K['actions_t'], null]], 'active' => 'dashboard'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <div class="stats" data-stats></div>
  <section class="acard" data-page-body>
    <div class="tabs-u" role="tablist" data-tabs></div>
    <div data-rows></div>
  </section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h;
  if (!A.guard(ctx)) return;
  var box = O.$('[data-rows]'), tasks = [], tab = 'all';
  O.loading(box);
  var cref = function (r) { return r.customer_id ? '/account/customers/' + encodeURIComponent(r.customer_id) : '/account/customers'; };
  Promise.all([L.soft(O.list('/mobile/partner/agent/leads').then(function (r) { return r.items; })), L.soft(A.quotes()), L.soft(A.proposals()), L.soft(A.renewals()), L.soft(A.clients())]).then(function (r) {
    var leads = r[0] || [], quotes = r[1] || [], props = r[2] || [], rens = r[3] || [], clients = r[4] || [];
    var add = function (urgent, icon, title, who, due, href) { tasks.push({ urgent: urgent, icon: icon, title: title, who: who, due: due, href: href }); };
    leads.filter(function (l) { return l.status === 'NEW'; }).forEach(function (l) { add(false, 'target', L.t('a_lead_new'), l.full_name, l.created_at, '/account/leads'); });
    quotes.forEach(function (q) {
      var st = String(q.status).toUpperCase(), d = A.days(q.expires_at);
      if (['DECLINED', 'CANCELLED', 'EXPIRED', 'ACCEPTED', 'CONVERTED', 'LOST'].indexOf(st) >= 0) return;
      if (d !== null && d >= 0 && d <= 7) add(d <= 2, 'clock', L.t('a_quote_exp', { d: d }), q.customer_name, q.expires_at, '/account/agent/quotes/' + encodeURIComponent(q.id));
      else if (st === 'SENT' || st === 'VIEWED') add(false, 'compare', L.t('a_quote_follow'), q.customer_name, q.expires_at, '/account/agent/quotes/' + encodeURIComponent(q.id) + '/send');
    });
    props.forEach(function (p) {
      var st = String(p.status).toUpperCase(), href = '/account/agent/proposals/' + encodeURIComponent(p.id);
      if (st === 'INFORMATION_REQUIRED') add(true, 'help', L.t('a_prop_info'), p.customer_name, p.decided_at || p.submitted_at, href + '/information');
      else if (st === 'COUNTEROFFERED') add(true, 'scale', L.t('a_prop_counter'), p.customer_name, p.decided_at, href);
      else if (st === 'PAYMENT_PENDING' || st === 'APPROVED') add(true, 'card', L.t('a_prop_pay'), p.customer_name, p.decided_at, '/account/book');
      else if (['DRAFT', 'DISCLOSURES_PENDING', 'DOCUMENTS_PENDING'].indexOf(st) >= 0) add(false, 'doc', L.t('a_prop_draft'), p.customer_name, p.created_at, href);
    });
    rens.forEach(function (x) { add(x.days_remaining !== undefined && x.days_remaining <= 7, 'refresh', L.t('a_ren', { date: O.date(x.expires_at) }), (x.customer_name || '') + (x.policy_number ? ' · ' + x.policy_number : ''), x.expires_at, cref(x)); });
    clients.forEach(function (c) { var s = String(c.kyc_status || 'NOT_STARTED').toUpperCase(); if (['APPROVED', 'VERIFIED'].indexOf(s) < 0) add(false, 'check', L.t('a_kyc', { s: A.label(s) }), c.full_name, null, '/account/customers/' + encodeURIComponent(c.id) + '/kyc'); });
    tasks.sort(function (a, b) { return (b.urgent - a.urgent) || String(a.due || '9').localeCompare(String(b.due || '9')); });
    A.stats(O.$('[data-stats]'), [A.stat('red', 'clock', L.t('a_urgent'), tasks.filter(function (t) { return t.urgent; }).length), A.stat('blue', 'list', L.t('a_all'), tasks.length)]);
    paint();
  }).catch(function (e) { A.fail(box, e); });
  function paint() {
    A.tabs(O.$('[data-tabs]'), [['all', L.t('a_all'), tasks.length], ['urgent', L.t('a_urgent'), tasks.filter(function (t) { return t.urgent; }).length]], tab, function (t) { tab = t; render(); });
    render();
  }
  function render() {
    var rows = tasks.filter(function (t) { return tab === 'all' || t.urgent; });
    if (!rows.length) return O.empty(box, L.t('a_none'));
    O.clear(box).appendChild(L.table(['a_type', 'client', 'a_due', 'actions'], rows.map(function (t) {
      return h('tr', { 'data-task': '' }, h('td', null, O.icon(t.icon), ' ', h('b', null, t.title), t.urgent ? h('span', { class: 'st st-bad' }, L.t('a_urgent')) : null),
        h('td', null, t.who || '—'), h('td', null, O.date(t.due)), h('td', { class: 'acts' }, L.link(L.t('a_do'), t.href, 'dbtn-primary')));
    })));
  }
});
</script>
@endpush
