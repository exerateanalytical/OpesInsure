<x-filament-panels::page>
    @php($scanner = $this->scannerStatus())
    @php($counts = $this->statusCounts())
    <p class="text-sm text-gray-500">{{ __('scan_queue.subtitle') }}</p>

    <x-filament::section :heading="__('scan_queue.scanner.heading')">
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" data-scanner-status>
            <div>
                <dt class="text-sm font-medium text-gray-500">{{ __('scan_queue.scanner.configured') }}</dt>
                <dd class="mt-1 text-lg font-semibold {{ $scanner['configured'] ? 'text-success-600' : 'text-danger-600' }}">{{ $scanner['configured'] ? __('scan_queue.scanner.yes') : __('scan_queue.scanner.no') }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">{{ __('scan_queue.scanner.reachable') }}</dt>
                <dd class="mt-1 text-lg font-semibold {{ $scanner['reachable'] ? 'text-success-600' : 'text-danger-600' }}">{{ $scanner['reachable'] ? __('scan_queue.scanner.yes') : __('scan_queue.scanner.no') }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">{{ __('scan_queue.scanner.version') }}</dt>
                <dd class="mt-1 text-sm">{{ $scanner['version'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">{{ __('scan_queue.scanner.endpoint') }}</dt>
                <dd class="mt-1 text-sm">{{ $scanner['endpoint'] ?? '—' }}</dd>
            </div>
        </dl>
        @if (! $scanner['configured'])
            <p class="mt-3 text-sm text-warning-600">{{ __('scan_queue.scanner.not_configured_help') }}</p>
        @elseif (! $scanner['reachable'] && $scanner['error'])
            <p class="mt-3 text-sm text-danger-600">{{ __('scan_queue.scanner.error') }}: {{ $scanner['error'] }}</p>
        @endif
    </x-filament::section>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6" data-scan-counts>
        @foreach ($counts as $status => $n)
            <x-filament::section>
                <div class="text-xs font-medium text-gray-500">{{ __('scan_queue.status.'.$status) }}</div>
                <div class="mt-1 text-2xl font-semibold {{ $status === 'INFECTED' && $n > 0 ? 'text-danger-600' : '' }}">{{ $n }}</div>
            </x-filament::section>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
