{{-- /account/agent/quotes/{id}/send — AGT-026 Send Quote: POST /quotes/{id}/send {channel, recipient} (quotes.send, book-scoped;
     same endpoint as the app). Answers the share link (48-char token, expires with the quote). --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['send_t'], 'lede' => $K['send_lede'], 'crumbs' => [[$K['dash_t'], '/account/agent'], [$K['js']['b_quote'], null], [$K['send_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <section class="acard" data-page-body></section>
  <section class="acard">
    <form class="ag-form" data-send-form>
      <label class="afield-s"><span>{{ $K['js']['s_channel'] }} *</span><select name="channel" required>@foreach ($K['js']['s_channels'] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></label>
      <label class="afield-s"><span>{{ $K['js']['s_recipient'] }}</span><input name="recipient" maxlength="191" autocomplete="off"></label>
      <button class="dbtn dbtn-primary sm" type="submit">{{ $K['js']['s_send'] }}</button>
    </form>
    <div data-sent></div>
  </section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var id = ctx.ids[0], box = $('[data-page-body]'), form = $('[data-send-form]');
  O.$$('.crumbs span').forEach(function (el) { if (el.textContent === L.t('b_quote')) el.replaceWith(h('a', { href: '/account/agent/quotes/' + encodeURIComponent(id) }, el.textContent)); });
  O.loading(box);
  O.api('/quotes/' + encodeURIComponent(id)).then(function (r) {
    var q = r.quote || {}, best = (r.offers || [])[0];
    O.clear(box).append(h('h2', null, (q.quote_number || '') + ' · ' + A.line(q.line_code)), h('div', { class: 'ag-fields' },
      A.field(L.t('status'), O.chip(q.lifecycle_state || q.status)), A.field(L.t('q_best'), best ? A.money(best.total_minor) : null), A.field(L.t('expires'), O.date(q.expires_at))));
  }).catch(function (e) { A.fail(box, e); form.hidden = true; });
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var btn = form.querySelector('[type=submit]'), body = { channel: form.elements.channel.value }, rc = form.elements.recipient.value.trim();
    if (rc) body.recipient = rc;
    O.busy(btn, true); O.alert('');
    O.api('/quotes/' + encodeURIComponent(id) + '/send', { body: body }).then(function (r) {
      O.busy(btn, false);
      var url = location.origin + (r.link_path || ''), inp = h('input', { value: url, readonly: 'readonly', 'aria-label': L.t('s_sent') });
      O.clear($('[data-sent]')).append(h('p', { class: 'sub' }, L.t('s_sent')), inp, h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { inp.select(); try { navigator.clipboard.writeText(url); } catch (x) {} O.alert(L.t('s_copied'), 'ok'); } }, L.t('s_copy')),
        h('small', { class: 'muted', style: 'display:block' }, L.t('expires') + ' ' + O.date(r.expires_at, true)));
    }).catch(function (e) { O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
  });
});
</script>
@endpush
