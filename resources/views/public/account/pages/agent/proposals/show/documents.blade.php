{{-- /account/agent/proposals/{id}/documents — AGT-030 Proposal Documents: the insurer's requirements (GET /proposals/{id}/checklist →
     required_documents, book-scoped) and capture for each: POST /mobile/partner/agent/clients/{customer}/documents (stored as the client's
     document) then POST /proposals/{id}/documents {document_id, requirement_code} — the app's link call. --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['pdocs_t'], 'lede' => $K['pdocs_lede'], 'crumbs' => [[$K['dash_t'], '/account/agent'], [$K['proposal_t'], null], [$K['pdocs_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.launch-a')
@include('public.partials.pending-scan')
<div data-agent class="agrid"><section class="acard" data-page-body></section></div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var id = ctx.ids[0], e = encodeURIComponent(id), box = $('[data-page-body]'), LOCKED = ['DECLINED', 'WITHDRAWN', 'PAYMENT_PENDING', 'APPROVED'];
  O.$$('.crumbs span').forEach(function (el) { if (el.textContent === @json($K['proposal_t'])) el.replaceWith(h('a', { href: '/account/agent/proposals/' + e }, el.textContent)); });
  load();
  function load() {
    O.loading(box);
    return Promise.all([O.api('/proposals/' + e + '/checklist'), L.soft(L.proposalRow(id))]).then(function (r) { paint(r[0], r[1] || {}); }).catch(function (err) { A.fail(box, err); });
  }
  function paint(c, row) {
    var st = String(c.status).toUpperCase(), locked = LOCKED.indexOf(st) >= 0 || !row.customer_id || !O.can('agent.clients.manage'), docs = (c.required_documents || []).filter(function (d, i, a) { return a.findIndex(function (x) { return x.code === d.code; }) === i; });
    O.clear(box).append(h('div', { class: 'acard-h' }, h('h2', null, L.t('pr_number') + ' · ' + (row.proposal_number || '')), O.chip(st, A.label(st))), LOCKED.indexOf(st) >= 0 ? h('p', { class: 'sub' }, L.t('d_locked')) : null);
    var held = window.OpesPendingScan && OpesPendingScan.render(c.pending_documents); // S4
    if (held) box.appendChild(held);
    if (!docs.length) { var x = h('div'); box.appendChild(x); O.empty(x, L.t('none')); return; }
    box.appendChild(L.table(['d_req', 'd_level', 'status', 'actions'], docs.map(function (d) {
      var cell = h('td', { class: 'acts' });
      if (d.satisfied_by && d.satisfied_by !== 'UPLOAD') cell.appendChild(h('small', { class: 'muted' }, L.t('d_form')));
      else if (!locked) {
        var inp = h('input', { type: 'file', accept: 'application/pdf,image/jpeg,image/png', 'aria-label': L.t('d_upload') + ' ' + (L.tr(d.name) || d.label || d.code) });
        var b = h('button', { type: 'button', class: 'dbtn dbtn-primary sm', 'data-upload': d.code }, L.t('d_upload'));
        b.addEventListener('click', function () {
          O.busy(b, true); O.alert('');
          L.uploadFor(row.customer_id, inp, 'PROPOSAL').then(function (doc) { return O.api('/proposals/' + e + '/documents', { body: { document_id: doc.id, requirement_code: d.code } }); })
            .then(function () { O.alert(L.t('d_done'), 'ok'); load(); }).catch(function (err) { O.busy(b, false); O.alert(A.errMsg(err), 'bad'); });
        });
        cell.append(inp, b);
      } else cell.textContent = '—';
      return h('tr', null, h('td', null, h('b', null, L.tr(d.name) || d.label || O.label(d.code))), h('td', null, d.mandatory ? L.t('p_mandatory') : L.t('p_optional')), h('td', null, O.chip(d.status, A.label(d.status))), cell);
    })));
  }
});
</script>
@endpush
