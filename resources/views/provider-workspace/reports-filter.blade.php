@php($in = 'mt-1 block w-full rounded-lg border-gray-300 text-sm')
<div class="grid gap-3 md:grid-cols-6 items-end" data-form="provider-report">
    <label class="text-sm md:col-span-2">{{ __('provider_workspace.screens.reports') }}
        <select wire:model.live="report" class="{{ $in }}">
            @foreach ($this->reportOptions() as $r)<option value="{{ $r }}">{{ str_replace('_', ' ', $r) }}</option>@endforeach
        </select>
    </label>
    <label class="text-sm">{{ __('provider_workspace.ui.date_from') }}<input type="date" wire:model.live="filters.date_from" class="{{ $in }}" /></label>
    <label class="text-sm">{{ __('provider_workspace.ui.date_to') }}<input type="date" wire:model.live="filters.date_to" class="{{ $in }}" /></label>
    <label class="text-sm">{{ __('provider_workspace.ui.facility') }}
        <select wire:model.live="filters.facility_id" class="{{ $in }}">
            <option value="">{{ __('provider_workspace.ui.all') }}</option>
            @foreach ($this->facilityOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </select>
    </label>
    <label class="text-sm">{{ __('provider_workspace.ui.claim_status') }}<input type="text" wire:model.live.debounce.500ms="filters.claim_status" class="{{ $in }}" /></label>
    @if ($this->canExport())
        <div class="md:col-span-6"><x-filament::button wire:click="exportCsv" icon="heroicon-o-arrow-down-tray" color="gray">{{ __('provider_workspace.ui.export_csv') }}</x-filament::button></div>
    @endif
</div>
