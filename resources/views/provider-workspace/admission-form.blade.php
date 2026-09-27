{{-- Admissions: new admission = ADMISSION preauthorization; admit / extend / discharge on the opened admission. --}}
@php($in = 'mt-1 block w-full rounded-lg border-gray-300 text-sm')
@if (auth()->user()->hasPermission('provider.preauth.create'))
    <div><x-filament::button tag="a" :href="\App\Application\Providers\Workspace\Filament\Pages\PreauthorizationsPage::getUrl(['request_type' => 'ADMISSION'], panel: 'provider')" icon="heroicon-o-plus">{{ __('provider_workspace.ui.new_admission') }}</x-filament::button></div>
@endif
@if ($this->selected)
    @if ($this->may('admit', 'provider.admission.create'))
        <x-filament::section data-form="admission-admit">
            <x-slot name="heading">{{ __('provider_workspace.ui.record_admission') }}</x-slot>
            <form wire:submit="admit" class="flex flex-wrap items-end gap-3">
                <label class="text-sm">{{ __('provider_workspace.fields.admission_date') }}<input type="date" wire:model="admitted_on" class="{{ $in }}" /></label>
                <x-filament::button type="submit">{{ __('provider_workspace.ui.record_admission') }}</x-filament::button>
            </form>
        </x-filament::section>
    @endif
    @if ($this->may('extend', 'provider.admission.extend'))
        <x-filament::section data-form="admission-extension">
            <x-slot name="heading">{{ __('provider_workspace.screens.extension_request') }}</x-slot>
            <form wire:submit="requestExtension" class="grid gap-3 md:grid-cols-3 items-end">
                <label class="text-sm">{{ __('provider_workspace.ui.requested_until') }}<input type="date" wire:model="requested_until" required class="{{ $in }}" />
                    @error('requested_until')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror</label>
                <label class="text-sm md:col-span-2">{{ __('provider_workspace.ui.reason') }}<input type="text" wire:model="extension_reason" required class="{{ $in }}" />
                    @error('extension_reason')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror</label>
                <div><x-filament::button type="submit">{{ __('provider_workspace.ui.request_extension') }}</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif
    @if ($this->may('discharge', 'provider.admission.create'))
        <x-filament::section collapsible collapsed data-form="admission-discharge">
            <x-slot name="heading">{{ __('provider_workspace.screens.discharge') }}</x-slot>
            <form wire:submit="discharge" class="flex flex-wrap items-end gap-3">
                <label class="text-sm">{{ __('provider_workspace.ui.discharged_on') }}<input type="date" wire:model="discharged_on" class="{{ $in }}" /></label>
                <x-filament::button type="submit" wire:confirm="{{ __('provider_workspace.ui.confirm') }}">{{ __('provider_workspace.screens.discharge') }}</x-filament::button>
            </form>
        </x-filament::section>
    @endif
@endif
