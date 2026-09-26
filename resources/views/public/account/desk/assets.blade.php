{{-- Claims Desk shared assets: desk.css + desk.js + copy. Included by every pages/claims-desk* view. --}}
@push('head')
<link rel="stylesheet" href="/landing/portal/desk.css?v={{ @filemtime(public_path('landing/portal/desk.css')) }}">
@endpush
@push('scripts')
<script>window.DESK_T = {!! json_encode(__('account_desk.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};</script>
<script src="/landing/portal/desk.js?v={{ @filemtime(public_path('landing/portal/desk.js')) }}"></script>
@endpush
