{{-- /account/agent/needs — AGT-019 Needs Assessment: short fact-find with the client, recommended lines with the reason for each,
     then straight to the product list (/account/agent/products?line=) or a quote (/account/buy?line=&customer=). The answers can be kept in
     the lead diary (POST /mobile/partner/agent/leads/{lead}/activities, agent's own leads only). --}}
@php $K = __('launch_agent_a'); @endphp
@extends('public.account.layout', ['title' => $K['needs_t'], 'lede' => $K['needs_lede'], 'crumbs' => [[$K['dash_t'], '/account/agent'], [$K['needs_t'], null]], 'active' => 'dashboard'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <div class="desk-main">
    <section class="acard" data-page-body>
      <form class="ag-form" data-needs-form>
        <label class="afield-s"><span>{{ $K['js']['client'] }}</span><select name="customer" data-customer><option value="">{{ $K['js']['choose_client'] }}</option></select></label>
        <label class="afield-s"><span>{{ $K['js']['n_lead'] }}</span><select name="lead" data-lead><option value="">—</option></select></label>
        @foreach ($K['js']['n_q'] as $code => $q)
          <fieldset class="afield-s" data-q="{{ $code }}"><legend>{{ $q }}</legend>
            <label><input type="radio" name="{{ $code }}" value="1"> {{ $K['js']['n_yes'] }}</label>
            <label><input type="radio" name="{{ $code }}" value="0" checked> {{ $K['js']['n_no'] }}</label>
          </fieldset>
        @endforeach
        <label class="afield-s"><span>{{ $K['js']['n_budget'] }}</span><input type="number" name="budget" min="0" step="1000"></label>
        <button class="dbtn dbtn-primary sm" type="submit">{{ $K['js']['n_run'] }}</button>
      </form>
    </section>
    <aside class="desk-side"><section class="acard" data-result><h2>{{ $K['js']['n_result'] }}</h2><p class="sub">{{ $K['js']['n_none'] }}</p></section></aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, L = AgentA, O = Opes, h = O.h, $ = O.$;
  if (!A.guard(ctx)) return;
  var form = $('[data-needs-form]'), res = $('[data-result]'), last = null;
  var MAP = { vehicle: ['MOTOR'], travel: ['TRAVEL'], home: ['HOME'], health: ['HEALTH'], dependants: ['LIFE'], business: ['BUSINESS'], accident: ['ACCIDENT'] };
  A.clients().then(function (rows) { var sel = $('[data-customer]'); rows.forEach(function (c) { sel.appendChild(h('option', { value: c.id, selected: ctx.params.get('customer') === c.id }, [c.full_name, c.phone_e164].filter(Boolean).join(' · '))); }); }).catch(function () {});
  O.list('/mobile/partner/agent/leads').then(function (r) { var sel = $('[data-lead]'); r.items.filter(function (l) { return l.status !== 'LOST'; }).forEach(function (l) { sel.appendChild(h('option', { value: l.id }, l.full_name)); }); }).catch(function () { $('[data-lead]').closest('label').hidden = true; });
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var lines = [];
    Object.keys(MAP).forEach(function (k) { if (form.elements[k].value === '1') MAP[k].forEach(function (l) { if (lines.indexOf(l) < 0) lines.push(l); }); });
    var customer = form.elements.customer.value, why = L.T.n_why || {};
    last = { lines: lines, budget: form.elements.budget.value };
    O.clear(res).appendChild(h('h2', null, L.t('n_result')));
    if (!lines.length) { res.appendChild(h('p', { class: 'sub' }, L.t('n_none'))); return; }
    res.appendChild(h('ul', { class: 'op-notes', 'data-recommendations': '' }, lines.map(function (l) {
      return h('li', null, h('b', null, A.line(l)), h('span', null, why[l] || ''), h('div', { class: 'btns', style: 'display:flex;gap:6px;margin-top:6px' },
        L.link(L.t('n_details'), '/account/agent/products?line=' + l.toLowerCase() + (customer ? '&customer=' + encodeURIComponent(customer) : '')),
        A.canQuote() ? L.link(L.t('n_quote'), '/account/buy?line=' + l.toLowerCase() + (customer ? '&customer=' + encodeURIComponent(customer) : ''), 'dbtn-primary') : null));
    })));
    var lead = form.elements.lead.value;
    if (lead && O.can('agent.clients.manage')) {
      var b = h('button', { type: 'button', class: 'dbtn dbtn-outline sm' }, L.t('n_save'));
      b.addEventListener('click', function () {
        O.busy(b, true);
        var body = L.t('q_needs') + ': ' + lines.map(A.line).join(', ') + (last.budget ? ' · ' + A.money(Number(last.budget) * 100) : '');
        O.api('/mobile/partner/agent/leads/' + encodeURIComponent(lead) + '/activities', { body: { entry_type: 'NOTE', body: body } }).then(function () { O.busy(b, false); O.alert(L.t('n_saved'), 'ok'); })
          .catch(function (e) { O.busy(b, false); O.alert(A.errMsg(e), 'bad'); });
      });
      res.appendChild(b);
    }
  });
});
</script>
@endpush
