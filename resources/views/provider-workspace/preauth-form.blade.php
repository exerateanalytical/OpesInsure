{{-- Preauthorization request (spec screen preauthorization_request) + provider answers on the opened request. --}}
@php($in = 'mt-1 block w-full rounded-lg border-gray-300 text-sm')
@if ($this->canCreate())
    <x-filament::section collapsible :collapsed="$this->selected !== null" data-form="preauth-request">
        <x-slot name="heading">{{ __('provider_workspace.screens.new_preauthorization') }}</x-slot>
        <form wire:submit="submitRequest" class="grid gap-3 md:grid-cols-3">
            <label class="text-sm">{{ __('provider_workspace.ui.request_type') }}
                <select wire:model.live="request_type" class="{{ $in }}">
                    @foreach (\App\Application\Health\Preauth\PreauthLifecycle::TYPES as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm">{{ __('provider_workspace.ui.policy_id') }}
                <input type="text" wire:model="policy_id" required class="{{ $in }}" />
                @error('policy_id')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm">{{ __('provider_workspace.ui.member_ref') }}
                <input type="text" wire:model="member_ref" class="{{ $in }}" />
            </label>
            <label class="text-sm">{{ __('provider_workspace.ui.facility') }}
                <select wire:model="facility_id" class="{{ $in }}">
                    <option value="">—</option>
                    @foreach ($this->facilityOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </select>
                @error('facility_id')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm">{{ __('provider_workspace.ui.service_code') }}
                <input type="text" wire:model="service_code" required class="{{ $in }}" />
                @error('service_code')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm">{{ __('provider_workspace.ui.quantity') }}
                <input type="number" min="1" step="1" wire:model="quantity" required class="{{ $in }}" />
            </label>
            <label class="text-sm">{{ __('provider_workspace.ui.unit_price_minor') }}
                <input type="number" min="0" step="1" wire:model="unit_price_minor" class="{{ $in }}" />
            </label>
            @foreach ($this->typeFields() as $field => [$required, $rule])
                <label class="text-sm">{{ __('provider_workspace.fields.'.$field) }}@if ($required) *@endif
                    @if (str_contains($rule, 'boolean'))
                        <input type="checkbox" wire:model="details.{{ $field }}" class="mt-2 block rounded border-gray-300" />
                    @elseif (str_contains($rule, 'date'))
                        <input type="date" wire:model="details.{{ $field }}" @required($required) class="{{ $in }}" />
                    @else
                        <input type="text" wire:model="details.{{ $field }}" @required($required) class="{{ $in }}" />
                    @endif
                    @error('details.'.$field)<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                </label>
            @endforeach
            <label class="text-sm md:col-span-3">{{ __('provider_workspace.ui.clinical_notes') }}
                <textarea wire:model="clinical_notes" rows="2" class="{{ $in }}"></textarea>
            </label>
            <div class="md:col-span-3"><x-filament::button type="submit">{{ __('provider_workspace.ui.submit_request') }}</x-filament::button></div>
        </form>
    </x-filament::section>
@endif

@if ($this->selected)
    @php($status = $this->selectedStatus())
    @if ($this->may('provide_info', $status, 'provider.preauth.respond_to_query'))
        <x-filament::section data-form="preauth-respond">
            <x-slot name="heading">{{ __('provider_workspace.screens.preauthorization_query_response') }}</x-slot>
            <form wire:submit="respond" class="grid gap-3">
                <textarea wire:model="answer" rows="3" required class="{{ $in }}"></textarea>
                @error('answer')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                <div><x-filament::button type="submit">{{ __('provider_workspace.ui.send') }}</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif
    @if ($this->may('cancel', $status, 'provider.preauth.create'))
        <x-filament::section collapsible collapsed data-form="preauth-cancel">
            <x-slot name="heading">{{ __('provider_workspace.ui.cancel_request') }}</x-slot>
            <form wire:submit="cancelRequest" class="grid gap-3">
                <input type="text" wire:model="cancel_reason" required placeholder="{{ __('provider_workspace.ui.reason') }}" class="{{ $in }}" />
                @error('cancel_reason')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                <div><x-filament::button type="submit" color="danger" wire:confirm="{{ __('provider_workspace.ui.confirm') }}">{{ __('provider_workspace.ui.cancel_request') }}</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif
@endif
