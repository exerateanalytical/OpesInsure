{{-- Launch 2026-10-02 customer/shared screens: customer-area assets plus launch_customer.js strings (window.OPES_LC) and launch.js helpers. --}}
@include('public.account.partials.customer-assets')
@push('scripts')
<script>window.OPES_LC = {!! json_encode(__('launch_customer.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};</script>
<script src="/landing/portal/launch.js?v={{ @filemtime(public_path('landing/portal/launch.js')) }}"></script>
@endpush
