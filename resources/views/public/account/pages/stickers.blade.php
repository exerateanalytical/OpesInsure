{{-- /account/stickers — AGT-049 Sticker Assignment + AGT-050 Sticker Handover (agent).
     GET /mobile/partner/agent/stickers (the agent's in-stock stickers and handovers to / from them);
     POST /mobile/partner/agent/policies/{id}/sticker (book policy; StickerCustodyService: only the agent's own stock);
     POST /sticker-handovers/{id}/accept|reject (the receiving agent acknowledges; never the initiator). --}}
@php $K = __('launch_agent_b'); @endphp
@extends('public.account.layout', ['title' => $K['stickers_t'], 'lede' => $K['stickers_lede'], 'crumbs' => [[$K['stickers_t'], null]], 'active' => 'stickers'])
@section('content')
@include('public.account.agent.servicing-assets')
<div data-agent class="agrid">
  <div class="desk-stats" data-stats></div>
  <section class="acard" data-page-body><h2>{{ $K['js']['sec_assign'] }}</h2><div data-assign></div></section>
  <section class="acard"><h2>{{ $K['js']['sec_handovers'] }}</h2><div data-handovers></div></section>
  <section class="acard"><h2>{{ $K['js']['sec_stock'] }}</h2><div data-stock></div></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, S = AgentS, O = Opes, h = O.h;
  if (!S.guard(ctx)) return;
  var abox = O.$('[data-assign]'), hbox = O.$('[data-handovers]'), sbox = O.$('[data-stock]'), data = null, policies = [];
  [abox, hbox, sbox].forEach(O.loading);

  function drawStock() {
    var stock = data.stock;
    if (!stock.length) return O.empty(sbox, S.t('no_stock'));
    O.clear(sbox).appendChild(S.table(['th_serial', 'th_insurer', 'th_batch', 'th_status'], stock.map(function (s) { return S.row([h('b', null, s.serial_number), (s.carrier_short_name || s.carrier_name) || '—', s.batch_number || '—', S.chip(s.status)]); })));
  }
  function decide(hv, accept, btn) {
    var reason = accept ? null : window.prompt(S.t('reject_reason'));
    if (!accept && !reason) return;
    O.busy(btn, true); O.alert('');
    O.api('/sticker-handovers/' + S.enc(hv.id) + (accept ? '/accept' : '/reject'), { body: accept ? {} : { reason: reason } }).then(function () {
      O.alert(S.t(accept ? 'handover_accepted' : 'handover_rejected'), 'ok'); load();
    }).catch(function (e) { O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
  }
  function drawHandovers() {
    var rows = data.handovers;
    if (!rows.length) return O.empty(hbox, S.t('no_handovers'));
    O.clear(hbox).appendChild(S.table(['th_direction', 'th_from_to', 'th_quantity', 'th_status', 'th_created', 'th_actions'], rows.map(function (hv) {
      var acts = hv.can_decide && O.can('stickers.handover') ? h('span', { class: 'acts' },
        h('button', { type: 'button', class: 'dbtn dbtn-primary sm', onclick: function () { decide(hv, true, this); } }, O.icon('check'), S.t('accept')),
        h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { decide(hv, false, this); } }, O.icon('x'), S.t('reject'))) : (hv.decision_reason || '—');
      return S.row([S.t(hv.incoming ? 'incoming' : 'outgoing'), S.label(hv.from_level) + ' → ' + S.label(hv.to_level), String(hv.quantity), S.chip(hv.status), O.date(hv.created_at), acts]);
    })));
  }
  function drawAssign() {
    O.clear(abox);
    if (!O.can('stickers.assign')) return abox.appendChild(h('p', { class: 'sub' }, S.t('assign_no_perm')));
    var eligible = policies.filter(function (p) { return ['ACTIVE', 'EXPIRING'].indexOf(p.status) >= 0 && ['AUTO', 'AUTOMOBILE', 'MOTOR'].indexOf(String(p.line_code || '').toUpperCase()) >= 0; /* StickerCustodyService::MOTOR_LINES */ });
    if (!eligible.length) return O.empty(abox, S.t('no_motor_active'));
    if (!data.stock.length) return O.empty(abox, S.t('no_stock_assign'));
    var pol = h('select', { name: 'policy_id', required: true }, eligible.map(function (p) { return h('option', { value: p.id }, [p.policy_number, p.customer_name, (p.carrier_short_name || p.carrier_name)].filter(Boolean).join(' · ')); }));
    var want = ctx.params.get('policy'); if (want) pol.value = want;
    var serial = h('select', { name: 'serial_number', required: true });
    function serials() {
      var p = eligible.filter(function (x) { return x.id === pol.value; })[0], carrier = p && p.carrier_id;
      O.clear(serial); data.stock.filter(function (s) { return !carrier || s.carrier_id === carrier; }).forEach(function (s) { serial.appendChild(h('option', { value: s.serial_number }, s.serial_number + ((s.carrier_short_name || s.carrier_name) ? ' · ' + (s.carrier_short_name || s.carrier_name) : ''))); });
    }
    pol.addEventListener('change', serials); serials();
    var btn = h('button', { type: 'submit', class: 'dbtn dbtn-primary sm' }, O.icon('check'), S.t('assign'));
    var f = h('form', { class: 'bform', novalidate: true }, S.field(S.t('f_policy'), pol, true), S.field(S.t('f_serial'), serial, true), h('p', { class: 'sub' }, S.t('assign_note')), h('div', { class: 'bactions' }, btn));
    f.addEventListener('submit', function (e) {
      e.preventDefault(); O.alert('');
      if (!pol.value || !serial.value) return O.alert(S.t('assign_missing'), 'bad');
      O.busy(btn, true);
      O.api('/mobile/partner/agent/policies/' + S.enc(pol.value) + '/sticker', { body: { serial_number: serial.value } }).then(function (s) {
        O.busy(btn, false); O.alert(S.t('assigned', { s: s.serial_number }), 'ok'); load();
      }).catch(function (err) { O.busy(btn, false); O.alert(A.errMsg(err), 'bad'); });
    });
    abox.appendChild(f);
  }
  function load() {
    return Promise.all([S.stickers(), A.policies().catch(function () { return []; })]).then(function (r) {
      data = r[0]; policies = r[1];
      var pending = data.handovers.filter(function (x) { return x.can_decide; }).length;
      A.stats(O.$('[data-stats]'), [A.stat('b', 'check', S.t('stat_stock'), data.stock.length), A.stat('o', 'clock', S.t('stat_pending_handovers'), pending)]);
      drawStock(); drawHandovers(); drawAssign();
    }).catch(function (e) { [abox, hbox, sbox].forEach(function (b) { A.fail(b, e); }); });
  }
  return load();
});
</script>
@endpush
