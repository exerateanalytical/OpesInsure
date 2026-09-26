<form wire:submit="check" class="grid gap-3 md:grid-cols-5 items-end">
    <label class="text-sm">{{ __('provider_workspace.screens.patient_search') }}
        <input type="text" wire:model="member_ref" required class="mt-1 block w-full rounded-lg border-gray-300" />
    </label>
    <label class="text-sm">Service
        <input type="text" wire:model="service_code" required class="mt-1 block w-full rounded-lg border-gray-300" />
    </label>
    <label class="text-sm">Policy
        <input type="text" wire:model="policy_id" class="mt-1 block w-full rounded-lg border-gray-300" />
    </label>
    <label class="text-sm">Date
        <input type="date" wire:model="service_date" class="mt-1 block w-full rounded-lg border-gray-300" />
    </label>
    <x-filament::button type="submit">{{ __('provider_workspace.screens.eligibility_check') }}</x-filament::button>
</form>
