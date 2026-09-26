{{-- /account/customers/{id} — one client of the agent / broker: profile, policies and quotes.
     Agent: GET /mobile/agent/clients/{id} + /mobile/partner/agent/quotes|policies. Broker: GET /mobile/broker/clients/{id} (policies_detail) + /mobile/partner/broker/quotes. --}}
@php $K = __('account_agent'); @endphp
@extends('public.account.layout', ['title' => $K['client_t'], 'lede' => $K['client_lede'], 'crumbs' => [[$K['customers_t'], '/account/customers'], [$K['client_t'], null]], 'active' => 'customers'])
@section('content')
@include('public.account.agent.assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
  <section class="acard"><h2>{{ $K['js']['client_policies'] }}</h2><div data-policies></div></section>
  <section class="acard"><h2>{{ $K['js']['client_quotes'] }}</h2><div data-quotes></div></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, O = Opes, h = O.h;
  if (!A.guard(ctx)) return;
  var id = ctx.ids[0], box = O.$('[data-page-body]'), pbox = O.$('[data-policies]'), qbox = O.$('[data-quotes]');
  O.loading(box); O.loading(pbox); O.loading(qbox);
  return A.client(id).then(function (c) {
    var initials = String(c.full_name || '').split(/\s+/).map(function (p) { return p.charAt(0); }).join('').slice(0, 2).toUpperCase();
    O.clear(box).append(
      h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('span', { class: 'av' }, initials || '··'), h('div', null, h('h2', null, c.full_name), h('small', null, [c.phone_e164, c.city].filter(Boolean).join(' · ')))),
        h('div', { class: 'btns' }, h('a', { class: 'dbtn dbtn-outline sm', href: '/account/customers' }, A.t('back')), A.canQuote() ? h('a', { class: 'dbtn dbtn-primary sm', href: '/account/buy?customer=' + encodeURIComponent(c.id) }, O.icon('compare'), A.t('new_quote_client')) : null)),
      h('hr', { class: 'ag-hr' }),
      h('div', { class: 'ag-fields' },
        A.field(A.t('th_phone'), c.phone_e164), A.field(A.t('th_city'), c.city),
        c.kyc_status !== undefined ? A.field(A.t('th_kyc'), O.chip(c.kyc_status, A.label(c.kyc_status))) : A.field(A.t('th_outstanding'), A.money(c.outstanding_minor)),
        A.field(A.t('stat_policies'), String(c.policy_count || 0)), A.field(A.t('th_renewal'), O.date(c.renewal_due_at)),
        A.field(A.t('origin'), c.origin_locked ? A.t('origin_locked') : A.t('origin_open'))));

    // Policies: the broker detail carries them; the agent list is filtered by this client's name (the agent policy shape has no customer id).
    var pols = c.policies_detail ? Promise.resolve(c.policies_detail) : A.policies().then(function (rows) { return rows.filter(function (p) { return p.customer_id ? p.customer_id === c.id : (p.party_id && c.party_id ? p.party_id === c.party_id : p.customer_name === c.full_name); }); });
    pols.then(function (rows) {
      if (!rows.length) return O.empty(pbox, A.t('no_client_policies'));
      O.clear(pbox).appendChild(A.table(['th_policy', 'th_insurer', 'th_line', 'th_premium', 'th_status', 'th_issued'], rows.map(function (p) {
        return h('tr', null, h('td', null, h('b', null, p.policy_number || '—')), h('td', null, p.carrier_name || '—'), h('td', null, A.line(p.line_code)),
          h('td', { class: 'amt' }, A.money(p.premium_minor)), h('td', null, O.chip(p.status)), h('td', null, O.date(p.issued_at)));
      })));
    }).catch(function (e) { A.fail(pbox, e); });

    A.quotes().then(function (rows) {
      rows = rows.filter(function (q) { return q.customer_id === c.id; });
      if (!rows.length) return O.empty(qbox, A.t('no_client_quotes'), !A.canQuote() ? null : h('a', { class: 'dbtn dbtn-primary sm', href: '/account/buy?customer=' + encodeURIComponent(c.id) }, A.t('new_quote_client')));
      O.clear(qbox).appendChild(A.table(['th_line', 'th_offers', 'th_best', 'th_status', 'th_created', 'th_expires'], rows.map(function (q) {
        return h('tr', null, h('td', null, h('b', null, A.line(q.line_code))), h('td', null, String(q.offers || 0)), h('td', { class: 'amt' }, A.money(q.best_premium_minor)),
          h('td', null, O.chip(q.status)), h('td', null, O.date(q.created_at)), h('td', null, O.date(q.expires_at)));
      })));
    }).catch(function (e) { A.fail(qbox, e); });
  }).catch(function (e) { A.fail(box, e); O.clear(pbox); O.clear(qbox); });
});
</script>
@endpush
