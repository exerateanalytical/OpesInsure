{{-- /account/complaints/{id} — SHR-014 Complaint Details: status, received / acknowledged / due dates, outcome and answer,
     escalation, handling steps and the correspondence with the customer. GET /mobile/complaints/{id} (own party only; else 404). --}}
@extends('public.account.layout', ['title' => __('launch_customer.complaint.title'), 'lede' => __('launch_customer.complaint.lede'), 'crumbs' => [[__('launch_customer.complaints.title'), '/account/complaints'], [__('launch_customer.complaint.title'), null]], 'active' => 'support'])
@section('content')
@include('public.account.partials.launch-assets')
<div class="agrid main-side op-ms380">
  <section class="acard" data-page-body data-complaint></section>
  <section class="acard" data-steps></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, C = LC.T.cpl, box = Opes.$('[data-page-body]'), sb = Opes.$('[data-steps]');
  Opes.loading(box); Opes.loading(sb);
  return Opes.api('/mobile/complaints/' + encodeURIComponent(ctx.ids[0])).then(function (c) {
    function row(k, v) { return v ? [h('dt', null, k), h('dd', null, v)] : null; }
    Opes.clear(box).append(h('div', { class: 'acard-h' }, h('h2', null, C.number + ' ' + c.complaint_number), Opes.chip(c.status)),
      h('dl', { class: 'kv' }, row(C.received, Opes.date(c.received_at, true)), row(C.ack, c.acknowledged_at && Opes.date(c.acknowledged_at, true)),
        row(C.due, c.open && c.due_at && Opes.date(c.due_at)), row(C.outcome, c.outcome && Opes.label(c.outcome)), row(C.communicated, c.communicated_at && Opes.date(c.communicated_at, true)),
        row(C.escalated, c.escalation_level && Opes.label(c.escalation_level))),
      h('p', { style: 'white-space:pre-wrap' }, c.description),
      c.resolution_summary ? h('div', { class: 'acct-alert', style: 'display:block' }, h('b', null, C.resolution + ' : '), c.resolution_summary) : null,
      h('div', { class: 'btnbar', style: 'margin-top:12px' }, OP.btn(C.back, '/account/complaints', 'dbtn-outline sm', 'chev-left')));
    var corr = c.correspondence || [];
    if (corr.length) box.append(h('h3', { style: 'font-size:15px;margin:16px 0 8px' }, C.corr_t), h('ul', { class: 'op-nlist' }, corr.map(function (m) {
      return h('li', null, h('span', { class: 'op-li' }, Opes.icon(m.direction === 'INBOUND' ? 'send' : 'mail')), h('div', null, h('b', null, m.subject_line), m.summary ? h('small', { class: 'op-muted', style: 'display:block;white-space:pre-wrap' }, m.summary) : null),
        h('small', null, (m.direction === 'INBOUND' ? C.in : C.out) + ' · ' + Opes.date(m.dispatched_at || m.received_at || m.created_at, true)));
    })));
    Opes.clear(sb).append(h('h2', null, C.timeline_t), h('ol', { class: 'op-nlist' }, (c.timeline || []).map(function (e) {
      return h('li', null, h('span', { class: 'op-li' }, Opes.icon('clock')), h('div', null, h('b', null, Opes.label(e.to_status || e.type))), h('small', null, Opes.date(e.occurred_at, true)));
    })));
  }).catch(function (e) { Opes.fail(box, e && e.status === 404 ? { message: C.not_found } : e); Opes.clear(sb); });
});
</script>
@endpush
