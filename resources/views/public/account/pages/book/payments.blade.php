{{-- /account/book/payments — AGT-037 Payment Assistance (agent): premiums to collect on assisted sales (prompt the client to pay:
     POST /mobile/agent/sales/{id}/payment-request, the same action as the app) and the payment attempts of the book with their status.
     GET /mobile/partner/agent/quotes + /mobile/partner/agent/payments (book-scoped). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['payments_t'], 'lede' => $K['payments_lede'], 'crumbs' => [[__('account_agent.book_t'), '/account/book'], [$K['payments_t'], null]], 'active' => 'book'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
  <section class="acard"><h2>{{ $K['js']['sec_to_collect'] }}</h2><div data-collect></div></section>
  <section class="acard" data-page-body><h2>{{ $K['js']['sec_payments'] }}</h2><div class="desk-tools" data-tools></div><div data-rows></div></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var cbox = O.$('[data-collect]'), box = O.$('[data-rows]'), all = [], filter = 'open', OPEN = ['CREATED', 'PENDING_CUSTOMER', 'PROCESSING', 'AWAITING_TRANSFER', 'FAILED', 'EXPIRED'];
  O.loading(cbox); O.loading(box);

  function drawCollect(quotes) {
    var rows = quotes.filter(function (q) { return q.assisted && q.best_premium_minor && q.payment_status !== 'PAID' && ['ACCEPTED', 'EXPIRED', 'CANCELLED'].indexOf(q.status) < 0; });
    if (!rows.length) return O.empty(cbox, S.t('nothing_to_collect'));
    O.clear(cbox).appendChild(S.table(['th_client', 'th_line', 'th_amount', 'th_status', 'th_created', 'th_actions'], rows.map(function (q) {
      var cell = h('td', { class: 'acts' });
      function paint() {
        O.clear(cell);
        if (q.payment_status === 'CUSTOMER_PROMPTED') return cell.appendChild(S.chip('CUSTOMER_PROMPTED'));
        if (!S.canManage()) return cell.appendChild(document.createTextNode('—'));
        cell.appendChild(h('button', { type: 'button', class: 'dbtn dbtn-primary sm', onclick: function () {
          var b = this; O.busy(b, true); O.alert('');
          O.api('/mobile/agent/sales/' + S.enc(q.id) + '/payment-request', { body: {} }).then(function (s) {
            q.payment_status = s.payment_status; O.busy(b, false); paint(); A.saleAlert(s, q.customer_name);
          }).catch(function (e) { O.busy(b, false); O.alert(A.errMsg(e), 'bad'); });
        } }, O.icon('send'), S.t('prompt_pay')));
      }
      paint();
      return h('tr', null, h('td', null, S.clientLink(q.customer_id, q.customer_name)), h('td', null, A.line(q.line_code)), h('td', { class: 'amt' }, S.money(q.best_premium_minor)),
        h('td', null, S.chip(q.payment_status || q.status)), h('td', null, O.date(q.created_at)), cell);
    })));
  }
  function render() {
    var rows = all.filter(function (p) { return filter === 'all' || OPEN.indexOf(p.status) >= 0; });
    if (!all.length) return O.empty(box, S.t('no_payments'));
    if (!rows.length) return O.empty(box, S.t('no_open_payments'));
    O.clear(box).appendChild(S.table(['th_client', 'th_proposal', 'th_amount', 'th_provider', 'th_status', 'th_created'], rows.map(function (p) {
      return S.row([S.clientLink(p.customer_id, p.customer_name), p.proposal_number || '—', h('span', { class: 'amt' }, S.money(p.amount_minor)), S.label(p.provider), S.chip(p.status), O.date(p.created_at, true)]);
    })));
  }
  var sel = h('select', { 'aria-label': S.t('filter'), onchange: function () { filter = this.value; render(); } }, h('option', { value: 'open' }, S.t('flt_open')), h('option', { value: 'all' }, S.t('flt_all')));
  O.$('[data-tools]').appendChild(sel);
  A.quotes().then(drawCollect).catch(function (e) { A.fail(cbox, e); });
  return S.payments().then(function (rows) { all = rows; render(); }).catch(function (e) { A.fail(box, e); });
});
</script>
@endpush
