@php($issued = $this->issuedCredential())
@if ($issued)
    <x-filament::section data-secret-once>
        <x-slot name="heading">{{ __('developer_portal.ui.secret_once_title') }}</x-slot>
        <p class="text-sm text-danger-600 mb-3">{{ __('developer_portal.ui.secret_once_warning') }}</p>
        <dl class="grid gap-2 text-sm">
            <div><dt class="text-xs text-gray-500">{{ __('developer_portal.columns.oauth_client_id') }}</dt><dd class="font-mono break-all">{{ $issued['client_id'] }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('developer_portal.columns.client_secret') }}</dt><dd class="font-mono break-all">{{ $issued['client_secret'] }}</dd></div>
        </dl>
    </x-filament::section>
@endif
@if ($this->canManageKeys())
    <x-filament::section>
        <x-slot name="heading">{{ __('developer_portal.ui.issue_sandbox_key') }}</x-slot>
        <form wire:submit="issueKey" class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block text-xs text-gray-500">{{ __('developer_portal.columns.label') }}</span>
                <input type="text" wire:model="label" maxlength="120" class="rounded-lg border-gray-300 text-sm">
            </label>
            <x-filament::button type="submit" size="sm">{{ __('developer_portal.ui.issue') }}</x-filament::button>
        </form>
        @error('label')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
        <p class="mt-2 text-xs text-gray-500">{{ __('developer_portal.ui.production_keys_note') }}</p>
    </x-filament::section>
@endif
