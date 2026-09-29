{{-- /account/customers/{id}/activities — AGT-016 Customer Activities: one timeline of the client's quotes, proposals, policies and claims
     (GET /mobile/partner/agent/quotes|proposals|policies|claims, filtered to this client) and the lead diary of the lead converted into
     this client (GET|POST /mobile/partner/agent/leads/{lead}/activities). All scoped to the calling agent's book. --}}
@php $K = __('launch_agent_a'); $C = __('account_agent'); @endphp
@extends('public.account.layout', ['title' => $K['activities_t'], 'lede' => $K['activities_lede'], 'crumbs' => [[$C['customers_t'], '/account/customers'], [$C['client_t'], null], [$K['activities_t'], null]], 'active' => 'customers'])
@section('content')
@include('public.account.agent.launch-a')
<div data-agent class="agrid">
  <div class="desk-main">
    <section class="acard" data-page-body>
      <div class="tabs-u" role="tablist" data-tabs></div>
      <div data-rows></div>
    </section>
    <aside class="desk-side">
      <section class="acard" data-log>
        <h2>{{ $K['js']['act_log'] }}</h2>
        <form class="ag-form" data-log-form>
          <label class="afield-s"><span>{{ $K['js']['act_type'] }}</span><select name="entry_type">@foreach ($K['js']['act_kinds'] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></label>
          <label class="afield-s"><span>{{ $K['js']['act_body'] }} *</span><textarea name="body" rows="3" maxlength="4000" required></textarea></label>
          <label class="afield-s"><span>{{ $C['js']['follow_up_at'] }}</span><input type="datetime-local" name="follow_up_at"></label>
          <button class="dbtn dbtn-primary sm" type="submit">{{ $C['js']['log_activity'] }}</button>
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
  var id = ctx.ids[0], box = $('[data-rows]'), items = [], tab = 'all', lead = null;
  O.$$('.crumbs a, .crumbs span').forEach(function (el) { if (el.textContent === @json($C['client_t'])) el.replaceWith(h('a', { href: '/account/customers/' + encodeURIComponent(id) }, el.textContent)); });
  $('[data-log-form]').addEventListener('submit', log);
  if (!O.can('agent.clients.manage')) $('[data-log]').hidden = true;
  load();

  function load() {
    O.loading(box); items = [];
    return A.client(id).then(function (c) {
      return Promise.all([L.soft(A.quotes()), L.soft(A.proposals()), L.soft(A.policies()), L.soft(A.claims()), L.soft(O.list('/mobile/partner/agent/leads').then(function (r) { return r.items; }))]).then(function (r) {
        var mine = function (rows) { return (rows || []).filter(function (x) { return x.customer_id ? x.customer_id === id : (x.party_id && c.party_id ? x.party_id === c.party_id : x.customer_name === c.full_name); }); };
        mine(r[0]).forEach(function (q) { items.push({ k: 'quote', at: q.created_at, title: A.line(q.line_code) + ' · ' + A.money(q.best_premium_minor), st: q.status, href: '/account/agent/quotes/' + encodeURIComponent(q.id) }); });
        mine(r[1]).forEach(function (p) { items.push({ k: 'proposal', at: p.submitted_at || p.created_at, title: (p.proposal_number || '') + ' · ' + ((p.carrier_short_name || p.carrier_name) || ''), st: p.status, href: '/account/agent/proposals/' + encodeURIComponent(p.id) }); });
        mine(r[2]).forEach(function (p) { items.push({ k: 'policy', at: p.issued_at, title: (p.policy_number || '') + ' · ' + ((p.carrier_short_name || p.carrier_name) || ''), st: p.status, href: p.id && A.mode() === 'agent' ? '/account/book/policies/' + encodeURIComponent(p.id) : '/account/book' }); });
        mine(r[3]).forEach(function (x) { items.push({ k: 'claim', at: x.reported_at || x.created_at, title: x.claim_number || x.reference || '—', st: x.status, href: A.mode() === 'agent' ? '/account/book/claims/' + encodeURIComponent(x.id) : '/account/book' }); });
        lead = (r[4] || []).filter(function (l) { return l.converted_customer_id === id; })[0] || null;
        if (!lead) { $('[data-log-form]').hidden = true; if (!$('[data-no-lead]')) $('[data-log]').appendChild(h('p', { class: 'sub', 'data-no-lead': '' }, L.t('act_no_lead'))); return null; }
        return O.api('/mobile/partner/agent/leads/' + encodeURIComponent(lead.id) + '/activities').then(function (rows) {
          (rows.items || rows || []).forEach(function (e) { items.push({ k: 'note', at: e.created_at || e.occurred_at, title: A.label(e.entry_type) + ' — ' + e.body, st: null, due: e.follow_up_at }); });
        }).catch(function () {});
      }).then(paint);
    }).catch(function (e) { A.fail(box, e); $('[data-log]').hidden = true; });
  }
  function paint() {
    items.sort(function (a, b) { return String(b.at || '').localeCompare(String(a.at || '')); });
    var n = function (k) { return items.filter(function (i) { return i.k === k; }).length; };
    A.tabs($('[data-tabs]'), [['all', L.t('a_all'), items.length], ['quote', L.t('act_quote'), n('quote')], ['proposal', L.t('act_proposal'), n('proposal')], ['policy', L.t('act_policy'), n('policy')], ['claim', L.t('act_claim'), n('claim')], ['note', L.t('act_note'), n('note')]], tab, function (t) { tab = t; render(); });
    render();
  }
  function render() {
    var rows = items.filter(function (i) { return tab === 'all' || i.k === tab; });
    if (!rows.length) return O.empty(box, L.t('none'));
    O.clear(box).appendChild(L.table(['date', 'act_type', 'note', 'status'], rows.map(function (i) {
      return h('tr', null, h('td', null, O.date(i.at, true)), h('td', null, L.t('act_' + i.k)), h('td', null, i.href ? h('a', { href: i.href }, i.title) : i.title, i.due ? h('span', { class: 'muted' }, ' · ' + O.date(i.due, true)) : null), h('td', null, i.st ? O.chip(i.st, A.label(i.st)) : '—'));
    })));
  }
  function log(ev) {
    ev.preventDefault();
    if (!lead) return;
    var f = ev.target, btn = f.querySelector('[type=submit]'), body = { entry_type: f.elements.entry_type.value, body: f.elements.body.value.trim() };
    if (f.elements.follow_up_at.value) body.follow_up_at = new Date(f.elements.follow_up_at.value).toISOString();
    O.busy(btn, true); O.alert('');
    O.api('/mobile/partner/agent/leads/' + encodeURIComponent(lead.id) + '/activities', { body: body }).then(function () { O.busy(btn, false); f.reset(); O.alert(L.t('act_logged'), 'ok'); load(); })
      .catch(function (e) { O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
  }
});
</script>
@endpush
