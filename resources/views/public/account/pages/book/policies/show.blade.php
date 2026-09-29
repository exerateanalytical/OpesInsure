{{-- /account/book/policies/{id} — AGT-040 Policy Details (agent): the policy of a book client, its service requests and
     cancellations (AGT-043 per policy), sticker, claims and the servicing actions.
     GET /mobile/partner/agent/policies/{id} (book-scoped) + /mobile/partner/agent/claims (filtered by policy). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['policy_t'], 'lede' => $K['policy_lede'], 'crumbs' => [[__('account_agent.book_t'), '/account/book?tab=policies'], [$K['policy_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
  <section class="acard"><h2>{{ $K['js']['sec_requests'] }}</h2><div data-requests></div></section>
  <section class="acard"><h2>{{ $K['js']['sec_claims'] }}</h2><div data-claims></div></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var id = ctx.ids[0], box = O.$('[data-page-body]'), rbox = O.$('[data-requests]'), cbox = O.$('[data-claims]');
  O.loading(box); O.loading(rbox); O.loading(cbox);
  return S.policy(id).then(function (p) {
    var days = A.days(p.coverage_ends_at), live = p.status === 'ACTIVE', base = S.policyUrl(p.id);
    var acts = [S.btn(S.t('act_documents'), base + '/documents', 'dbtn-outline', 'doc')];
    if (live && S.canManage()) acts.push(S.btn(S.t('act_change'), base + '/endorsement', 'dbtn-outline', 'edit'));
    if (live && O.can('policies.cancellation.request')) acts.push(S.btn(S.t('act_cancel'), base + '/cancel', 'dbtn-outline', 'x'));
    if (live && p.line_code === 'MOTOR' && !p.sticker && O.can('stickers.assign')) acts.push(S.btn(S.t('act_sticker'), '/account/stickers?policy=' + S.enc(p.id), 'dbtn-outline', 'check'));
    if (live && p.customer_id && A.canFileClaim()) acts.push(S.btn(S.t('act_claim'), S.clientUrl(p.customer_id) + '/claim?policy=' + S.enc(p.id), 'dbtn-primary', 'shield'));
    if (days !== null && days <= 60 && ['ACTIVE', 'EXPIRING'].indexOf(p.status) >= 0) acts.push(S.btn(S.t('act_renewal'), '/account/book/renewals/' + S.enc(p.id), 'dbtn-primary', 'refresh'));
    O.clear(box).append(
      h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('span', { class: 'av' }, O.icon('doc')), h('div', null, h('h2', null, p.policy_number || '—'), h('small', null, [(p.carrier_short_name || p.carrier_name), A.line(p.line_code)].filter(Boolean).join(' · ')))),
        h('div', { class: 'btns' }, S.chip(p.status))),
      h('hr', { class: 'ag-hr' }),
      h('div', { class: 'ag-fields' },
        A.field(S.t('f_client'), S.clientLink(p.customer_id, p.customer_name)), A.field(S.t('f_insurer'), (p.carrier_short_name || p.carrier_name)), A.field(S.t('f_line'), A.line(p.line_code)),
        A.field(S.t('f_premium'), S.money(p.premium_minor)), A.field(S.t('f_starts'), O.date(p.coverage_starts_at)), A.field(S.t('f_ends'), O.date(p.coverage_ends_at)),
        A.field(S.t('f_issued'), O.date(p.issued_at)), A.field(S.t('f_days_left'), days === null ? '—' : (days < 0 ? S.t('expired_ago', { n: -days }) : S.t('days_left', { n: days }))),
        A.field(S.t('f_sticker'), p.sticker ? p.sticker.serial_number : '—')),
      h('div', { class: 'bactions' }, acts));

    var rows = (p.transactions || []).map(function (t) { return h('tr', null, h('td', null, h('a', { href: '/account/book/requests/' + S.enc(t.id) }, h('b', null, t.transaction_number || '—'))), h('td', null, S.label(t.type)), h('td', null, S.chip(t.status)), h('td', null, t.reason || '—'), h('td', null, O.date(t.created_at))); });
    (p.cancellations || []).forEach(function (c) { rows.push(h('tr', null, h('td', null, '—'), h('td', null, S.label('CANCELLATION')), h('td', null, S.chip(c.status)), h('td', null, S.label(c.reason_code)), h('td', null, O.date(c.created_at)))); });
    if (!rows.length) O.empty(rbox, S.t('no_requests')); else O.clear(rbox).appendChild(S.table(['th_reference', 'th_type', 'th_status', 'th_reason', 'th_created'], rows));

    A.claims().then(function (all) {
      var mine = all.filter(function (c) { return c.policy_id === p.id; });
      if (!mine.length) return O.empty(cbox, S.t('no_policy_claims'));
      O.clear(cbox).appendChild(S.table(['th_claim', 'th_status', 'th_estimate', 'th_loss_date'], mine.map(function (c) {
        return S.row([h('a', { href: S.claimUrl(c.id) }, h('b', null, c.claim_number || '—')), S.chip(c.status), h('span', { class: 'amt' }, S.money(c.estimated_loss_minor)), O.date(c.loss_occurred_at)]);
      })));
    }).catch(function (e) { A.fail(cbox, e); });
  }).catch(function (e) { A.fail(box, e); O.clear(rbox); O.clear(cbox); });
});
</script>
@endpush
