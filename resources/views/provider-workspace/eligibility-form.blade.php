<form wire:submit="check" class="grid gap-3 md:grid-cols-5 items-end">
    <label class="text-sm">{{ __('provider_workspace.screens.patient_search') }}
        <input type="text" wire:model="member_ref" required class="mt-1 block w-full rounded-lg border-gray-300" />
    </label>
    <label class="text-sm">{{ __('provider_workspace.ui.service_code') }}
        <input type="text" wire:model="service_code" required class="mt-1 block w-full rounded-lg border-gray-300" />
    </label>
    <label class="text-sm">{{ __('provider_workspace.ui.policy_id') }}
        <input type="text" wire:model="policy_id" class="mt-1 block w-full rounded-lg border-gray-300" />
    </label>
    <label class="text-sm">{{ __('provider_workspace.ui.service_date') }}
        <input type="date" wire:model="service_date" class="mt-1 block w-full rounded-lg border-gray-300" />
    </label>
    <x-filament::button type="submit">{{ __('provider_workspace.screens.eligibility_check') }}</x-filament::button>
</form>
@if (($this->result['eligible'] ?? false) && auth()->user()->hasPermission('provider.preauth.create'))
    <div data-action="eligibility-to-preauth">
        <x-filament::button tag="a" color="gray" icon="lucide-clipboard-check"
            :href="\App\Application\Providers\Workspace\Filament\Pages\PreauthorizationsPage::getUrl(array_filter(['policy_id' => $this->result['policy_id'] ?? $this->policy_id, 'member_ref' => $this->member_ref, 'service_code' => $this->service_code]), panel: 'provider')">
            {{ __('provider_workspace.screens.new_preauthorization') }}
        </x-filament::button>
    </div>
@endif
