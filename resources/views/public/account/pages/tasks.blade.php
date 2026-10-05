{{-- /account/tasks — AGT-056 Tasks & Follow-Ups (agent): one work list built from the agent's own book — leads to contact, assisted
     sales awaiting payment, renewals in the 60-day window, service requests in progress, open claims and sticker handovers to
     acknowledge. Each task links to the screen where it is done. GET /mobile/partner/agent/leads|quotes|claims|service-requests,
     /mobile/agent/renewals, /mobile/partner/agent/stickers (each source loads independently; a failed one is reported, not fatal). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['tasks_t'], 'lede' => $K['tasks_lede'], 'crumbs' => [[$K['tasks_t'], null]], 'active' => 'tasks'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
  <div class="desk-stats ag-stats4" data-stats></div>
  <section class="acard" data-page-body>
    <div class="desk-tools" data-tools></div>
    <div data-rows></div>
  </section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var box = O.$('[data-rows]'), tasks = [], kind = '', errors = 0, CLOSED = ['CLOSED', 'PAID', 'REJECTED', 'WITHDRAWN', 'SETTLED', 'DECLINED'];
  var day = 86400000, now = Date.now();
  function due(iso, addDays) { if (!iso) return null; return new Date(new Date(iso).getTime() + (addDays || 0) * day).toISOString(); }
  var SOURCES = {
    leads: function () { return O.list('/mobile/partner/agent/leads').then(function (r) { return r.items.filter(function (l) { return ['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTE', 'NEGOTIATION'].indexOf(l.status) >= 0; }).map(function (l) {
      return { kind: 'leads', title: S.t(l.status === 'NEW' ? 'task_lead_new' : 'task_lead_follow', { n: l.full_name }), who: l.full_name, due: due(l.updated_at, l.status === 'NEW' ? 1 : 3), status: l.status, href: '/account/leads' };
    }); }); },
    payments: function () { return A.quotes().then(function (rows) { return rows.filter(function (q) { return q.assisted && q.best_premium_minor && q.payment_status !== 'PAID' && ['ACCEPTED', 'EXPIRED', 'CANCELLED'].indexOf(q.status) < 0; }).map(function (q) {
      // CUSTOMER_PROMPTED: operator prompt on the client's phone; client_notified_at: the client was asked to review/accept the application; else: send it.
      var key = q.payment_status === 'CUSTOMER_PROMPTED' ? 'task_pay_wait' : (q.client_notified_at && !q.payment_status ? 'task_pay_accept' : 'task_pay_prompt');
      return { kind: 'payments', title: S.t(key, { n: q.customer_name || '' }), who: q.customer_name, due: q.expires_at || due(q.created_at, 2), status: q.payment_status || q.status, href: '/account/book/payments' };
    }); }); },
    renewals: function () { return A.renewals().then(function (rows) { return rows.filter(function (r) { return ['RENEWED', 'COMPLETED'].indexOf(r.status) < 0; }).map(function (r) {
      return { kind: 'renewals', title: S.t('task_renewal', { p: r.policy_number || '', n: r.customer_name || '' }), who: r.customer_name, due: r.expires_at, status: r.status, href: '/account/book/renewals/' + S.enc(r.id) };
    }); }); },
    requests: function () { return S.requests().then(function (rows) { return rows.filter(function (t) { return ['REQUESTED', 'PAYMENT_PENDING', 'PENDING_APPROVAL'].indexOf(t.status) >= 0; }).map(function (t) {
      return { kind: 'requests', title: S.t('task_request', { t: S.label(t.type), p: t.policy_number || '' }), who: t.policy_number, due: due(t.created_at, 5), status: t.status, href: '/account/book/requests/' + S.enc(t.id) };
    }); }); },
    claims: function () { return A.claims().then(function (rows) { return rows.filter(function (c) { return CLOSED.indexOf(c.status) < 0; }).map(function (c) {
      return { kind: 'claims', title: S.t('task_claim', { c: c.claim_number || '', n: c.customer_name || '' }), who: c.customer_name, due: due(c.submitted_at, 7), status: c.status, href: S.claimUrl(c.id) + '/evidence' };
    }); }); },
    stickers: function () { return O.can('stickers.view') ? S.stickers().then(function (d) { return d.handovers.filter(function (x) { return x.can_decide; }).map(function (x) {
      return { kind: 'stickers', title: S.t('task_handover', { q: x.quantity }), who: S.label(x.from_level), due: due(x.created_at, 1), status: x.status, href: '/account/stickers' };
    }); }) : Promise.resolve([]); },
  };
  function render() {
    var rows = tasks.filter(function (t) { return !kind || t.kind === kind; }).sort(function (a, b) { return (a.due || '9') < (b.due || '9') ? -1 : 1; });
    if (!tasks.length) return O.empty(box, S.t(errors ? 'tasks_partial' : 'no_tasks'));
    if (!rows.length) return O.empty(box, S.t('no_match'));
    O.clear(box).appendChild(S.table(['th_task', 'th_type', 'th_due', 'th_status', 'th_actions'], rows.map(function (t) {
      var late = t.due && new Date(t.due).getTime() < now;
      return S.row([h('b', null, t.title), S.t('kind_' + t.kind), h('span', { class: late ? 'st st-bad' : '' }, t.due ? O.date(t.due) : '—', late ? ' · ' + S.t('overdue') : ''), S.chip(t.status), S.btn(S.t('open'), t.href)]);
    })));
    if (errors) box.appendChild(h('p', { class: 'sub' }, S.t('tasks_partial')));
  }
  var sel = h('select', { 'aria-label': S.t('filter'), onchange: function () { kind = this.value; render(); } }, h('option', { value: '' }, S.t('flt_all')),
    Object.keys(SOURCES).map(function (k) { return h('option', { value: k }, S.t('kind_' + k)); }));
  O.$('[data-tools]').appendChild(sel);
  O.loading(box);
  return Promise.all(Object.keys(SOURCES).map(function (k) { return SOURCES[k]().catch(function () { errors++; return []; }); })).then(function (lists) {
    tasks = [].concat.apply([], lists);
    var overdue = tasks.filter(function (t) { return t.due && new Date(t.due).getTime() < now; }).length, week = tasks.filter(function (t) { return t.due && new Date(t.due).getTime() - now < 7 * day; }).length;
    A.stats(O.$('[data-stats]'), [A.stat('b', 'list', S.t('stat_tasks'), tasks.length), A.stat('o', 'clock', S.t('stat_overdue'), overdue), A.stat('p', 'refresh', S.t('stat_week'), week),
      A.stat('g', 'shield', S.t('stat_open_claims'), tasks.filter(function (t) { return t.kind === 'claims'; }).length)]);
    render();
  });
});
</script>
@endpush
