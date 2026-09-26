{{-- /account/payments/new[?policy=<id>|?proposal=<id>] — Make a Payment (design 11_stage).
     The API collects money only for proposals in PAYMENT_PENDING (POST /payments + /payments/{id}/initiate, then
     GET /payments/{id} until it settles). Issued policies have no outstanding balance to collect here. --}}
@extends('public.account.layout', ['title' => __('account_policies.pay.title'), 'lede' => __('account_policies.pay.lede'), 'crumbs' => [[__('account_policies.pays.title'), '/account/payments'], [__('account_policies.pay.title'), null]], 'active' => 'payments'])
@section('content')
@include('public.account.partials.policies-assets')
<div data-page-body style="display:grid;gap:16px">
  <div class="acard"><div class="acct-loading" role="status"><span class="spin"></span>{{ __('account.js.loading') }}</div></div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, T = OP.T, P = T.pay, $ = Opes.$, box = $('[data-page-body]');
  var policyId = ctx.params.get('policy'), proposalId = ctx.params.get('proposal');
  return Promise.all([OP.policies(), OP.proposals(), OP.payments().catch(function () { return null; })]).then(function (r) {
    var pols = r[0], props = r[1], pays = r[2] || [];
    var policy = policyId ? pols.filter(function (p) { return p.id === policyId; })[0] : null;
    function paidFor(pid) { return pays.filter(function (x) { return x.proposal_id === pid && OP.ok(x); }).reduce(function (s, x) { return s + (x.amount_minor || 0); }, 0); }
    // Payable = PAYMENT_PENDING with no completed payment covering it yet (a paid proposal is only awaiting issuance).
    var due = props.filter(function (x) { return String(x.status).toUpperCase() === 'PAYMENT_PENDING' && paidFor(x.id) < (x.total_minor || 0); });
    var target = null;
    if (proposalId) target = due.filter(function (x) { return x.id === proposalId; })[0] || null;
    else if (policy) target = due.filter(function (x) { return x.id === policy.proposal_id; })[0] || null;
    else if (due.length === 1) target = due[0];
    var prop = policy ? props.filter(function (x) { return x.id === policy.proposal_id; })[0] : target;

    Opes.clear(box);
    // ---- summary bar
    if (policy || target) {
      var paid = pays.filter(function (x) { return x.proposal_id === (policy ? policy.proposal_id : target.id) && OP.ok(x); }).reduce(function (s, x) { return s + (x.amount_minor || 0); }, 0);
      var totalMinor = policy ? OP.total(policy) : target.total_minor;
      var outstanding = target ? target.total_minor : 0;
      var risk = policy ? OP.risk(policy) : { name: null };
      box.appendChild(h('section', { class: 'acard op-sumbar' },
        h('div', { class: 'op-cell' }, h('span', { class: 'op-li', style: 'width:56px;height:56px' }, Opes.icon(OP.lineIcon(policy ? OP.lineOf(policy) : target.line_code))),
          h('div', null, h('b', null, policy ? OP.title(policy) : target.product_name), h('small', null, [risk.name, risk.reg].filter(Boolean).join(' | ') || (policy || target).carrier_name), policy ? h('small', null, T.show.number + ': ', h('b', { style: 'display:inline' }, policy.policy_number)) : null),
          policy ? OP.chip(OP.state(policy)) : OP.chip('PAYMENT_PENDING')),
        h('div', null, h('small', null, P.period), h('b', null, policy ? Opes.date(policy.coverage_starts_at) + ' – ' + Opes.date(policy.coverage_ends_at) : '—')),
        h('div', null, h('small', null, P.premium), h('b', null, OP.mm(totalMinor))),
        h('div', null, h('small', null, P.paid), h('b', null, OP.mm(paid))),
        h('div', null, h('small', null, P.outstanding), h('b', null, OP.mm(outstanding)))));
    }

    if (!target) {
      var c = OP.card(due.length ? P.due_t : T.show.qa_pay);
      c.appendChild(h('p', { class: 'acct-alert info', style: 'margin:0 0 12px' }, policy ? P.nothing : (due.length ? P.choose : P.nothing_all)));
      if (policy) c.appendChild(h('p', { class: 'op-muted' }, P.renew_note));
      if (due.length) c.appendChild(h('div', { class: 'op-due' }, due.map(function (x) {
        return h('div', null, h('div', { class: 'op-cell' }, h('span', { class: 'op-li' }, Opes.icon(OP.lineIcon(x.line_code))), h('div', null, h('b', null, x.product_name), h('small', null, x.carrier_name))),
          h('b', null, OP.mm(x.total_minor)), OP.btn(P.pay_now, '/account/payments/new?proposal=' + x.id, 'dbtn-primary sm', 'card'));
      })));
      box.appendChild(h('div', { class: 'agrid main-side' }, c, recentCard()));
      return;
    }

    // ---- payment flow for a PAYMENT_PENDING proposal
    var state = { step: 0, provider: null, phone: String((ctx.session.user && ctx.session.user.phone_e164) || '').replace(/^\+237/, '') };
    var steps = h('div', { class: 'op-steps' });
    var main = h('div', { style: 'display:grid;gap:16px;min-width:0' });
    var sumCard = OP.card(P.sum_t);
    var side = h('div', { style: 'display:grid;gap:16px;min-width:0' }, sumCard,
      h('section', { class: 'acard op-info' }, h('h2', null, P.info_t), h('ul', null, P.info.map(function (t) { return h('li', null, t); }))), recentCard());
    box.append(steps, h('div', { class: 'agrid main-side op-ms380' }, main, side));

    function methodLabel(p) { return p === 'mtn_momo' ? P.mtn : p === 'orange_money' ? P.om : '—'; }
    function logo(p) { return p === 'mtn_momo' ? h('span', { class: 'op-logo mtn' }, 'MTN') : p === 'orange_money' ? h('span', { class: 'op-logo om' }, 'orange') : h('span', { class: 'op-logo ' + p }, Opes.icon(p === 'card' ? 'card' : 'bank')); }
    function phoneOk() { return /^6\d{8}$/.test(state.phone.replace(/\s+/g, '')); }
    function drawSum() {
      Opes.clear(sumCard).append(h('h2', null, P.sum_t), h('div', { class: 'op-sum' },
        h('div', null, h('span', null, P.item), h('b', null, target.product_name)),
        h('div', null, h('span', null, P.premium), h('b', null, OP.mm(target.total_minor))),
        state.provider ? h('div', null, h('span', null, P.method), h('b', null, methodLabel(state.provider))) : null,
        state.step >= 2 ? h('div', null, h('span', null, P.payer), h('b', null, '+237 ' + state.phone)) : null,
        h('div', { class: 'big' }, h('span', null, P.to_pay), h('b', null, OP.mm(target.total_minor)))));
    }
    function draw() {
      Opes.clear(steps).appendChild(Opes.stepper(P.steps, state.step));
      drawSum();
      Opes.clear(main);
      if (state.step <= 1) {
        var opts = h('div', { class: 'op-methods' }, [['mtn_momo', P.mtn, P.mtn_d], ['orange_money', P.om, P.om_d]].map(function (m) {
          return h('label', { class: 'op-method' }, h('input', { type: 'radio', name: 'provider', value: m[0], checked: state.provider === m[0], onchange: function () { state.provider = m[0]; state.step = 1; draw(); } }), logo(m[0]), h('span', null, h('b', null, m[1]), h('small', null, m[2])));
        }).concat([['card', P.card], ['bank', P.bank]].map(function (m) {
          return h('label', { class: 'op-method off', title: P.soon }, h('input', { type: 'radio', name: 'provider', disabled: true }), logo(m[0]), h('span', null, h('b', null, m[1]), h('small', null, P.soon)));
        })));
        main.appendChild(h('section', { class: 'acard' }, h('h2', null, P.m_t), h('p', { class: 'sub' }, P.m_d), opts));
        if (state.provider) {
          var input = h('input', { id: 'pay-phone', type: 'tel', inputmode: 'numeric', autocomplete: 'tel-national', value: state.phone, maxlength: 12, placeholder: '6XX XX XX XX', oninput: function () { state.phone = this.value.replace(/[^\d]/g, ''); } });
          var err = h('small', { class: 'op-late', hidden: true }, P.phone_bad);
          main.appendChild(h('section', { class: 'acard' }, h('h2', null, P.d_t), h('p', { class: 'sub' }, P.d_d),
            h('div', { class: 'op-note' }, logo(state.provider), h('div', null, h('b', null, methodLabel(state.provider)), P.prompt)),
            h('label', { class: 'afield-s', style: 'margin-top:14px' }, h('span', null, P.phone, h('i', null, ' *')), h('div', { class: 'op-phone' }, h('span', { class: 'cc' }, '+237'), input), h('small', { class: 'op-muted' }, P.phone_h), err),
            h('div', { class: 'btnbar', style: 'justify-content:space-between' }, OP.btn(T.back, policyId ? '/account/policies/' + policyId : '/account/payments', 'dbtn-outline', 'chev-left'),
              h('button', { type: 'button', class: 'dbtn dbtn-primary', onclick: function () { if (!phoneOk()) { err.hidden = false; input.focus(); return; } state.phone = state.phone.replace(/\s+/g, ''); state.step = 2; draw(); } }, P.continue, Opes.icon('arrow')))));
        }
      } else if (state.step === 2) {
        var go = h('button', { type: 'button', class: 'dbtn dbtn-primary', onclick: function () { submit(go); } }, Opes.icon('lock'), P.confirm);
        main.appendChild(h('section', { class: 'acard' }, h('h2', null, P.c_t), h('p', { class: 'sub' }, P.c_d),
          h('dl', { class: 'kv', style: 'max-width:520px' }, h('dt', null, P.item), h('dd', null, target.product_name + ' — ' + (target.carrier_name || '')), h('dt', null, P.method), h('dd', null, methodLabel(state.provider)),
            h('dt', null, P.payer), h('dd', null, '+237 ' + state.phone), h('dt', null, P.to_pay), h('dd', null, OP.mm(target.total_minor))),
          h('div', { class: 'btnbar', style: 'justify-content:space-between' }, h('button', { type: 'button', class: 'dbtn dbtn-outline', onclick: function () { state.step = 1; draw(); } }, Opes.icon('chev-left'), T.back), go)));
      } else {
        main.appendChild(state.result);
      }
    }
    function submit(btn) {
      Opes.busy(btn, true); Opes.alert('');
      Opes.api('/payments', { body: { proposal_id: target.id, provider: state.provider, payer_phone_e164: '+237' + state.phone, idempotency_key: 'web-' + Opes.uuid() } })
        .then(function (pay) { return Opes.api('/payments/' + pay.id + '/initiate', { body: {} }).then(function () { return pay; }); })
        .then(function (pay) {
          state.step = 3;
          var msg = h('div', { class: 'acct-loading', role: 'status' }, h('span', { class: 'spin' }), P.waiting);
          state.result = h('section', { class: 'acard' }, h('h2', null, P.r_t), msg); draw();
          var n = 0;
          (function poll() {
            Opes.api('/payments/' + pay.id).then(function (x) {
              var s = String(x.status || '').toUpperCase();
              if (s === 'SUCCEEDED') {
                Opes.clear(msg).className = 'acct-alert ok';
                msg.append(P.ok + ' ');
                state.result.appendChild(h('div', { class: 'btnbar' }, h('button', { type: 'button', class: 'dbtn dbtn-primary', onclick: function () { OP.openReceipt(pay.id); } }, Opes.icon('download'), T.receipt), OP.btn(T.view_all, '/account/payments', 'dbtn-outline')));
                Opes.clear(steps).appendChild(Opes.stepper(P.steps, 4));
              } else if (/FAIL|CANCEL|EXPIRE|REJECT|DECLIN/.test(s)) {
                Opes.clear(msg).className = 'acct-alert bad'; msg.append(P.failed);
                state.result.appendChild(h('div', { class: 'btnbar' }, h('button', { type: 'button', class: 'dbtn dbtn-primary', onclick: function () { state.step = 1; draw(); } }, P.retry)));
              } else if (++n < 40) setTimeout(poll, 3000);
              else { Opes.clear(msg).className = 'acct-alert info'; msg.append(P.timeout); }
            }).catch(function () { if (++n < 40) setTimeout(poll, 4000); });
          })();
        })
        .catch(function (e) { Opes.busy(btn, false); Opes.alert(e.message); });
    }
    draw();

  }).catch(function (e) { Opes.fail(box, e); });

  function recentCard() {
    var c = OP.card(T.show.pay_t, null, h('a', { class: 'rowlink', href: '/account/payments' }, T.view_all));
    var inner = h('div'); c.appendChild(inner); Opes.loading(inner);
    OP.payments().then(function (list) {
      Opes.clear(inner);
      if (!list.length) return Opes.empty(inner, T.no_payments);
      inner.appendChild(OP.table([
        [T.pays.date, function (x) { return Opes.date(x.created_at); }],
        [T.pays.amount, function (x) { return OP.mm(x.amount_minor); }],
        [T.pays.status, function (x) { return OP.chip(x.status); }],
        [T.receipt, function (x) { return OP.ok(x) ? h('button', { type: 'button', class: 'op-iconbtn', 'aria-label': T.receipt, onclick: function () { OP.openReceipt(x.id); } }, Opes.icon('download')) : ''; }]
      ], list.slice(0, 3), 'op-stack op-mini'));
    }).catch(function (e) { Opes.fail(inner, e); });
    return c;
  }
});
</script>
@endpush
