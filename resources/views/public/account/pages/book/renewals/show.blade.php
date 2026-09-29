{{-- /account/book/renewals/{policyId} — AGT-045 Renewal Details (agent): one expiring policy of the book, its renewal work status and
     the renewal actions (new quote for the client, which the client then pays — same assisted-sale flow as the app).
     GET /mobile/agent/renewals (the row; id = policy id) + /mobile/partner/agent/policies/{id}. --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['renewal_t'], 'lede' => $K['renewal_lede'], 'crumbs' => [[__('account_agent.book_t'), '/account/book'], [$K['renewal_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var id = ctx.ids[0], box = O.$('[data-page-body]');
  O.loading(box);
  return Promise.all([S.policy(id), A.renewals().catch(function () { return []; })]).then(function (r) {
    var p = r[0], row = r[1].filter(function (x) { return x.id === p.id; })[0] || null, days = A.days(p.coverage_ends_at);
    var window_ = days === null ? '—' : days < 0 ? S.t('win_lapsed') : days <= 7 ? '7' : days <= 15 ? '15' : days <= 30 ? '30' : days <= 60 ? '60' : '90';
    var acts = [S.btn(S.t('back_policy'), S.policyUrl(p.id))];
    if (p.customer_id && A.canQuote()) acts.push(S.btn(S.t('renew_quote'), '/account/buy?customer=' + S.enc(p.customer_id), 'dbtn-primary', 'refresh'));
    if (p.customer_id) acts.push(S.btn(S.t('open_client'), S.clientUrl(p.customer_id), 'dbtn-outline', 'user'));
    O.clear(box).append(
      h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('span', { class: 'av' }, O.icon('refresh')), h('div', null, h('h2', null, p.policy_number || '—'), h('small', null, [p.customer_name, (p.carrier_short_name || p.carrier_name)].filter(Boolean).join(' · ')))),
        h('div', { class: 'btns' }, S.chip(row ? row.status : (days !== null && days <= 60 ? 'DUE' : 'NOT_DUE')))),
      h('hr', { class: 'ag-hr' }),
      h('div', { class: 'ag-fields' },
        A.field(S.t('f_client'), S.clientLink(p.customer_id, p.customer_name)), A.field(S.t('f_line'), A.line(p.line_code)), A.field(S.t('f_insurer'), (p.carrier_short_name || p.carrier_name)),
        A.field(S.t('f_premium'), S.money(p.premium_minor)), A.field(S.t('f_ends'), O.date(p.coverage_ends_at)),
        A.field(S.t('f_days_left'), days === null ? '—' : (days < 0 ? S.t('expired_ago', { n: -days }) : S.t('days_left', { n: days }))),
        A.field(S.t('f_window'), window_ === '—' || window_ === S.t('win_lapsed') ? window_ : S.t('win_days', { n: window_ }))),
      h('p', { class: 'sub' }, S.t(row ? 'renewal_note' : 'renewal_not_due')),
      h('div', { class: 'bactions' }, acts));
  }).catch(function (e) { A.fail(box, e); });
});
</script>
@endpush
