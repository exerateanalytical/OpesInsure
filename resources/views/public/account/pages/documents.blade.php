{{-- /account/documents — Policy Documents (design 12_stage). GET /mobile/documents (+ POST /{id}/access for a
     signed link), payment receipts from /mobile/payments, upload via POST /mobile/documents (base64 JSON). --}}
@extends('public.account.layout', ['title' => __('account_policies.docs.title'), 'lede' => __('account_policies.docs.lede'), 'crumbs' => [[__('account_policies.docs.title'), null]], 'active' => 'documents'])
@section('content')
@include('public.account.partials.policies-assets')
<div class="stats" data-stats></div>
<div class="agrid main-side">
  <section class="acard" data-page-body></section>
  <div style="display:grid;gap:16px">
    <section class="acard" data-qa></section>
    <section class="acard" data-cats></section>
  </div>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, T = OP.T, K = T.docs, $ = Opes.$, box = $('[data-page-body]'), stats = $('[data-stats]'), qa = $('[data-qa]'), cats = $('[data-cats]');
  var GROUPS = ['policy', 'payment', 'claim', 'cert', 'other'];
  var G_ICON = { policy: 'doc', payment: 'card', claim: 'shield', cert: 'check', other: 'doc' };
  function group(cat) { cat = String(cat || '').toUpperCase(); if (/CLAIM/.test(cat)) return 'claim'; if (/RECEIPT|PAYMENT|INVOICE/.test(cat)) return 'payment'; if (/CERT|STICKER|ATTEST|PROOF_OF_COVER/.test(cat)) return 'cert'; if (/POLICY|SCHEDULE|ENDORSE|RENEW/.test(cat)) return 'policy'; return 'other'; }
  var now = Date.now(), DAY = 864e5;
  function state(d) {
    var s = String(d.status || 'VALID').toUpperCase();
    if (/EXPIRED|SUPERSEDED|VOID|REVOKED|REPLACED|CANCELLED/.test(s)) return 'EXPIRED';
    var u = d.valid_until ? Date.parse(d.valid_until) : null;
    if (u && u < now) return 'EXPIRED';
    if (u && u - now < 30 * DAY) return 'EXPIRING';
    return s === 'VALID' ? 'VALID' : s;
  }
  var all = [], tab = 'all', q = '';
  var fileIn = h('input', { type: 'file', accept: 'application/pdf,image/jpeg,image/png', hidden: true, onchange: function () { if (this.files[0]) pick(this.files[0]); } });
  var catSel = h('select', { 'aria-label': K.u_cat }, ['ID_DOCUMENT', 'DRIVING_LICENCE', 'VEHICLE_REGISTRATION', 'PROOF_OF_ADDRESS', 'OTHER'].map(function (c) { return h('option', { value: c }, K.cat[c]); }));
  var chosen = null, fname = h('small', { class: 'op-muted' });
  var drop = h('div', { class: 'drop', role: 'button', tabindex: '0', onclick: function () { fileIn.click(); }, onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileIn.click(); } },
    ondragover: function (e) { e.preventDefault(); drop.classList.add('over'); }, ondragleave: function () { drop.classList.remove('over'); }, ondrop: function (e) { e.preventDefault(); drop.classList.remove('over'); if (e.dataTransfer.files[0]) pick(e.dataTransfer.files[0]); } },
    Opes.icon('download'), h('span', null, K.u_drop), fname);
  var send = h('button', { type: 'button', class: 'dbtn dbtn-primary wide', disabled: true, onclick: upload }, Opes.icon('send'), K.u_send);
  var form = h('div', { hidden: true, style: 'display:grid;gap:10px;margin-top:10px' }, h('label', { class: 'afield-s' }, h('span', null, K.u_cat), catSel), drop, fileIn, send);
  function pick(f) {
    if (['application/pdf', 'image/jpeg', 'image/png'].indexOf(f.type) < 0) { Opes.alert(K.u_type); return; }
    if (f.size > 10 * 1024 * 1024) { Opes.alert(K.u_big); return; }
    Opes.alert(''); chosen = f; fname.textContent = f.name; send.disabled = false;
  }
  function upload() {
    if (!chosen) return;
    Opes.busy(send, true);
    Opes.fileBase64(chosen).then(function (b64) { return Opes.api('/mobile/documents', { body: { category: catSel.value, mime_type: chosen.type, file_base64: b64 } }); })
      .then(function () { Opes.alert(K.u_ok, 'ok'); chosen = null; fname.textContent = ''; form.hidden = true; load(); })
      .catch(function (e) { Opes.alert(e.message); })
      .finally(function () { Opes.busy(send, false); send.disabled = !chosen; });
  }
  Opes.clear(qa).append(h('h2', null, K.qa_t), h('div', { class: 'op-qa' },
    h('button', { type: 'button', class: 'dbtn dbtn-primary', onclick: function () { form.hidden = !form.hidden; } }, Opes.icon('download'), K.upload), form,
    OP.btn(T.show.qa_help, '/account/support', 'dbtn-outline', 'headset')),
    h('div', { class: 'op-fmt', style: 'margin-top:12px' }, Opes.icon('doc'), h('div', null, h('b', null, K.formats_t), K.formats, h('small', { style: 'display:block;margin-top:4px' }, K.secure))));

  var search = h('input', { type: 'search', placeholder: K.search, 'aria-label': K.search, oninput: function () { q = this.value.toLowerCase(); draw(); } });
  var tabs = h('div', { class: 'tabs-u', role: 'tablist' }), out = h('div'), foot = h('p', { class: 'op-muted', style: 'margin:10px 0 0' });

  function load() {
    Opes.loading(out);
    return Promise.all([Opes.list('/mobile/documents', { per_page: 100 }), OP.policies().catch(function () { return []; }), OP.payments().catch(function () { return []; })]).then(function (r) {
      var pol = {}, byProp = {};
      r[1].forEach(function (p) { pol[p.id] = p; byProp[p.proposal_id] = p; });
      all = r[0].items.map(function (d) {
        var p = d.policy_id && pol[d.policy_id];
        return { id: d.id, kind: 'doc', name: d.title || K.cat[d.category] || Opes.label(d.category), cat: d.category, group: group(d.category), related: p ? p.policy_number : (d.subject_label || null),
          relatedSub: p ? (OP.risk(p).name || OP.title(p)) : null, issued: d.issued_at || d.created_at, until: d.valid_until, state: state(d), mime: d.mime_type };
      }).concat(r[2].filter(OP.ok).map(function (x) {
        var p = byProp[x.proposal_id];
        return { id: x.id, kind: 'receipt', name: K.cat.RECEIPT, cat: 'RECEIPT', group: 'payment', related: p ? p.policy_number : x.provider_reference, relatedSub: OP.mm(x.amount_minor), issued: x.updated_at || x.created_at, until: null, state: 'VALID' };
      }));
      all.sort(function (a, b) { return String(b.issued).localeCompare(String(a.issued)); });
      var cnt = { all: all.length }; GROUPS.forEach(function (g) { cnt[g] = all.filter(function (d) { return d.group === g; }).length; });
      Opes.clear(stats).append(
        OP.stat('doc', 'blue', K.s_total, all.length, K.s_total_d),
        OP.stat('check', 'green', K.s_active, all.filter(function (d) { return d.state === 'VALID'; }).length, K.s_active_d),
        OP.stat('clock', 'orange', K.s_exp, all.filter(function (d) { return d.state === 'EXPIRING'; }).length, K.s_exp_d),
        OP.stat('x', 'red', K.s_old, all.filter(function (d) { return d.state === 'EXPIRED'; }).length, K.s_old_d),
        OP.stat('card', 'navy', K.s_rcpt, cnt.payment ? all.filter(function (d) { return d.kind === 'receipt'; }).length : 0, K.s_rcpt_d));
      Opes.clear(tabs);
      ['all'].concat(GROUPS).forEach(function (g) {
        tabs.appendChild(h('button', { type: 'button', role: 'tab', 'data-g': g, 'aria-selected': g === tab ? 'true' : 'false', onclick: function () { tab = g; Opes.$$('[data-g]', tabs).forEach(function (b) { b.setAttribute('aria-selected', b.dataset.g === g ? 'true' : 'false'); }); draw(); } }, K.tabs[g]));
      });
      Opes.clear(cats).append(h('h2', null, K.cats_t), h('ul', { class: 'op-cats' }, GROUPS.map(function (g) { return h('li', null, Opes.icon(G_ICON[g]), K.tabs[g], h('em', null, cnt[g])); })));
      draw();
    }).catch(function (e) { Opes.fail(out, e); });
  }
  function open(d) { return d.kind === 'receipt' ? OP.openReceipt(d.id) : OP.openDocument(d.id); }
  function draw() {
    var rows = all.filter(function (d) { return (tab === 'all' || d.group === tab) && (!q || [d.name, d.related, d.relatedSub, K.g[d.group]].join(' ').toLowerCase().indexOf(q) >= 0); });
    Opes.clear(out);
    foot.textContent = all.length ? OP.fmt(K.showing, { n: rows.length, t: all.length }) : '';
    if (!all.length) return Opes.empty(out, T.no_docs);
    if (!rows.length) return Opes.empty(out, T.no_match);
    out.appendChild(OP.table([
      [K.name, function (d) { return h('span', { class: 'op-dname' }, Opes.icon('doc'), d.name); }],
      [K.type, function (d) { return K.g[d.group]; }],
      [K.related, function (d) { return d.related ? h('div', null, d.related, d.relatedSub ? h('small', { class: 'op-muted', style: 'display:block' }, d.relatedSub) : null) : '—'; }],
      [K.issued, function (d) { return Opes.date(d.issued); }],
      [K.expiry, function (d) { return d.until ? Opes.date(d.until) : '—'; }],
      [K.status, function (d) { return OP.chip(d.state); }],
      [K.actions, function (d) { return h('div', { class: 'op-acts' }, h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { open(d); } }, T.view), h('button', { type: 'button', class: 'op-iconbtn', 'aria-label': T.download + ': ' + d.name, title: T.download, onclick: function () { open(d); } }, Opes.icon('download'))); }]
    ], rows, 'op-stack'));
  }
  Opes.clear(box).append(tabs, h('div', { class: 'op-filters' }, h('label', { class: 'op-search' }, Opes.icon('search'), search)), out, foot);
  return load();
});
</script>
@endpush
