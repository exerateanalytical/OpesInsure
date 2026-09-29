<x-filament-panels::page>
    @php($L = 'sms_providers')

    <x-filament::section>
        @if ($gatewayStatus === 'CONFIG_REQUIRED')
            <div class="text-sm font-semibold text-danger-600">{{ __($L.'.status_config_required') }}</div>
            <div class="mt-1 text-sm text-gray-500">{{ __($L.'.config_required_help') }}</div>
        @else
            <div class="text-sm font-semibold text-success-600">{{ __($L.'.status_configured') }}</div>
            <div class="mt-1 text-sm text-gray-500">{{ __($L.'.configured_help') }}</div>
        @endif
        <div class="mt-2 text-xs text-gray-500">{{ __($L.'.mtn_note') }}</div>
    </x-filament::section>

    {{ $this->table }}

    <x-filament::section>
        <x-slot name="heading">{{ __($L.'.log_heading') }}</x-slot>
        @if ($log->isEmpty())
            <div class="text-sm text-gray-500">{{ __($L.'.log_empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            @foreach (['created_at', 'destination_masked', 'provider', 'purpose', 'encoding', 'segments', 'status', 'error'] as $c)
                                <th class="px-2 py-1 font-medium">{{ __($L.'.columns.'.$c) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($log as $m)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                <td class="px-2 py-1 whitespace-nowrap">{{ $m->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td class="px-2 py-1 font-mono">{{ $m->destination_masked }}</td>
                                <td class="px-2 py-1">{{ $m->provider ? __($L.'.providers.'.$m->provider) : '—' }}</td>
                                <td class="px-2 py-1">{{ $m->purpose }}</td>
                                <td class="px-2 py-1">{{ $m->encoding }}</td>
                                <td class="px-2 py-1">{{ $m->segments }}</td>
                                <td class="px-2 py-1 {{ $m->status === 'SENT' ? 'text-success-600' : 'text-danger-600' }}">{{ $m->status }}</td>
                                <td class="px-2 py-1 text-gray-500">{{ $m->error ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
