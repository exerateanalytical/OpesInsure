@php($p = $this->viewPayload())
@php($clients = $this->clientOptions())
<x-filament-panels::page>
    @if (count($clients) > 1)
        <div class="flex items-center gap-2 text-sm">
            <span class="text-gray-500">{{ __('developer_portal.ui.client') }}</span>
            <select class="rounded-lg border-gray-300 text-sm" wire:change="switchClient($event.target.value)">
                @foreach ($clients as $id => $name)
                    <option value="{{ $id }}" @selected($id === $this->link()->integration_client_id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div wire:offline class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm" data-state="OFFLINE">{{ __('developer_portal.states.OFFLINE') }}</div>
    <div wire:loading class="text-sm text-gray-500" data-state="LOADING">{{ __('developer_portal.states.LOADING') }}</div>

    @if ($p['cards'] !== [])
        <div class="grid gap-4 md:grid-cols-4">
            @foreach ($p['cards'] as $label => $value)
                <x-filament::section compact>
                    <div class="text-xs text-gray-500">{{ $this->label((string) $label) }}</div>
                    <div class="text-base font-semibold break-all">{{ $this->cell((string) $label, $value) }}</div>
                </x-filament::section>
            @endforeach
        </div>
    @endif

    @if ($this->extraView())
        @include($this->extraView())
    @endif

    @if (in_array($p['state'], ['EMPTY', 'ERROR', 'VALIDATION_FAILED'], true))
        <x-filament::section>
            <p class="text-sm" data-state="{{ $p['state'] }}">{{ $p['message'] }}</p>
        </x-filament::section>
    @elseif ($p['state'] === 'SUCCESS' && $this->stateMessage)
        <div class="rounded-lg border border-success-300 bg-success-50 p-3 text-sm" data-state="SUCCESS">{{ $this->stateMessage }}</div>
    @endif

    @if ($p['rows'] !== [])
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50"><tr>
                    @foreach ($p['columns'] as $c)<th class="px-3 py-2 font-medium">{{ $this->label((string) $c) }}</th>@endforeach
                    <th class="px-3 py-2"></th>
                </tr></thead>
                <tbody>
                    @foreach ($p['rows'] as $row)
                        <tr class="border-t border-gray-100">
                            @foreach ($p['columns'] as $c)
                                <td class="px-3 py-2 align-top">
                                    @if ($c === 'url')
                                        <x-filament::link :href="$row[$c]" size="sm">{{ __('developer_portal.ui.open') }}</x-filament::link>
                                    @else
                                        {{ $this->cell((string) $c, $row[$c] ?? null) }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-3 py-2 whitespace-nowrap">
                                @foreach ($this->rowActions($row) as $a)
                                    <x-filament::link tag="button" size="sm" wire:click="{{ $a['action'] }}('{{ $a['arg'] }}')">{{ $a['label'] }}</x-filament::link>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
