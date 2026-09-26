{{-- Claims area assets (outside pages/ so it is not routable): included by claims.blade.php, claims/new and claims/show. --}}
@push('head')
<link rel="stylesheet" href="/landing/portal/claims.css?v={{ @filemtime(public_path('landing/portal/claims.css')) }}">
@endpush
@push('scripts')
<script>window.OPES_CLAIMS = {!! json_encode(__('account_claims.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};</script>
<script src="/landing/portal/claims.js?v={{ @filemtime(public_path('landing/portal/claims.js')) }}"></script>
@endpush
