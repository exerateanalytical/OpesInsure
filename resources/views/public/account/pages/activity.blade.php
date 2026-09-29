{{-- /account/activity — SHR-008 Audit Timeline (customer side): GET /mobile/account/activity (the caller's own audit_log rows,
     tenant-scoped) and GET /me/security/login-activity (own sign-ins). Staff see the tenant trail in the admin AuditTrail screen. --}}
@extends('public.account.layout', ['title' => __('launch_customer.activity.title'), 'lede' => __('launch_customer.activity.lede'), 'crumbs' => [[__('account_policies.prof.title'), '/account/profile'], [__('launch_customer.activity.title'), null]], 'active' => 'privacy'])
@section('content')
@include('public.account.partials.launch-assets')
<div class="agrid main-side op-ms380">
  <section class="acard" data-page-body data-activity></section>
  <section class="acard" data-logins></section>
</div>
@endsection
@push('scripts')
<script>
Opes.page(function () {
  var h = Opes.h, A = LC.T.activity, box = Opes.$('[data-page-body]'), lb = Opes.$('[data-logins]'), list = h('ol', { class: 'op-timeline op-nlist' }), last = null;
  function more(before) {
    return Opes.api('/mobile/account/activity', { query: before ? { before: before, limit: 50 } : { limit: 50 } }).then(function (rows) {
      rows = rows || [];
      rows.forEach(function (r) {
        last = r.sequence;
        list.appendChild(h('li', null, h('span', { class: 'op-li' }, Opes.icon('clock')),
          h('div', null, h('b', null, Opes.label(String(r.action || '').replace(/\./g, '_'))), h('small', { class: 'op-muted', style: 'display:block' }, [Opes.label(r.subject_type), (A.src || {})[r.source] || r.source].filter(Boolean).join(' · '))),
          h('small', null, Opes.date(r.occurred_at, true))));
      });
      return rows.length;
    });
  }
  Opes.loading(box);
  more(null).then(function (n) {
    Opes.clear(box).appendChild(h('h2', null, A.actions_t));
    if (!n) { var e = h('div'); box.appendChild(e); Opes.empty(e, A.none); return; }
    box.appendChild(list);
    if (n >= 50) { var b = h('button', { type: 'button', class: 'dbtn dbtn-outline sm', onclick: function () { Opes.busy(b, true); more(last).then(function (k) { Opes.busy(b, false); if (k < 50) b.remove(); }).catch(function (e) { Opes.busy(b, false); Opes.alert(e.message); }); } }, A.more); box.appendChild(b); }
  }).catch(function (e) { Opes.fail(box, e); });

  Opes.loading(lb);
  Opes.api('/me/security/login-activity').then(function (rows) {
    rows = rows || [];
    Opes.clear(lb).appendChild(h('h2', null, A.logins_t));
    if (!rows.length) { var e = h('div'); lb.appendChild(e); Opes.empty(e, A.no_logins); return; }
    lb.appendChild(h('ul', { class: 'op-nlist' }, rows.slice(0, 20).map(function (a) {
      return h('li', null, h('div', null, h('b', null, Opes.date(a.occurred_at, true)), h('small', { class: 'op-muted', style: 'display:block' }, [a.method, a.device_name || a.platform, a.masked_ip, a.country_code].filter(Boolean).join(' · '))),
        a.outcome || a.result || a.status ? Opes.chip(a.outcome || a.result || a.status) : null);
    })));
  }).catch(function () { Opes.clear(lb); lb.remove(); });
});
</script>
@endpush
