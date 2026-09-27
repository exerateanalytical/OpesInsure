{{-- Customer self-service pages: the policies-area assets plus the account_customer.js strings as window.OPES_CUST. --}}
@include('public.account.partials.policies-assets')
@push('scripts')
<script>window.OPES_CUST = {!! json_encode(__('account_customer.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};</script>
@endpush
