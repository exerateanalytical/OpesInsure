{{-- /account/customers/{id}/kyc — AGT-014 Customer KYC + AGT-015 KYC Document Capture, for a client in the agent's own book.
     GET /mobile/partner/agent/clients/{id}/kyc; capture: POST .../documents (stored as the client's document) then POST .../kyc/documents;
     POST .../kyc/submit. Another agent's client answers 403. --}}
@php $K = __('launch_agent_a'); $C = __('account_agent'); @endphp
@extends('public.account.layout', ['title' => $K['kyc_t'], 'lede' => $K['kyc_lede'], 'crumbs' => [[$C['customers_t'], '/account/customers'], [$C['client_t'], null], [$K['kyc_t'], null]], 'active' => 'customers'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <div class="desk-main">
    <div>
      <section class="acard" data-page-body></section>
      <section class="acard" data-docs></section>
    </div>
    <aside class="desk-side">
      <section class="acard" data-capture hidden>
        <h2>{{ $K['js']['kyc_capture'] }}</h2>
        <form class="ag-form" data-kyc-form>
          <label class="afield-s"><span>{{ $K['js']['kyc_purpose'] }} *</span><select name="purpose" required data-purpose></select></label>
          <label class="afield-s"><span>{{ $K['js']['kyc_file'] }} *</span><input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label>
          <button class="dbtn dbtn-primary sm" type="submit">{{ $K['js']['kyc_upload'] }}</button>
        </form>
      </section>
      <section class="acard" data-submit hidden>
        <h2>{{ $K['js']['kyc_submit'] }}</h2>
        <form class="ag-form" data-submit-form>
          <label class="afield-s"><span>{{ $K['js']['kyc_notes'] }}</span><textarea name="notes" rows="3" maxlength="2000"></textarea></label>
          <button class="dbtn dbtn-primary sm" type="submit">{{ $K['js']['kyc_submit'] }}</button>
        </form>
      </section>
    </aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var id = ctx.ids[0], base = '/mobile/partner/agent/clients/' + encodeURIComponent(id), box = $('[data-page-body]'), docs = $('[data-docs]');
  var EDITABLE = ['DRAFT', 'MORE_INFO_REQUIRED'], canManage = O.can('agent.clients.manage');
  O.$$('.crumbs a, .crumbs span').forEach(function (el) { if (el.textContent === @json($C['client_t'])) el.replaceWith(h('a', { href: '/account/customers/' + encodeURIComponent(id) }, el.textContent)); });
  $('[data-kyc-form]').addEventListener('submit', capture);
  $('[data-submit-form]').addEventListener('submit', submit);
  load();

  function load() {
    O.loading(box); O.loading(docs);
    return Promise.all([A.client(id), O.api(base + '/kyc')]).then(function (r) { paint(r[0], r[1].submission); }).catch(function (e) { A.fail(box, e); O.clear(docs); });
  }
  function paint(c, s) {
    var st = s ? String(s.status).toUpperCase() : (c.kyc_status || 'NOT_STARTED'), editable = !s || EDITABLE.indexOf(st) >= 0;
    O.clear(box).append(h('div', { class: 'ag-head' }, h('div', { class: 'who' }, h('div', null, h('h2', null, c.full_name), h('small', null, [c.phone_e164, c.city].filter(Boolean).join(' · ')))),
        h('div', { class: 'btns' }, L.link(L.t('back'), '/account/customers/' + encodeURIComponent(id)), L.link(L.t('act_note'), '/account/customers/' + encodeURIComponent(id) + '/activities'))),
      h('hr', { class: 'ag-hr' }),
      h('div', { class: 'ag-fields' }, A.field(L.t('kyc_status'), O.chip(st, A.label(st))), A.field(L.t('kyc_level'), s && s.kyc_level), A.field(A.t('th_updated'), O.date(s && (s.submitted_at || s.reviewed_at))),
        A.field(L.t('expires'), O.date(s && s.expires_at))),
      s && s.remediation_reason ? h('p', { class: 'bnote' }, s.remediation_reason) : null,
      !s ? h('p', { class: 'sub' }, L.t('kyc_none')) : null,
      s && !editable ? h('p', { class: 'sub' }, L.t('kyc_locked')) : null);
    var reqs = (s && s.requirements) || [];
    if (reqs.length) box.appendChild(h('div', null, h('h2', null, L.t('kyc_req')), L.table(['kyc_purpose', 'status'], reqs.map(function (q) {
      return h('tr', null, h('td', null, h('b', null, O.label(q.requirement_code)), q.mandatory ? h('small', { class: 'muted' }, ' · ' + L.t('p_mandatory')) : null),
        h('td', null, q.satisfied ? h('span', { class: 'st st-ok' }, L.t('kyc_ok')) : h('span', { class: 'st st-bad' }, L.t('kyc_missing'))));
    }))));
    O.clear(docs).appendChild(h('h2', null, L.t('kyc_docs')));
    var list = (s && s.documents) || [];
    if (!list.length) { var e = h('div'); docs.appendChild(e); O.empty(e, L.t('none')); }
    else docs.appendChild(L.table(['kyc_purpose', 'scan', 'verification'], list.map(function (d) {
      return h('tr', null, h('td', null, h('b', null, O.label(d.purpose)), h('span', { class: 'muted' }, d.category || '')), h('td', null, O.chip(d.scan_status, A.label(d.scan_status))), h('td', null, O.chip(d.verification_status, A.label(d.verification_status))));
    })));
    var sel = $('[data-purpose]'), opts = reqs.filter(function (q) { return !q.satisfied; }).map(function (q) { return q.requirement_code; });
    if (!opts.length) opts = reqs.map(function (q) { return q.requirement_code; });
    O.clear(sel).append.apply(sel, opts.concat(['OTHER']).filter(function (v, i, a) { return a.indexOf(v) === i; }).map(function (v) { return h('option', { value: v }, v === 'OTHER' ? L.t('other_doc') : O.label(v)); }));
    $('[data-capture]').hidden = !(canManage && editable);
    $('[data-submit]').hidden = !(canManage && s && editable && list.length);
  }
  function capture(ev) {
    ev.preventDefault();
    var f = ev.target, btn = f.querySelector('[type=submit]'), purpose = f.elements.purpose.value;
    O.busy(btn, true); O.alert('');
    L.uploadFor(id, f.elements.file, 'KYC').then(function (doc) { return O.api(base + '/kyc/documents', { body: { document_id: doc.id, purpose: purpose } }); })
      .then(function () { O.busy(btn, false); f.reset(); O.alert(L.t('kyc_uploaded'), 'ok'); load(); })
      .catch(function (e) { O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
  }
  function submit(ev) {
    ev.preventDefault();
    var f = ev.target, btn = f.querySelector('[type=submit]'), notes = f.elements.notes.value.trim();
    O.busy(btn, true); O.alert('');
    O.api(base + '/kyc/submit', { body: notes ? { notes: notes } : {} }).then(function () { O.busy(btn, false); O.alert(L.t('kyc_submitted'), 'ok'); load(); })
      .catch(function (e) { O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
  }
});
</script>
@endpush
