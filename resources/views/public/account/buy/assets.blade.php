{{-- Shared assets for the quote & purchase pages (pages/buy, pages/quotes*): buy.css, strings, buy.js. --}}
@push('head')<link rel="stylesheet" href="/landing/portal/buy.css?v={{ @filemtime(public_path('landing/portal/buy.css')) }}">@endpush
@push('scripts')
<script>window.BUY_T = {!! json_encode(__('account_buy.js'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};</script>
<script src="/landing/portal/buy.js?v={{ @filemtime(public_path('landing/portal/buy.js')) }}"></script>
@endpush
