@php($r = $this->result())
@php($in = 'mt-1 block w-full rounded-lg border-gray-300 text-sm')
<x-filament-panels::page>
    <x-filament::section>
        <div class="grid gap-3 md:grid-cols-6 items-end" data-form="reports-filter">
            <label class="text-sm md:col-span-2">{{ __('dashboards.reports.report') }}
                <select wire:model.live="report" class="{{ $in }}">
                    @foreach ($this->options() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm">{{ __('dashboards.reports.from') }}<input type="date" wire:model.live="from" class="{{ $in }}" /></label>
            <label class="text-sm">{{ __('dashboards.reports.to') }}<input type="date" wire:model.live="to" class="{{ $in }}" /></label>
            <label class="text-sm">{{ __('dashboards.reports.currency') }}<input type="text" maxlength="3" wire:model.live.debounce.500ms="currency" placeholder="XAF" class="{{ $in }}" /></label>
            <label class="text-sm">{{ __('dashboards.reports.as_of') }}<input type="date" wire:model.live="asOf" class="{{ $in }}" /></label>
        </div>
        <div class="mt-3 flex items-center gap-3">
            <x-filament::button wire:click="exportCsv" icon="heroicon-o-arrow-down-tray" color="gray">{{ __('dashboards.reports.export_csv') }}</x-filament::button>
            <span wire:loading class="text-sm text-gray-500">{{ __('dashboards.states.loading') }}</span>
        </div>
    </x-filament::section>

    <x-filament::section :heading="$r['title']">
        @if ($r['status'] !== 'AVAILABLE')
            <p class="text-sm {{ in_array($r['status'], ['ERROR', 'INVALID'], true) ? 'text-danger-600' : 'text-warning-700' }}" data-state="{{ $r['status'] }}">{{ $r['reason'] ?? __('dashboards.reports.not_available') }}</p>
        @elseif ($r['rows'] === [])
            <p class="text-sm text-gray-500" data-state="EMPTY">{{ __('dashboards.states.empty') }}</p>
        @else
            <p class="mb-2 text-xs text-gray-500">{{ trans_choice('dashboards.reports.rows', count($r['rows']), ['count' => count($r['rows'])]) }}@if ($r['truncated']) — {{ __('dashboards.reports.truncated') }}@endif</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50"><tr>@foreach ($r['columns'] as $c)<th class="px-3 py-2 font-medium">{{ str_replace('_', ' ', $c) }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach (array_slice($r['rows'], 0, 500) as $row)
                            <tr class="border-t border-gray-100">@foreach ($r['columns'] as $c)@php($v = $row[$c] ?? null)<td class="px-3 py-2">{{ is_scalar($v) || $v === null ? $v : json_encode($v) }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
