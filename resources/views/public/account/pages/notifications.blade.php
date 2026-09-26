{{-- /account/notifications — GET /mobile/notifications, POST /mobile/notifications/{id}/read, POST /mobile/notifications/read-all. --}}
@extends('public.account.layout', ['title' => __('account_policies.notif.title'), 'lede' => __('account_policies.notif.lede'), 'crumbs' => [[__('account_policies.notif.title'), null]], 'active' => 'notifications'])
@section('content')
@include('public.account.partials.policies-assets')
<section class="acard" data-page-body></section>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, T = OP.T, N = T.notif, box = Opes.$('[data-page-body]');
  var ICON = { QUOTE: 'compare', POLICY: 'shield', PAYMENT: 'card', CLAIM: 'doc', SECURITY: 'lock', DOCUMENT: 'doc', SUPPORT: 'headset' };
  function target(n) {
    var p = n.path || ''; if (!p || p.charAt(0) !== '/') return null;
    if (p.indexOf('/account') === 0) return p;
    if (/^\/(policies|claims|payments|quotes|documents|vehicles|support|notifications)(\/|$)/.test(p)) return '/account' + p;
    if (p === '/security' || p.indexOf('/profile') === 0) return '/account/profile';
    return null;
  }
  function badge(n) { Opes.$$('[data-unread],[data-unread-count]').forEach(function (e) { e.hidden = !n; if (e.hasAttribute('data-unread-count')) e.textContent = n; }); }
  function markRead(n) { if (n.read) return Promise.resolve(); return Opes.api('/mobile/notifications/' + n.id + '/read', { body: {} }).then(function () { n.read = true; }); }
  function load() {
    Opes.loading(box);
    return Opes.api('/mobile/notifications').then(function (list) {
      list = list || [];
      var unread = list.filter(function (n) { return !n.read; }).length; badge(unread);
      var all = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', disabled: !unread, onclick: function () {
        Opes.busy(all, true);
        Opes.api('/mobile/notifications/read-all', { body: {} }).then(function () { Opes.alert(N.done, 'ok'); return load(); }).catch(function (e) { Opes.busy(all, false); Opes.alert(e.message); });
      } }, Opes.icon('check'), N.mark_all);
      Opes.clear(box).append(h('div', { class: 'acard-h' }, h('h2', null, unread ? OP.fmt(N.unread, { n: unread }) : T.all), all));
      if (!list.length) { var e = h('div'); box.appendChild(e); return Opes.empty(e, N.none); }
      box.appendChild(h('ul', { class: 'op-nlist' }, list.map(function (n) {
        var li, href = target(n);
        li = h('li', { class: n.read ? '' : 'unread' }, h('span', { class: 'op-li' }, Opes.icon(ICON[String(n.type).toUpperCase()] || 'bell')),
          h('div', null, h('b', null, n.title), h('p', null, n.body), h('small', { class: 'op-muted' }, Opes.date(n.created_at, true))),
          h('div', { class: 'op-acts' }, href ? h('a', { class: 'dbtn dbtn-outline sm', href: href, onclick: function (e) { e.preventDefault(); markRead(n).finally(function () { location.href = href; }); } }, N.open) : null,
            !n.read ? h('button', { type: 'button', class: 'op-iconbtn', title: N.read, 'aria-label': N.read, onclick: function (ev) { var b = ev.currentTarget; markRead(n).then(function () { li.className = ''; b.remove(); unread--; badge(unread); }).catch(function (e) { Opes.alert(e.message); }); } }, Opes.icon('check')) : null));
        return li;
      })));
    }).catch(function (e) { Opes.fail(box, e); });
  }
  return load();
});
</script>
@endpush
