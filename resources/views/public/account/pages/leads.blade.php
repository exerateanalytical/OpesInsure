{{-- /account/leads — sales pipeline. Agents: GET|POST /mobile/partner/agent/leads, PATCH .../leads/{id}, POST .../leads/{id}/convert.
     Other partner staff with crm.leads.*: GET|POST /crm/leads, POST /crm/leads/{id}/transitions (no convert). --}}
@php $K = __('account_agent'); @endphp
@extends('public.account.layout', ['title' => $K['leads_t'], 'lede' => $K['leads_lede'], 'crumbs' => [[$K['leads_t'], null]], 'active' => 'leads'])
@section('content')
@include('public.account.agent.assets')
<div data-agent class="agrid">
  <div class="ag-pipe" data-pipe></div>
  <div class="desk-main">
    <section class="acard" data-page-body>
      <div class="tabs-u" role="tablist" data-tabs></div>
      <div class="desk-tools" data-tools></div>
      <div data-rows></div>
    </section>
    <aside class="desk-side">
      <section class="acard" data-new-lead>
        <h2>{{ $K['js']['new_lead'] }}</h2>
        <form class="ag-form" data-lead-form>
          <label class="afield-s"><span>{{ $K['js']['f_name'] }} *</span><input name="full_name" required minlength="3" maxlength="160" autocomplete="off"></label>
          <label class="afield-s"><span>{{ $K['js']['f_phone'] }} *</span><input name="phone_e164" required minlength="8" maxlength="32" inputmode="tel" placeholder="+2376…" autocomplete="off"></label>
          <label class="afield-s"><span>{{ $K['js']['f_city'] }}</span><input name="city" maxlength="80"></label>
          <label class="afield-s"><span>{{ $K['js']['f_product'] }}</span><select name="product_interest"><option value="">—</option>@foreach (['MOTOR', 'HEALTH', 'TRAVEL', 'HOME', 'LIFE', 'BUSINESS', 'ACCIDENT'] as $ln)<option value="{{ $ln }}">{{ $K['js']['lines'][$ln] }}</option>@endforeach</select></label>
          <label class="afield-s"><span>{{ $K['js']['f_notes'] }}</span><textarea name="notes" rows="3" maxlength="2000"></textarea></label>
          <button class="dbtn dbtn-primary sm" type="submit">{{ $K['js']['add_lead'] }}</button>
        </form>
      </section>
      <section class="acard"><h2>{{ $K['js']['by_product'] }}</h2><div data-products></div></section>
    </aside>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var A = Agent, O = Opes, h = O.h;
  if (!A.guard(ctx)) return;
  var box = O.$('[data-rows]'), all = [], state = { tab: 'open', q: '' };
  var STAGES = ['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTE', 'NEGOTIATION', 'CONVERTED', 'LOST'];
  var COLORS = { NEW: '#1E63E9', CONTACTED: '#0EA5E9', QUALIFIED: '#7C3AED', QUOTE: '#F59E0B', NEGOTIATION: '#EA580C', CONVERTED: '#16A34A', LOST: '#E5484D' };
  // Agents use the agent lead API (with convert); other partner staff (e.g. broker staff) use the CRM lead API (no convert endpoint for them).
  var AG = O.can('agent.clients.read'), BASE = AG ? '/mobile/partner/agent/leads' : '/crm/leads';
  if (!AG && !O.can('crm.leads.manage')) O.$('[data-new-lead]').hidden = true;
  O.$('[data-tools]').appendChild(A.search(A.t('search_leads'), function (q) { state.q = q; render(); }));
  O.$('[data-lead-form]').addEventListener('submit', create);
  load();

  function load() {
    O.loading(box);
    return O.list(BASE).then(function (r) { all = r.items; paint(); }).catch(function (e) { A.fail(box, e); O.$('[data-new-lead]').hidden = true; });
  }
  function paint() {
    var cnt = function (s) { return all.filter(function (l) { return l.status === s; }).length; };
    O.clear(O.$('[data-pipe]')).append.apply(O.$('[data-pipe]'), STAGES.map(function (s) { return h('div', { style: '--c:' + COLORS[s] }, h('small', null, A.label(s)), h('b', null, String(cnt(s)))); }));
    var open = all.filter(function (l) { return l.status !== 'CONVERTED' && l.status !== 'LOST'; }).length;
    A.tabs(O.$('[data-tabs]'), [['open', A.t('tab_open'), open], ['CONVERTED', A.label('CONVERTED'), cnt('CONVERTED')], ['LOST', A.label('LOST'), cnt('LOST')], ['all', A.t('tab_all'), all.length]], state.tab, function (t) { state.tab = t; render(); });
    O.clear(O.$('[data-products]')).appendChild(A.bars(A.group(all, function (l) { return l.product_interest ? A.line(l.product_interest) : A.t('unspecified'); }).map(function (g) { return [g[0], g[1]]; }), '#7C3AED'));
    render();
  }
  function render() {
    if (!all.length) return O.empty(box, A.t('no_leads'));
    var rows = all.filter(function (l) {
      if (state.tab === 'open' && (l.status === 'CONVERTED' || l.status === 'LOST')) return false;
      if (state.tab !== 'open' && state.tab !== 'all' && l.status !== state.tab) return false;
      if (state.q && [l.full_name, l.phone_e164, l.city, l.notes].join(' ').toLowerCase().indexOf(state.q) < 0) return false;
      return true;
    });
    if (!rows.length) return O.empty(box, A.t('no_match'));
    O.clear(box).appendChild(A.table(['th_lead', 'th_city', 'th_product', 'th_status', 'th_updated', 'th_actions'], rows.map(function (l) {
      var acts = h('td', { class: 'acts' });
      if ((l.next_statuses || []).length && (AG || O.can('crm.leads.manage'))) {
        acts.appendChild(h('select', { 'aria-label': A.t('move_to') + ' ' + l.full_name, onchange: function () { if (this.value) move(this, l, this.value); } },
          h('option', { value: '' }, A.t('move_to') + '…'), l.next_statuses.map(function (s) { return h('option', { value: s }, A.label(s)); })));
      }
      if (AG && l.status !== 'CONVERTED' && l.status !== 'LOST') acts.appendChild(h('button', { type: 'button', class: 'dbtn dbtn-primary sm', onclick: function () { convert(this, l); } }, A.t('convert')));
      if (l.converted_customer_id) acts.appendChild(h('a', { class: 'dbtn dbtn-outline sm', href: '/account/customers/' + encodeURIComponent(l.converted_customer_id) }, A.t('view_client')));
      return h('tr', null,
        h('td', null, h('b', null, l.full_name), h('span', { class: 'muted' }, l.phone_e164 || ''), l.notes ? h('span', { class: 'muted' }, l.notes) : null),
        h('td', null, l.city || '—'), h('td', null, l.product_interest ? A.line(l.product_interest) : '—'),
        h('td', null, h('span', { class: 'st st-' + (l.status === 'CONVERTED' ? 'ok' : l.status === 'LOST' ? 'bad' : 'info') }, A.label(l.status))),
        h('td', null, O.date(l.updated_at, true)), acts);
    })));
  }
  function move(sel, l, to) {
    var body = { status: to };
    if (to === 'LOST') { var why = window.prompt(A.t('lost_reason')); if (why === null) { sel.value = ''; return; } body.lost_reason = why || null; }
    sel.disabled = true; O.alert('');
    (AG ? O.api(BASE + '/' + encodeURIComponent(l.id), { method: 'PATCH', body: body }) : O.api(BASE + '/' + encodeURIComponent(l.id) + '/transitions', { body: body })).then(function (u) { replace(u); O.alert(A.t('lead_moved', { s: A.label(u.status) }), 'ok'); })
      .catch(function (e) { sel.disabled = false; sel.value = ''; O.alert(A.errMsg(e), 'bad'); });
  }
  function convert(btn, l) {
    if (!window.confirm(A.t('convert_confirm', { name: l.full_name }))) return;
    O.busy(btn, true); O.alert('');
    O.api(BASE + '/' + encodeURIComponent(l.id) + '/convert', { body: { consent_confirmed: true } }).then(function (r) {
      replace(r.lead); O.alert(A.t('converted', { name: l.full_name }), 'ok');
    }).catch(function (e) { O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
  }
  function replace(u) { all = all.map(function (x) { return x.id === u.id ? u : x; }); paint(); }
  function create(ev) {
    ev.preventDefault();
    var f = ev.target, btn = f.querySelector('[type=submit]'), body = {};
    ['full_name', 'phone_e164', 'city', 'product_interest', 'notes'].forEach(function (k) { var v = f.elements[k].value.trim(); if (v) body[k] = v; });
    O.busy(btn, true); O.alert('');
    O.api(BASE, { body: body }).then(function (l) { O.busy(btn, false); f.reset(); all.unshift(l); state.tab = 'open'; paint(); O.alert(A.t('lead_added'), 'ok'); })
      .catch(function (e) { O.busy(btn, false); O.alert(A.errMsg(e), 'bad'); });
  }
});
</script>
@endpush
