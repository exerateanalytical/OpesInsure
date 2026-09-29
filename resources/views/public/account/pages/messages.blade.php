{{-- /account/messages[?kind=notif|support|complaints] — SHR-017 Communication Centre: one inbox of everything exchanged with the
     customer, from the app's endpoints: GET /mobile/notifications, GET /mobile/support/cases, GET /mobile/complaints. Each row opens its
     own screen (notifications, support thread, complaint details). --}}
@extends('public.account.layout', ['title' => __('launch_customer.messages.title'), 'lede' => __('launch_customer.messages.lede'), 'crumbs' => [[__('launch_customer.messages.title'), null]], 'active' => 'notifications'])
@section('content')
@include('public.account.partials.launch-assets')
<section class="acard" data-page-body data-messages></section>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var h = Opes.h, M = LC.T.msg, box = Opes.$('[data-page-body]'), kind = ctx.params.get('kind') || 'all';
  Opes.loading(box);
  return Promise.all([LC.soft(Opes.api('/mobile/notifications')), LC.support(), LC.complaints()]).then(function (r) {
    var rows = [];
    (r[0] || []).forEach(function (n) { rows.push({ k: 'notif', icon: 'bell', title: n.title, body: n.body, at: n.created_at, unread: !n.read, href: '/account/notifications' }); });
    (r[1] || []).forEach(function (t) { var last = (t.messages || []).slice(-1)[0]; rows.push({ k: 'support', icon: 'headset', title: (t.reference ? t.reference + ' · ' : '') + t.subject, body: last ? last.body : t.description, at: t.updated_at || t.created_at, status: t.status, href: '/account/support' }); });
    (r[2] || []).forEach(function (c) { rows.push({ k: 'complaints', icon: 'doc', title: c.complaint_number, body: c.resolution_summary || c.description, at: c.communicated_at || c.acknowledged_at || c.received_at, status: c.status, href: '/account/complaints/' + c.id }); });
    rows.sort(function (a, b) { return new Date(b.at || 0) - new Date(a.at || 0); });

    var tabs = h('div', { class: 'op-tabs', role: 'tablist', 'data-kinds': '' }), list = h('ul', { class: 'op-notes' });
    function paint() {
      Opes.$$('[data-kind]', tabs).forEach(function (b) { b.setAttribute('aria-selected', b.dataset.kind === kind ? 'true' : 'false'); });
      Opes.clear(list);
      var shown = rows.filter(function (x) { return kind === 'all' || x.k === kind; });
      if (!shown.length) { list.appendChild(h('li', null, M.none)); return; }
      shown.slice(0, 100).forEach(function (x) {
        var kk = x.k === 'complaints' ? 'complaint' : x.k;
        list.appendChild(h('li', { class: x.unread ? 'unread' : '' }, h('div', { style: 'display:flex;justify-content:space-between;gap:8px;align-items:center' },
          h('b', null, Opes.icon(x.icon), ' ', x.title || M.k[kk]), h('span', { style: 'display:flex;gap:6px;align-items:center' }, x.unread ? h('span', { class: 'st st-warn' }, M.unread) : null, x.status ? Opes.chip(x.status) : null, OP.btn(M.open, x.href))),
          x.body ? h('span', null, String(x.body).slice(0, 240)) : null, h('small', { class: 'op-muted', style: 'display:block' }, M.k[kk] + ' · ' + Opes.date(x.at, true))));
      });
    }
    ['all', 'notif', 'support', 'complaints'].forEach(function (k) {
      tabs.appendChild(h('button', { type: 'button', role: 'tab', class: 'op-tab', 'data-kind': k, onclick: function () { kind = k; paint(); } }, M[k]));
    });
    Opes.clear(box).append(h('div', { class: 'btnbar', style: 'margin-bottom:12px' }, OP.btn(M.new_support, '/account/support', 'dbtn-outline sm', 'headset'), OP.btn(M.new_complaint, '/account/complaints', 'dbtn-outline sm', 'doc')), tabs, list);
    paint();
  }).catch(function (e) { Opes.fail(box, e); });
});
</script>
@endpush
