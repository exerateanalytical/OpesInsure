{{-- /account/vehicles — My Vehicles (design 13_stage). GET/POST /mobile/assets (type VEHICLE); insurance status
     comes from matching the vehicle's registration against the customer's policies (GET /mobile/wallet). --}}
@extends('public.account.layout', ['title' => __('account_policies.veh.title'), 'lede' => __('account_policies.veh.lede'), 'crumbs' => [[__('account_policies.veh.title'), null]], 'active' => 'vehicles'])
@section('content')
@include('public.account.partials.policies-assets')
<div class="stats" data-stats></div>
<div class="agrid main-side op-ms300">
  <div style="display:grid;gap:16px;min-width:0">
    <section class="acard" data-form hidden></section>
    <section class="acard" data-page-body></section>
  </div>
  <div style="display:grid;gap:16px">
    <section class="acard op-vdetail" data-detail></section>
    <section class="acard" data-useful></section>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, T = OP.T, V = T.veh, $ = Opes.$, box = $('[data-page-body]'), stats = $('[data-stats]'), detail = $('[data-detail]'), formBox = $('[data-form]');
  var rows = [], tab = 'all', q = '', sel = null;
  function norm(s) { return String(s || '').toUpperCase().replace(/[^A-Z0-9]/g, ''); }
  function showForm() { formBox.hidden = false; formBox.scrollIntoView({ behavior: 'smooth', block: 'start' }); var i = formBox.querySelector('input'); if (i) i.focus(); }

  Opes.clear($('[data-useful]')).append(h('h2', null, V.useful), h('nav', { class: 'op-links' },
    h('a', { href: '/account/buy?line=motor' }, Opes.icon('compare'), V.u_quote, Opes.icon('chev')),
    h('a', { href: '#add', onclick: function (e) { e.preventDefault(); showForm(); } }, Opes.icon('motor'), V.u_add, Opes.icon('chev')),
    h('a', { href: '/account/documents' }, Opes.icon('doc'), V.u_docs, Opes.icon('chev'))));

  // ---- add-vehicle form (POST /mobile/assets)
  function field(name, label, attrs) { return h('label', { class: 'afield-s' }, h('span', null, label, attrs && attrs.required ? h('i', null, ' *') : null), h('input', Object.assign({ name: name }, attrs || {}))); }
  var usage = h('select', { name: 'usage_type' }, Object.keys(V.usages).map(function (k) { return h('option', { value: k }, V.usages[k]); }));
  var save = h('button', { type: 'submit', class: 'dbtn dbtn-primary' }, Opes.icon('check'), T.save);
  var form = h('form', { class: 'op-form', novalidate: true, onsubmit: function (e) {
      e.preventDefault();
      var d = {}; new FormData(form).forEach(function (v, k) { d[k] = String(v).trim(); });
      if (!d.make || !d.model || !d.registration_number) { Opes.alert(Opes.t.error); return; }
      var facts = { make: d.make, model: d.model, registration_number: d.registration_number.toUpperCase(), usage_type: d.usage_type };
      if (d.year) facts.year = parseInt(d.year, 10);
      if (d.fiscal_power) facts.fiscal_power = parseInt(d.fiscal_power, 10);
      Opes.busy(save, true);
      Opes.api('/mobile/assets', { body: { type: 'VEHICLE', display_name: d.make + ' ' + d.model + ' · ' + facts.registration_number, external_reference: facts.registration_number, facts: facts } })
        .then(function (a) { Opes.alert(V.saved, 'ok'); form.reset(); formBox.hidden = true; sel = a && a.id; return load(); })
        .catch(function (err) { Opes.alert(err.message); }).finally(function () { Opes.busy(save, false); });
    } },
    field('make', V.make, { required: true, maxlength: 60, autocomplete: 'off' }), field('model', V.model, { required: true, maxlength: 60, autocomplete: 'off' }),
    field('registration_number', V.plate, { required: true, maxlength: 20, autocomplete: 'off', placeholder: 'LT 452 CD' }), field('year', V.year, { type: 'number', min: 1950, max: new Date().getFullYear() + 1 }),
    h('label', { class: 'afield-s' }, h('span', null, V.usage), usage), field('fiscal_power', V.power, { type: 'number', min: 1, max: 60 }),
    h('div', { class: 'btnbar full' }, h('button', { type: 'button', class: 'dbtn dbtn-outline', onclick: function () { formBox.hidden = true; } }, T.cancel), save));
  Opes.clear(formBox).append(h('h2', { id: 'add' }, V.form_t), h('p', { class: 'sub' }, V.form_d), form);

  var search = h('input', { type: 'search', placeholder: V.search, 'aria-label': V.search, oninput: function () { q = this.value.toLowerCase(); draw(); } });
  var tabs = h('div', { class: 'tabs-u op-tabs', role: 'tablist' }), out = h('div');
  Opes.clear(box).append(h('div', { class: 'op-tabbar' }, tabs, OP.btn(V.quote, '/account/buy?line=motor', 'dbtn-outline sm', 'doc')), h('div', { class: 'op-filters' }, h('label', { class: 'op-search' }, Opes.icon('search'), search)), out);

  function load() {
    Opes.loading(out);
    return Promise.all([Opes.list('/mobile/assets', { per_page: 100 }), OP.policies().catch(function () { return []; })]).then(function (r) {
      var motor = r[1].filter(function (p) { return OP.risk(p).reg; });
      rows = r[0].items.filter(function (a) { return String(a.type).toUpperCase() === 'VEHICLE'; }).map(function (a) {
        var f = a.facts || {}, reg = f.registration_number || a.external_reference || '';
        var pols = motor.filter(function (p) { return norm(OP.risk(p).reg) === norm(reg) && norm(reg); });
        var pol = pols.filter(function (p) { var s = OP.state(p); return s === 'ACTIVE' || s === 'EXPIRING'; }).sort(function (x, y) { return String(y.coverage_ends_at).localeCompare(String(x.coverage_ends_at)); })[0] || null;
        var st = pol ? (OP.state(pol) === 'EXPIRING' ? 'EXPIRING' : 'INSURED') : 'NOT_INSURED';
        return { a: a, f: f, reg: reg, name: [f.make, f.model].filter(Boolean).join(' ') || a.display_name, pol: pol, st: st };
      });
      var ins = rows.filter(function (x) { return x.st !== 'NOT_INSURED'; }).length, exp = rows.filter(function (x) { return x.st === 'EXPIRING'; }).length, not = rows.length - ins;
      Opes.clear(stats).append(OP.stat('motor', 'blue', V.s_total, rows.length, V.s_total_d),
        OP.stat('shield', 'green', V.s_ins, ins, rows.length ? OP.fmt(V.s_ins_d, { p: Math.round(ins * 1000 / rows.length) / 10 }) : ''),
        OP.stat('clock', 'orange', V.s_exp, exp, V.s_exp_d), OP.stat('x', 'red', V.s_not, not, V.s_not_d),
        h('div', { class: 'op-add' }, h('button', { type: 'button', class: 'dbtn dbtn-primary', onclick: showForm }, '+ ', V.add)));
      Opes.clear(tabs);
      [['all', T.all, rows.length], ['INSURED', T.status.INSURED, ins], ['EXPIRING', T.expiring, exp], ['NOT_INSURED', T.status.NOT_INSURED, not]].forEach(function (t) {
        tabs.appendChild(h('button', { type: 'button', role: 'tab', 'data-t': t[0], 'aria-selected': t[0] === tab ? 'true' : 'false', onclick: function () { tab = t[0]; Opes.$$('[data-t]', tabs).forEach(function (b) { b.setAttribute('aria-selected', b.dataset.t === tab ? 'true' : 'false'); }); draw(); } }, t[1], t[0] !== 'all' ? h('span', { class: 'cnt' + (t[0] !== 'INSURED' && t[2] ? ' bad' : '') }, t[2]) : null));
      });
      if (!sel && rows[0]) sel = rows[0].a.id;
      draw();
    }).catch(function (e) { Opes.fail(out, e); Opes.clear(detail); });
  }
  function drawDetail() {
    var x = rows.filter(function (r) { return r.a.id === sel; })[0];
    Opes.clear(detail);
    if (!x) return Opes.empty(detail, rows.length ? V.pick : V.none);
    var f = x.f, p = x.pol;
    detail.append(h('div', { class: 'op-vhead' }, h('span', { class: 'op-li' }, Opes.icon('motor')), h('div', null, h('h3', null, [x.name, f.year].filter(Boolean).join(' ')), OP.chip(x.st))),
      h('dl', { class: 'kv' },
        h('dt', null, V.plate), h('dd', null, x.reg || '—'),
        h('dt', null, V.year), h('dd', null, f.year || '—'),
        h('dt', null, V.usage), h('dd', null, f.usage_type ? (V.usages[f.usage_type] || Opes.label(f.usage_type)) : '—'),
        h('dt', null, V.power), h('dd', null, f.fiscal_power || '—'),
        h('dt', null, V.pnum), h('dd', null, p ? p.policy_number : '—'),
        h('dt', null, V.expiry), h('dd', null, p ? Opes.date(p.coverage_ends_at) : '—'),
        h('dt', null, V.coverage), h('dd', null, p ? OP.title(p) : '—')),
      p ? h('div', { class: 'btnbar' }, OP.btn(V.view_policy, '/account/policies/' + p.id, 'dbtn-outline'), OP.btn(V.claim, '/account/claims/new?policy=' + p.id, 'dbtn-navy'))
        : h('div', { class: 'btnbar' }, OP.btn(V.get_quote, '/account/buy?line=motor', 'dbtn-primary', 'compare')));
  }
  function draw() {
    var list = rows.filter(function (x) { return (tab === 'all' || x.st === tab) && (!q || [x.name, x.reg, x.f.year, x.pol && x.pol.policy_number].join(' ').toLowerCase().indexOf(q) >= 0); });
    Opes.clear(out);
    drawDetail();
    if (!rows.length) return Opes.empty(out, V.none, h('button', { type: 'button', class: 'dbtn dbtn-primary sm', onclick: showForm }, V.add));
    if (!list.length) return Opes.empty(out, T.no_match);
    var t = OP.table([
      [V.vehicle, function (x) { return h('div', { class: 'op-cell' }, h('span', { class: 'op-li' }, Opes.icon('motor')), h('div', null, h('b', null, [x.name, x.f.year].filter(Boolean).join(' ')), h('small', null, x.f.usage_type ? (V.usages[x.f.usage_type] || Opes.label(x.f.usage_type)) : ''))); }],
      [V.plate, function (x) { return x.reg; }],
      [V.year, function (x) { return x.f.year; }],
      [V.pstatus, function (x) { return OP.chip(x.st); }],
      [V.pnum, function (x) { return x.pol ? x.pol.policy_number : '—'; }],
      [V.expiry, function (x) { return x.pol ? h('div', null, Opes.date(x.pol.coverage_ends_at), h('small', { class: 'op-muted' + (x.st === 'EXPIRING' ? ' op-late' : ''), style: 'display:block' }, OP.daysNote(x.pol))) : '—'; }],
      [V.actions, function (x) { return x.pol ? h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { sel = x.a.id; draw(); if (innerWidth < 1200) detail.scrollIntoView({ behavior: 'smooth' }); } }, T.view) : OP.btn(V.get_quote, '/account/buy?line=motor', 'dbtn-outline sm'); }]
    ], list, 'op-stack');
    Opes.$$('tbody tr', t).forEach(function (tr, i) { if (list[i].a.id === sel) tr.className = 'sel'; tr.style.cursor = 'pointer'; tr.addEventListener('click', function (e) { if (e.target.closest('a,button')) return; sel = list[i].a.id; draw(); }); });
    out.appendChild(t);
  }
  return load();
});
</script>
@endpush
