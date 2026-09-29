<x-filament-panels::page>
    @php($L = 'activa_integration')

    @if (empty($connections))
        <x-filament::section>
            <div class="text-sm font-medium">{{ __($L.'.status.CONFIG_REQUIRED') }}</div>
            <div class="mt-1 text-sm text-gray-500">{{ __($L.'.not_configured') }}</div>
        </x-filament::section>
    @endif

    @foreach ($connections as $c)
        <x-filament::section>
            <x-slot name="heading">{{ __($L.'.connection', ['environment' => $c['environment']]) }}</x-slot>
            <x-slot name="description">{{ __($L.'.status.'.$c['status']) }}</x-slot>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 text-sm">
                <div>
                    <div class="text-gray-500">{{ __($L.'.fields.subscription_key') }}</div>
                    <div class="font-medium">{{ $c['has_subscription_key'] ? __($L.'.set') : __($L.'.missing') }}</div>
                </div>
                <div>
                    <div class="text-gray-500">{{ __($L.'.last_reference_sync') }}</div>
                    <div class="font-medium">{{ $c['last_reference_sync_at']?->diffForHumans() ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500">{{ __($L.'.last_reconciled') }}</div>
                    <div class="font-medium">{{ $c['last_reconciled_at']?->diffForHumans() ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500">{{ __($L.'.queue') }}</div>
                    <div class="font-medium {{ $c['open_failures'] > 0 ? 'text-danger-600' : '' }}">{{ __($L.'.queue_counts', ['failures' => $c['open_failures'], 'synced' => $c['synced'], 'calls' => $c['calls_24h']]) }}</div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($services as $s)
                    @php($svc = $c['services'][$s])
                    @php($state = $svc['health']['state'] ?? ($svc['configured'] ? 'PENDING_VERIFICATION' : 'CONFIG_REQUIRED'))
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="text-sm font-semibold">{{ __($L.'.services.'.$s) }}</div>
                        <div class="mt-1 text-sm {{ $state === 'OK' ? 'text-success-600' : (in_array($state, ['CONFIG_REQUIRED', 'PENDING_VERIFICATION']) ? 'text-gray-500' : 'text-danger-600') }}">
                            {{ __($L.'.health.'.$state) }}@if (! empty($svc['health']['http_status'])) (HTTP {{ $svc['health']['http_status'] }})@endif
                        </div>
                        @if ($svc['circuit']['open'])
                            <div class="mt-1 text-xs text-danger-600">{{ __($L.'.circuit_open') }}</div>
                        @endif
                        <div class="mt-1 text-xs text-gray-500 break-all">{{ $svc['base_url'] }}</div>
                        @if (! empty($svc['health']['last_ok_at']))
                            <div class="mt-1 text-xs text-gray-500">{{ __($L.'.last_ok') }}: {{ \Illuminate\Support\Carbon::parse($svc['health']['last_ok_at'])->diffForHumans() }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach

    {{ $this->table }}
</x-filament-panels::page>
