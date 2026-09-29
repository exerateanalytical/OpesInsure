{{-- /account/complaints[?policy=<id>|claim_id=<id>] — SHR-013 Complaint Form + the customer's complaint list.
     GET/POST /mobile/complaints (own-party layer over the REQ-CPL-001 complaint engine, channel PORTAL); details on /account/complaints/{id}. --}}
@extends('public.account.layout', ['title' => __('launch_customer.complaints.title'), 'lede' => __('launch_customer.complaints.lede'), 'crumbs' => [[__('account_policies.sup.title'), '/account/support'], [__('launch_customer.complaints.title'), null]], 'active' => 'support'])
@section('content')
@include('public.account.partials.launch-assets')
<div class="agrid main-side op-ms380">
  <section class="acard" data-page-body data-complaints></section>
  <section class="acard" data-new></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, C = LC.T.cpl, box = Opes.$('[data-page-body]'), nb = Opes.$('[data-new]');
  var policyId = ctx.params.get('policy'), claimId = ctx.params.get('claim_id');
  var about = h('select', { name: 'about' }, h('option', { value: '' }, C.general));
  var send = h('button', { type: 'submit', class: 'dbtn dbtn-primary wide' }, Opes.icon('send'), C.send);
  var form = h('form', { style: 'display:grid;gap:12px', 'data-complaint-form': '', onsubmit: function (e) {
    e.preventDefault();
    var body = { description: form.elements.description.value.trim(), contact: form.elements.contact.value.trim() || null }, a = about.value;
    if (a.indexOf('policy:') === 0) body.policy_id = a.slice(7); else if (a.indexOf('claim:') === 0) body.claim_id = a.slice(6);
    Opes.busy(send, true);
    Opes.api('/mobile/complaints', { body: body }).then(function (c) {
      Opes.alert(LC.fmt(C.sent, { n: (c && c.complaint_number) || '' }), 'ok'); form.reset(); return load();
    }).catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(send, false); });
  } },
    h('label', { class: 'afield-s' }, h('span', null, C.about), about),
    h('label', { class: 'afield-s' }, h('span', null, C.desc), h('textarea', { name: 'description', rows: 6, minlength: 10, maxlength: 10000, required: true })),
    h('label', { class: 'afield-s' }, h('span', null, C.contact), h('input', { name: 'contact', maxlength: 255, autocomplete: 'email' })),
    send);
  Opes.clear(nb).append(h('h2', null, C.new_t), h('p', { class: 'sub' }, C.new_d), form, h('p', { class: 'op-muted', style: 'margin-top:12px' }, C.support, ' ', h('a', { href: '/account/support' }, C.open_support)));

  // Own policies / claims as complaint subjects (same list endpoints as the rest of the account).
  LC.soft(OP.policies()).then(function (pols) { (pols || []).forEach(function (p) { about.appendChild(h('option', { value: 'policy:' + p.id, selected: p.id === policyId }, LC.fmt(C.policy, { n: p.policy_number || OP.title(p) }))); }); });
  LC.soft(OP.claims()).then(function (cls) { (cls || []).forEach(function (c) { about.appendChild(h('option', { value: 'claim:' + c.id, selected: c.id === claimId }, LC.fmt(C.claim, { n: c.claim_number || '' }))); }); });

  function load() {
    Opes.loading(box);
    return Opes.api('/mobile/complaints').then(function (list) {
      list = list || [];
      Opes.clear(box).appendChild(h('h2', null, C.list_t));
      if (!list.length) { var e = h('div'); box.appendChild(e); Opes.empty(e, C.none); return; }
      box.appendChild(OP.table([
        [C.number, function (c) { return h('b', null, c.complaint_number); }],
        [C.received, function (c) { return Opes.date(c.received_at); }],
        [C.status, function (c) { return Opes.chip(c.status); }],
        [C.due, function (c) { return c.open && c.due_at ? Opes.date(c.due_at) : '—'; }],
        ['', function (c) { return OP.btn(OP.T.view, '/account/complaints/' + c.id); }]
      ], list, 'op-stack'));
    }).catch(function (e) { Opes.fail(box, e); });
  }
  return load();
});
</script>
@endpush
