<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::section>
            <div class="text-sm font-medium text-gray-500">Open circuits</div>
            <div class="mt-1 text-2xl font-semibold {{ $summary['open_circuits'] > 0 ? 'text-danger-600' : '' }}">{{ $summary['open_circuits'] }}</div>
            @if ($summary['half_open_circuits'] > 0)
                <div class="mt-1 text-xs text-gray-500">{{ $summary['half_open_circuits'] }} half-open (probing)</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm font-medium text-gray-500">Pending outbox events</div>
            <div class="mt-1 text-2xl font-semibold">{{ $summary['pending_outbox_count'] }}</div>
            <div class="mt-1 text-xs text-gray-500">
                @if ($summary['oldest_pending_outbox_seconds'] !== null)
                    Oldest pending: {{ $summary['oldest_pending_outbox_seconds'] }}s ago
                @else
                    Nothing pending
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm font-medium text-gray-500">Dead-letter queue</div>
            <div class="mt-1 text-2xl font-semibold {{ $summary['dead_letter_queue_count'] > 0 ? 'text-danger-600' : '' }}">{{ $summary['dead_letter_queue_count'] }}</div>
            <div class="mt-1 text-xs text-gray-500">Attempts needing manual replay or investigation</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm font-medium text-gray-500">Delivery latency (24h)</div>
            @if ($summary['latency_ms_24h']['sample_size'] > 0)
                <div class="mt-1 text-2xl font-semibold">{{ $summary['latency_ms_24h']['p50'] }}ms <span class="text-sm text-gray-500">p50</span></div>
                <div class="mt-1 text-xs text-gray-500">p95: {{ $summary['latency_ms_24h']['p95'] }}ms · {{ $summary['latency_ms_24h']['sample_size'] }} samples</div>
            @else
                <div class="mt-1 text-2xl font-semibold text-gray-400">—</div>
                <div class="mt-1 text-xs text-gray-500">No deliveries in the last 24h</div>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section class="mt-4">
        <x-slot name="heading">Deliveries, last 24 hours</x-slot>
        <div class="grid grid-cols-3 gap-4 text-center">
            <div>
                <div class="text-xl font-semibold text-success-600">{{ $summary['deliveries_24h']['delivered'] }}</div>
                <div class="text-xs text-gray-500">Delivered</div>
            </div>
            <div>
                <div class="text-xl font-semibold text-warning-600">{{ $summary['deliveries_24h']['retry_scheduled'] }}</div>
                <div class="text-xs text-gray-500">Retry scheduled</div>
            </div>
            <div>
                <div class="text-xl font-semibold text-danger-600">{{ $summary['deliveries_24h']['dead_lettered'] }}</div>
                <div class="text-xs text-gray-500">Dead-lettered</div>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section class="mt-4">
        <x-slot name="heading">Connections by status</x-slot>
        <div class="flex flex-wrap gap-2">
            @forelse ($summary['clients_by_status'] as $status => $count)
                <x-filament::badge :color="match($status) { 'ACTIVE' => 'success', 'REVOKED' => 'danger', 'SUSPENDED', 'RESTRICTED' => 'warning', default => 'gray' }">
                    {{ $status }}: {{ $count }}
                </x-filament::badge>
            @empty
                <span class="text-sm text-gray-500">No connections registered yet.</span>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
