{{-- RecordShell::paymentState() slot. --}}
@if ($payment)
    @include('filament.shared.payment-state', ['payment' => $payment])
@endif
