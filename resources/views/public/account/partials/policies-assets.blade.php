{{-- Assets for the policies-area pages: stylesheet, JS strings (account_policies.js) and policies.js helpers. --}}
@push('head')
<link rel="stylesheet" href="/landing/portal/policies.css?v={{ @filemtime(public_path('landing/portal/policies.css')) }}">
@endpush
@push('scripts')
<script>window.OPES_POL = {!! json_encode(__('account_policies.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};</script>
<script src="/landing/portal/policies.js?v={{ @filemtime(public_path('landing/portal/policies.js')) }}"></script>
@endpush
