{{-- Provider claim / invoice capture (new_provider_claim), submission and insurer-query answer on the opened claim. --}}
@php($in = 'mt-1 block w-full rounded-lg border-gray-300 text-sm')
@if ($this->can('provider.claim.create'))
    <x-filament::section collapsible :collapsed="$this->selected !== null" data-form="claim-create">
        <x-slot name="heading">{{ __('provider_workspace.screens.new_provider_claim') }}</x-slot>
        <form wire:submit="createClaim" class="grid gap-3 md:grid-cols-3">
            <label class="text-sm">{{ __('provider_workspace.ui.contract') }} *
                <select wire:model.live="contract_id" required class="{{ $in }}">
                    <option value="">—</option>
                    @foreach ($this->contractOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </select>
                @error('contract_id')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm">{{ __('provider_workspace.ui.invoice_reference') }} *
                <input type="text" wire:model="invoice_reference" required class="{{ $in }}" />
                @error('invoice_reference')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm">{{ __('provider_workspace.ui.service_date') }} *
                <input type="date" wire:model="service_date" required class="{{ $in }}" />
                @error('service_date')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm">{{ __('provider_workspace.ui.policy_id') }}<input type="text" wire:model="policy_id" class="{{ $in }}" /></label>
            <label class="text-sm">{{ __('provider_workspace.ui.member_ref') }}<input type="text" wire:model="member_reference" class="{{ $in }}" /></label>
            <label class="text-sm">{{ __('provider_workspace.ui.preauth_id') }}<input type="text" wire:model="preauth_id" class="{{ $in }}" /></label>
            <label class="text-sm">{{ __('provider_workspace.ui.facility') }}
                <select wire:model="facility_id" class="{{ $in }}">
                    <option value="">—</option>
                    @foreach ($this->facilityOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </select>
                @error('facility_id')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
            <div class="md:col-span-3 grid gap-2">
                <div class="text-sm font-medium">{{ __('provider_workspace.ui.lines') }}</div>
                @php($services = $this->serviceOptions())
                @foreach ($lines as $i => $line)
                    <div class="grid gap-2 md:grid-cols-5 items-end" wire:key="line-{{ $i }}">
                        <label class="text-xs">{{ __('provider_workspace.ui.service_code') }}
                            <select wire:model="lines.{{ $i }}.medical_service_id" class="{{ $in }}">
                                <option value="">—</option>
                                @foreach ($services as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                            </select>
                        </label>
                        <label class="text-xs">{{ __('provider_workspace.ui.provider_code') }}<input type="text" wire:model="lines.{{ $i }}.provider_code" class="{{ $in }}" /></label>
                        <label class="text-xs">{{ __('provider_workspace.ui.quantity') }}<input type="number" min="1" step="1" wire:model="lines.{{ $i }}.quantity" class="{{ $in }}" /></label>
                        <label class="text-xs">{{ __('provider_workspace.ui.unit_price_minor') }} *<input type="number" min="0" step="1" wire:model="lines.{{ $i }}.unit_price_minor" required class="{{ $in }}" />
                            @error('lines.'.$i.'.unit_price_minor')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror</label>
                        <div><x-filament::link tag="button" color="danger" size="sm" wire:click="removeLine({{ $i }})">{{ __('provider_workspace.ui.remove') }}</x-filament::link></div>
                    </div>
                @endforeach
                <div><x-filament::link tag="button" size="sm" wire:click="addLine">{{ __('provider_workspace.ui.add_line') }}</x-filament::link></div>
                @error('lines')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
            </div>
            <div class="md:col-span-3"><x-filament::button type="submit">{{ __('provider_workspace.ui.save_draft') }}</x-filament::button></div>
        </form>
    </x-filament::section>
@endif

@if ($this->selected)
    @php($status = $this->selectedStatus())
    @if ($status === 'DRAFT' && $this->can('provider.claim.submit'))
        <div data-form="claim-submit"><x-filament::button wire:click="submitClaim" wire:confirm="{{ __('provider_workspace.ui.confirm') }}" icon="lucide-send">{{ __('provider_workspace.ui.submit_claim') }}</x-filament::button></div>
    @endif
    @if (in_array($status, ['SUBMITTED', 'UNDER_REVIEW', 'DISPUTED'], true) && $this->can('provider.claim.respond_to_query'))
        <x-filament::section collapsible collapsed data-form="claim-respond">
            <x-slot name="heading">{{ __('provider_workspace.screens.claim_query_response') }}</x-slot>
            <form wire:submit="respond" class="grid gap-3">
                <textarea wire:model="query_response" rows="3" required class="{{ $in }}"></textarea>
                @error('query_response')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                <div><x-filament::button type="submit">{{ __('provider_workspace.ui.send') }}</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif
@endif
