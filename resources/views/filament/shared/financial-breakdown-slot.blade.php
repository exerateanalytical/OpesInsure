{{-- RecordShell::financialBreakdown() slot: renders nothing when no persisted breakdown exists. --}}
@if ($breakdown)
    @include('filament.shared.financial-breakdown', ['lines' => $breakdown['lines'], 'total' => $breakdown['total'] ?? null, 'currency' => $breakdown['currency'] ?? 'XAF', 'id' => 'record'])
@endif
