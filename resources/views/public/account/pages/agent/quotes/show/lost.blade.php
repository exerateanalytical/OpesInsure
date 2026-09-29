{{-- /account/agent/quotes/{id}/lost — AGT-028 Lost Quote: POST /quotes/{id}/decline {reason_code, note} (quotes.manage, book-scoped;
     reason codes = QuoteService::DECLINE_REASONS, same endpoint as the app). --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['lost_t'], 'lede' => $K['lost_lede'], 'crumbs' => [[$K['dash_t'], '/account/agent'], [$K['js']['b_quote'], null], [$K['lost_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
  <section class="acard">
    <form class="ag-form" data-lost-form>
      <label class="afield-s"><span>{{ $K['js']['reason'] }} *</span><select name="reason_code" required>@foreach ($K['js']['l_reasons'] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></label>
      <label class="afield-s"><span>{{ $K['js']['note'] }}</span><textarea name="note" rows="3" maxlength="1000"></textarea></label>
      <button class="dbtn dbtn-primary sm" type="submit">{{ $K['js']['l_submit'] }}</button>
    </form>
  </section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var id = ctx.ids[0], box = $('[data-page-body]'), form = $('[data-lost-form]');
  O.$$('.crumbs span').forEach(function (el) { if (el.textContent === L.t('b_quote')) el.replaceWith(h('a', { href: '/account/agent/quotes/' + encodeURIComponent(id) }, el.textContent)); });
  O.loading(box);
  var show = function (q) { O.clear(box).append(h('h2', null, (q.quote_number || '') + ' · ' + A.line(q.line_code)), h('div', { class: 'ag-fields' }, A.field(L.t('status'), O.chip(q.lifecycle_state || q.status)), A.field(L.t('expires'), O.date(q.expires_at)))); };
  O.api('/quotes/' + encodeURIComponent(id)).then(function (r) { show(r.quote || {}); }).catch(function (e) { A.fail(box, e); form.hidden = true; });
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!window.confirm(L.t('l_confirm'))) return;
    var btn = form.querySelector('[type=submit]'), body = { reason_code: form.elements.reason_code.value }, n = form.elements.note.value.trim();
    if (n) body.note = n;
    O.busy(btn, true); O.alert('');
    O.api('/quotes/' + encodeURIComponent(id) + '/decline', { body: body }).then(function (q) { form.hidden = true; show(q); O.alert(L.t('l_done'), 'ok'); })
      .catch(function (e) { O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
  });
});
</script>
@endpush
