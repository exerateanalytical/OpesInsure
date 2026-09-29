{{-- /account/book/policies/{id}/cancel — AGT-046 Cancellation Assistance (agent): refund preview and a cancellation request on
     behalf of the client. POST /mobile/partner/agent/policies/{id}/cancellations/preview and /cancellations (CancellationService:
     no backdating; the insurer reviews and approves — the agent is the maker only). Permission policies.cancellation.request. --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['cancel_t'], 'lede' => $K['cancel_lede'], 'crumbs' => [[__('account_agent.book_t'), '/account/book?tab=policies'], [$K['cancel_t'], null]], 'active' => 'book'])
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
    if (!O.can('policies.cancellation.request')) return box.appendChild(h('p', { class: 'sub' }, S.t('cancel_no_perm')));
    if (p.status !== 'ACTIVE') return box.appendChild(h('p', { class: 'sub' }, S.t('not_serviceable')));
    var path = '/mobile/partner/agent/policies/' + S.enc(p.id) + '/cancellations', today = new Date().toISOString().slice(0, 10);
    var when = h('input', { type: 'date', name: 'effective_at', min: today, value: today, required: true });
    var who = h('select', { name: 'initiated_by' }, ['INSURED', 'INTERMEDIARY'].map(function (v) { return h('option', { value: v }, S.label('INIT_' + v)); }));
    var reason = h('select', { name: 'reason_code' }, ['CLIENT_REQUEST', 'SOLD_ASSET', 'DUPLICATE_COVER', 'FINANCIAL', 'OTHER'].map(function (v) { return h('option', { value: v }, S.label(v)); }));
    var notes = h('textarea', { name: 'notes', rows: 3, maxlength: 2000 });
    var consent = h('input', { type: 'checkbox', name: 'consent', required: true });
    var quote = h('div', { class: 'ag-fields' }), btnQ = h('button', { type: 'button', class: 'dbtn dbtn-outline sm' }, O.icon('scale'), S.t('preview'));
    var btn = h('button', { type: 'submit', class: 'dbtn dbtn-primary sm' }, O.icon('send'), S.t('cancel_submit')), idem = O.uuid();
    function preview() {
      O.alert(''); O.busy(btnQ, true);
      O.api(path + '/preview', { body: { effective_at: when.value, initiated_by: who.value }, idemKey: O.uuid() }).then(function (q) {
        O.busy(btnQ, false);
        O.clear(quote).append(A.field(S.t('f_refund'), S.money(q.refund_minor)), A.field(S.t('f_basis'), S.label(q.basis)), A.field(S.t('f_effective'), O.date(when.value)));
      }).catch(function (e) { O.busy(btnQ, false); O.alert(A.errMsg(e), 'bad'); });
    }
    btnQ.addEventListener('click', preview);
    var form = h('form', { class: 'bform', novalidate: true }, S.field(S.t('f_effective'), when, true), S.field(S.t('f_initiated_by'), who, true), S.field(S.t('f_reason'), reason, true),
      S.field(S.t('f_notes'), notes), h('div', { class: 'bactions' }, btnQ), quote,
      h('label', { class: 'afield-s' }, h('span', null, consent, ' ', S.t('cancel_consent'))),
      h('p', { class: 'sub' }, S.t('cancel_note')), h('div', { class: 'bactions' }, S.btn(S.t('back_policy'), S.policyUrl(p.id)), btn));
    form.addEventListener('submit', function (e) {
      e.preventDefault(); O.alert('');
      if (!when.value) return O.alert(S.t('date_required'), 'bad');
      if (!consent.checked) return O.alert(S.t('consent_required'), 'bad');
      O.busy(btn, true);
      O.api(path, { body: { effective_at: when.value, initiated_by: who.value, reason_code: reason.value, notes: notes.value.trim() || null }, idemKey: idem }).then(function (c) {
        O.busy(btn, false); form.hidden = true;
        box.appendChild(h('div', { class: 'acct-empty' }, O.icon('check'), h('p', null, S.t('cancel_done', { r: S.money(c.refund_minor) })), S.btn(S.t('back_policy'), S.policyUrl(p.id), 'dbtn-primary')));
      }).catch(function (err) { O.busy(btn, false); O.alert(A.errMsg(err), 'bad'); });
    });
    box.appendChild(form);
  }).catch(function (e) { A.fail(box, e); });
});
</script>
@endpush
