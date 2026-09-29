{{-- /account/agent/proposals/{id}/information — AGT-033 Information Request: the underwriter's request (GET /proposals/{id}/checklist →
     information_request, book-scoped), supporting files via the proposal documents screen, and the answer: POST /proposals/{id}/resubmit
     {response} (INFORMATION_REQUIRED → RESUBMITTED). --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['info_t'], 'lede' => $K['info_lede'], 'crumbs' => [[$K['dash_t'], '/account/agent'], [$K['proposal_t'], null], [$K['info_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
  <section class="acard" data-answer hidden>
    <form class="ag-form" data-info-form>
      <label class="afield-s"><span>{{ $K['js']['i_response'] }} *</span><textarea name="response" rows="4" maxlength="4000" required></textarea></label>
      <button class="dbtn dbtn-primary sm" type="submit">{{ $K['js']['i_send'] }}</button>
    </form>
  </section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var id = ctx.ids[0], e = encodeURIComponent(id), box = $('[data-page-body]'), form = $('[data-info-form]');
  O.$$('.crumbs span').forEach(function (el) { if (el.textContent === @json($K['proposal_t'])) el.replaceWith(h('a', { href: '/account/agent/proposals/' + e }, el.textContent)); });
  form.addEventListener('submit', send);
  load();
  function load() {
    O.loading(box);
    return O.api('/proposals/' + e + '/checklist').then(paint).catch(function (err) { A.fail(box, err); });
  }
  function paint(c) {
    var st = String(c.status).toUpperCase(), r = c.information_request;
    O.clear(box).append(h('div', { class: 'acard-h' }, h('h2', null, L.t('i_items')), O.chip(st, A.label(st))));
    if (!r) { var x = h('div'); box.appendChild(x); O.empty(x, L.t('i_none')); $('[data-answer]').hidden = true; return; }
    box.append(h('p', { class: 'sub' }, L.t('i_requested') + ' ' + O.date(r.requested_at, true)),
      h('ul', { 'data-info-items': '' }, (r.items || []).map(function (i) { return h('li', null, i.code ? h('b', null, O.label(i.code) + ' — ') : null, i.description); })),
      r.message ? h('div', null, h('h3', null, L.t('i_message')), h('p', null, r.message)) : null,
      r.responded_at ? h('p', { class: 'sub' }, O.date(r.responded_at, true) + ' — ' + (r.response || '')) : null,
      L.link(L.t('pr_manage_docs'), '/account/agent/proposals/' + e + '/documents', null, 'doc'));
    $('[data-answer]').hidden = !(st === 'INFORMATION_REQUIRED' && O.can('agent.clients.manage'));
  }
  function send(ev) {
    ev.preventDefault();
    var btn = form.querySelector('[type=submit]');
    O.busy(btn, true); O.alert('');
    O.api('/proposals/' + e + '/resubmit', { body: { response: form.elements.response.value.trim() } }).then(function () { O.busy(btn, false); form.reset(); O.alert(L.t('i_sent'), 'ok'); load(); })
      .catch(function (err) { O.busy(btn, false); O.alert(A.errMsg(err), 'bad'); });
  }
});
</script>
@endpush
