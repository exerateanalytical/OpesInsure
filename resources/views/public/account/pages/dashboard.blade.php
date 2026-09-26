{{-- /account — replaced by the policies area with the full dashboard. --}}
@extends('public.account.layout', ['title' => __('account.side.dashboard'), 'crumbs' => [[__('account.side.dashboard'), null]], 'active' => 'dashboard'])
@section('content')
<div class="acard" data-page-body></div>
@endsection
@push('scripts')
<script>
Opes.page(function (ctx) {
  var box = Opes.$('[data-page-body]');
  Opes.loading(box);
  return Opes.list('/mobile/wallet/policies').then(function (r) {
    if (!r.items.length) return Opes.empty(box);
    Opes.clear(box).appendChild(Opes.h('pre', { style: 'white-space:pre-wrap;font-size:12px' }, JSON.stringify(r.items[0], null, 2)));
  }).catch(function (e) { Opes.fail(box, e); });
});
</script>
@endpush
