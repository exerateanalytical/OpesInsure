{{-- /account/commissions — commission accruals and withdrawals.
     Agent: GET /mobile/agent/commissions, GET /mobile/agent/withdrawals, POST /mobile/agent/withdrawals (agent.withdrawals.request + step-up COMMISSION_WITHDRAWAL
     via POST /mobile/security/step-up/request|verify). Broker: GET /mobile/partner/broker/commissions (totals, accruals, statements). --}}
@php $K = __('account_agent'); @endphp
@extends('public.account.layout', ['title' => $K['commissions_t'], 'lede' => $K['commissions_lede'], 'crumbs' => [[$K['commissions_t'], null]], 'active' => 'commissions'])
@section('content')
@include('public.account.agent.assets')
<div data-agent class="agrid">
  <div class="desk-stats ag-stats4" data-stats></div>
  <div class="desk-main">
    <div class="agrid">
      <section class="acard" data-page-body>
        <h2>{{ $K['js']['accruals'] }}</h2>
        <div class="tabs-u" role="tablist" data-tabs></div>
        <div data-rows></div>
      </section>
      <section class="acard" data-second><h2 data-second-t>{{ $K['js']['withdrawals'] }}</h2><div data-second-rows></div></section>
    </div>
    <aside class="desk-side">
      <section class="acard" data-wd-card hidden>
        <h2>{{ $K['js']['request_wd'] }}</h2><p class="sub">{{ $K['js']['request_wd_sub'] }}</p>
        <form class="ag-form" data-wd-form>
          <label class="afield-s"><span>{{ $K['js']['f_provider'] }} *</span><select name="provider" required><option value="mtn_momo">MTN Mobile Money</option><option value="orange_money">Orange Money</option></select></label>
          <label class="afield-s"><span>{{ $K['js']['f_amount'] }} *</span><input name="amount" type="number" min="1" step="1" required inputmode="numeric"></label>
          <label class="afield-s"><span>{{ $K['js']['f_dest'] }} *</span><input name="destination_phone" required maxlength="32" inputmode="tel" placeholder="+2376…"></label>
          <label class="afield-s" data-code-row hidden><span>{{ $K['js']['f_code'] }} *</span><input name="code" inputmode="numeric" maxlength="6" minlength="6" autocomplete="one-time-code"></label>
          <button class="dbtn dbtn-primary sm" type="submit" data-wd-btn>{{ $K['js']['send_code'] }}</button>
        </form>
      </section>
      <section class="acard"><h2>{{ $K['js']['by_status'] }}</h2><div data-bars></div></section>
    </aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, O = Opes, h = O.h;
  if (!A.guard(ctx)) return;
  var box = O.$('[data-rows]'), sbox = O.$('[data-second-rows]'), all = [], tab = 'all';
  var amt = function (a) { return a.amount_minor || 0; };
  O.loading(box); O.loading(sbox);

  if (A.mode() === 'broker') {
    O.$('[data-second-t]').textContent = A.t('statements');
    return O.api('/mobile/partner/broker/commissions').then(function (d) {
      var t = d.totals || {}; all = d.accruals || [];
      A.stats(O.$('[data-stats]'), [A.stat('g', 'piggy', A.t('c_available'), A.money(t.available_minor), A.t('c_available_d')), A.stat('o', 'clock', A.t('c_pending'), A.money(t.pending_minor), A.t('c_pending_d')),
        A.stat('b', 'check', A.t('c_paid'), A.money(t.paid_minor), A.t('c_paid_d')), A.stat('p', 'doc', A.t('c_count'), all.length, A.t('c_count_d'))]);
      accruals(true);
      var st = d.statements || [];
      if (!st.length) return O.empty(sbox, A.t('no_statements'));
      O.clear(sbox).appendChild(A.table(['th_statement', 'th_period', 'th_earned', 'th_paid', 'th_balance', 'th_status'], st.map(function (s) {
        return h('tr', null, h('td', null, h('b', null, s.statement_number)), h('td', null, O.date(s.period_start) + ' – ' + O.date(s.period_end)), h('td', { class: 'amt' }, A.money(s.earned_minor)),
          h('td', { class: 'amt' }, A.money(s.paid_minor)), h('td', { class: 'amt' }, A.money(s.closing_balance_minor)), h('td', null, O.chip(s.status, A.label(s.status))));
      })));
    }).catch(function (e) { A.fail(box, e); O.clear(sbox); });
  }

  var sum = function (s) { return all.filter(function (a) { return a.status === s; }).reduce(function (x, a) { return x + amt(a); }, 0); };
  O.list('/mobile/agent/commissions').then(function (r) {
    all = r.items;
    A.stats(O.$('[data-stats]'), [A.stat('g', 'piggy', A.t('c_available'), A.money(sum('AVAILABLE')), A.t('c_available_d')), A.stat('o', 'clock', A.t('c_pending'), A.money(sum('PENDING')), A.t('c_pending_d')),
      A.stat('b', 'check', A.t('c_paid'), A.money(sum('PAID')), A.t('c_paid_d')), A.stat('p', 'doc', A.t('c_count'), all.length, A.t('c_count_d'))]);
    accruals(false);
  }).catch(function (e) { A.fail(box, e); });
  loadWd();
  if (O.can('agent.withdrawals.request')) { O.$('[data-wd-card]').hidden = false; O.$('[data-wd-form]').addEventListener('submit', withdraw); }

  function accruals(broker) {
    var statuses = A.group(all, function (a) { return a.status; });
    O.clear(O.$('[data-bars]')).appendChild(A.bars(A.group(all, function (a) { return A.label(a.status); }, amt).map(function (g) { return [g[0], g[1], A.money(g[1])]; }), '#16A34A'));
    A.tabs(O.$('[data-tabs]'), [['all', A.t('tab_all'), all.length]].concat(statuses.map(function (s) { return [s[0], A.label(s[0]), s[1]]; })), tab, function (t) { tab = t; draw(); });
    draw();
    function draw() {
      if (!all.length) return O.empty(box, A.t('no_commissions'));
      var rows = all.filter(function (a) { return tab === 'all' || a.status === tab; });
      var heads = broker ? ['th_policy', 'th_client', 'th_amount', 'th_paid', 'th_status', 'th_available'] : ['th_source', 'th_amount', 'th_status', 'th_available'];
      O.clear(box).appendChild(A.table(heads, rows.map(function (a) {
        return broker
          ? h('tr', null, h('td', null, h('b', null, a.policy_number || '—')), h('td', null, a.customer_name || '—'), h('td', { class: 'amt' }, A.money(a.amount_minor)), h('td', { class: 'amt' }, A.money(a.paid_minor)), h('td', null, O.chip(a.status, A.label(a.status))), h('td', null, O.date(a.available_at)))
          : h('tr', null, h('td', null, h('b', null, a.reason ? O.label(a.reason) : A.t('commission')), h('span', { class: 'muted' }, A.t('policy_ref', { id: String(a.policy_id || '').slice(0, 8) }))), h('td', { class: 'amt' }, A.money(a.amount_minor)), h('td', null, O.chip(a.status, A.label(a.status))), h('td', null, O.date(a.available_at)));
      })));
    }
  }
  function loadWd() {
    return O.list('/mobile/agent/withdrawals').then(function (r) {
      if (!r.items.length) return O.empty(sbox, A.t('no_withdrawals'));
      O.clear(sbox).appendChild(A.table(['th_requested', 'th_provider', 'th_dest', 'th_amount', 'th_status'], r.items.map(function (w) {
        return h('tr', null, h('td', null, O.date(w.requested_at, true)), h('td', null, (A.t('providers') || {})[w.provider] || O.label(w.provider)), h('td', null, w.destination_phone || '—'), h('td', { class: 'amt' }, A.money(w.amount_minor)), h('td', null, O.chip(w.status, A.label(w.status))));
      })));
    }).catch(function (e) { A.fail(sbox, e); });
  }

  // Withdrawal: step-up SMS code first (COMMISSION_WITHDRAWAL), then the request with the one-time grant.
  var challenge = null;
  function withdraw(ev) {
    ev.preventDefault();
    var f = ev.target, btn = O.$('[data-wd-btn]'), codeRow = O.$('[data-code-row]'), P = 'COMMISSION_WITHDRAWAL';
    var body = { provider: f.elements.provider.value, amount_minor: Math.round(Number(f.elements.amount.value) * 100), destination_phone: f.elements.destination_phone.value.trim() };
    O.alert(''); O.busy(btn, true);
    if (!challenge) {
      return O.api('/mobile/security/step-up/request', { body: { purpose: P } }).then(function (c) {
        challenge = c.challenge_id; codeRow.hidden = false; f.elements.code.required = true; O.busy(btn, false); btn.textContent = A.t('confirm_wd'); f.elements.code.focus(); O.alert(A.t('code_sent'), 'ok');
      }).catch(function (e) { O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
    }
    var verified = false;
    O.api('/mobile/security/step-up/verify', { body: { challenge_id: challenge, purpose: P, code: f.elements.code.value.trim() } }).then(function (g) {
      verified = true;
      return A.postWithHeaders('/mobile/agent/withdrawals', body, { 'X-Step-Up-Grant': g.grant_token });
    }).then(function () {
      reset(); O.alert(A.t('wd_ok'), 'ok'); O.loading(sbox); loadWd();
    }).catch(function (e) { if (verified || !e || e.status !== 422) reset(); else O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
    function reset() { O.busy(btn, false); challenge = null; codeRow.hidden = true; f.elements.code.required = false; f.elements.code.value = ''; btn.textContent = A.t('send_code'); }
  }
});
</script>
@endpush
