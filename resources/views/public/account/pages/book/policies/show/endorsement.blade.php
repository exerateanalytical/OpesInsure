{{-- /account/book/policies/{id}/endorsement — AGT-042 Endorsement Request (agent-assisted change on a book policy).
     POST /mobile/partner/agent/policies/{id}/service-requests (ServiceRequestIntake, channel AGENT): the same REQUESTED
     row a customer raises from the app; insurer staff triage it into an endorsement. Tracked at /account/book/requests. --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['endorse_t'], 'lede' => $K['endorse_lede'], 'crumbs' => [[__('account_agent.book_t'), '/account/book?tab=policies'], [$K['endorse_t'], null]], 'active' => 'book'])
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
  var box = O.$('[data-page-body]');
  O.loading(box);
  return S.policy(ctx.ids[0]).then(function (p) {
    O.clear(box).append(S.policyHead(p), h('hr', { class: 'ag-hr' }));
    if (!S.canManage()) return box.appendChild(h('p', { class: 'sub' }, S.t('manage_only')));
    if (p.status !== 'ACTIVE') return box.appendChild(h('p', { class: 'sub' }, S.t('not_serviceable')));
    var type = h('select', { name: 'type', required: true }, ['ENDORSEMENT', 'ADDRESS_CHANGE', 'VEHICLE_CHANGE', 'BENEFICIARY_CHANGE', 'DOCUMENT_REISSUE'].map(function (t) { return h('option', { value: t }, S.label(t)); }));
    var reason = h('textarea', { name: 'reason', rows: 5, minlength: 5, maxlength: 4000, required: true, placeholder: S.t('reason_ph') });
    var btn = h('button', { type: 'submit', class: 'dbtn dbtn-primary sm' }, O.icon('send'), S.t('submit_request'));
    var idem = O.uuid();
    var form = h('form', { class: 'bform', novalidate: true }, S.field(S.t('f_request_type'), type, true), S.field(S.t('f_reason'), reason, true),
      h('p', { class: 'sub' }, S.t('endorse_note')), h('div', { class: 'bactions' }, S.btn(S.t('back_policy'), S.policyUrl(p.id)), btn));
    form.addEventListener('submit', function (e) {
      e.preventDefault(); O.alert('');
      if (reason.value.trim().length < 5) return O.alert(S.t('reason_short'), 'bad');
      O.busy(btn, true);
      O.api('/mobile/partner/agent/policies/' + S.enc(p.id) + '/service-requests', { body: { type: type.value, reason: reason.value.trim() }, idemKey: idem }).then(function (t) {
        O.busy(btn, false); form.hidden = true;
        box.appendChild(h('div', { class: 'acct-empty' }, O.icon('check'), h('p', null, S.t('request_done', { n: t.transaction_number || '' })),
          S.btn(S.t('track_request'), '/account/book/requests/' + S.enc(t.id), 'dbtn-primary'), S.btn(S.t('back_policy'), S.policyUrl(p.id))));
      }).catch(function (err) { O.busy(btn, false); O.alert(A.errMsg(err), 'bad'); });
    });
    box.appendChild(form);
  }).catch(function (e) { A.fail(box, e); });
});
</script>
@endpush
