{{-- /account/customers/{id}/claim — agent-assisted FNOL for a client in the agent's book (UI audit 2026-09-27).
     GET /mobile/agent/clients/{id} + /mobile/partner/agent/policies (the client's active policies), then
     POST /mobile/partner/agent/claims or /mobile/partner/broker/claims (AgentFnolController → FnolService::submitForCustomer, which refuses a client outside the book). --}}
@php $K = __('account_agent'); @endphp
@extends('public.account.layout', ['title' => $K['claim_t'], 'lede' => $K['claim_lede'], 'crumbs' => [[$K['customers_t'], '/account/customers'], [$K['claim_t'], null]], 'active' => 'customers'])
@section('content')
@include('public.account.agent.assets')
<div data-agent class="agrid">
  <section class="acard" data-page-body>
    <form class="bform" data-claim-form novalidate hidden>
      <h2 data-client-name></h2>
      <label class="afield-s"><span>{{ $K['js']['claim_policy'] }} <i>*</i></span><select name="policy_id" required></select></label>
      <label class="afield-s"><span>{{ $K['js']['claim_loss_at'] }} <i>*</i></span><input type="datetime-local" name="loss_occurred_at" required></label>
      <label class="afield-s"><span>{{ $K['js']['claim_location'] }}</span><input name="loss_location" maxlength="255"></label>
      <label class="afield-s"><span>{{ $K['js']['claim_description'] }} <i>*</i></span><textarea name="description" rows="4" maxlength="4000" required></textarea></label>
      <label class="afield-s"><span>{{ $K['js']['claim_estimate'] }}</span><input type="number" name="estimate" min="0" step="1" inputmode="numeric"></label>
      <div class="bactions">
        <a class="dbtn dbtn-outline sm" data-back>{{ $K['js']['back'] }}</a>
        <button type="submit" class="dbtn dbtn-primary sm" data-submit>{{ $K['js']['claim_submit'] }}</button>
      </div>
    </form>
    <div data-state></div>
  </section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, O = Opes, h = O.h;
  if (!A.guard(ctx)) return;
  var id = ctx.ids[0], form = O.$('[data-claim-form]'), state = O.$('[data-state]'), back = '/account/customers/' + encodeURIComponent(id);
  O.$('[data-back]').setAttribute('href', back);
  if (!A.canFileClaim()) return O.empty(state, A.t('claim_agent_only'), h('a', { class: 'dbtn dbtn-outline sm', href: back }, A.t('back')));
  O.loading(state);
  var idem = O.uuid() + O.uuid();
  return Promise.all([A.client(id), A.policies()]).then(function (r) {
    var c = r[0], pols = r[1].filter(function (p) { return (p.customer_id ? p.customer_id === c.id : p.party_id === c.party_id) && p.status === 'ACTIVE'; });
    O.clear(state);
    if (!pols.length) return O.empty(state, A.t('claim_no_policy'), h('a', { class: 'dbtn dbtn-outline sm', href: back }, A.t('back')));
    O.$('[data-client-name]').textContent = c.full_name;
    var sel = form.elements.policy_id;
    pols.forEach(function (p) { sel.appendChild(h('option', { value: p.id }, [p.policy_number, (p.carrier_short_name || p.carrier_name), A.line(p.line_code)].filter(Boolean).join(' · '))); });
    if (ctx.params.get('policy')) sel.value = ctx.params.get('policy'); // from the agent policy page (AGT-040 → AGT-052)
    form.hidden = false;
    form.addEventListener('submit', function (e) {
      e.preventDefault(); O.alert('');
      var el = form.elements, desc = el.description.value.trim(), when = el.loss_occurred_at.value, est = el.estimate.value;
      if (!sel.value || !when || desc.length < 3) return O.alert(A.t('claim_missing'));
      var btn = O.$('[data-submit]'); O.busy(btn, true);
      O.api(A.claimPath(), { body: {
        policy_id: sel.value, claimant_party_id: c.party_id, loss_occurred_at: new Date(when).toISOString(),
        loss_details: { description: desc }, loss_location: el.loss_location.value.trim() || null,
        estimated_loss_minor: est === '' ? null : Math.round(Number(est) * 100), idempotency_key: idem,
      } }).then(function (cl) {
        O.busy(btn, false); form.hidden = true;
        O.clear(state).appendChild(h('div', { class: 'acct-empty' }, O.icon('check'), h('p', null, A.t('claim_done', { n: cl.claim_number || '', c: c.full_name })),
          h('a', { class: 'dbtn dbtn-primary sm', href: '/account/book?tab=claims' }, A.t('tab_claims')), h('a', { class: 'dbtn dbtn-outline sm', href: back }, A.t('back'))));
      }).catch(function (err) { O.busy(btn, false); O.alert(A.errMsg(err)); });
    });
  }).catch(function (e) { A.fail(state, e); });
});
</script>
@endpush
