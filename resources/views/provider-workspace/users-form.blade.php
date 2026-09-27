{{-- User management: assign a staff member of the provider a portal role and facility scope (POST /api/v1/provider-portal/users). --}}
@php($f = 'provider-workspace.field')
<x-filament::section collapsible data-form="user-assign">
    <x-slot name="heading">{{ __('provider_workspace.ui.assign_user') }}</x-slot>
    <form wire:submit="assign" class="grid gap-3 md:grid-cols-4 items-end">
        @include($f, ['model' => 'user_id', 'label' => 'ui.user', 'type' => 'select', 'required' => true, 'options' => $this->staffOptions()])
        @include($f, ['model' => 'provider_role', 'label' => 'ui.provider_role', 'type' => 'select', 'required' => true, 'options' => $this->roleOptions()])
        @include($f, ['model' => 'facility_scope', 'label' => 'ui.facility_scope', 'type' => 'select', 'required' => true, 'live' => true,
            'options' => ['ALL' => __('provider_workspace.ui.scope_all'), 'ASSIGNED' => __('provider_workspace.ui.scope_assigned')]])
        @if ($facility_scope === 'ASSIGNED')
            <label class="text-sm">{{ __('provider_workspace.ui.facility') }}
                <select wire:model="facility_ids" multiple class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                    @foreach ($this->facilityOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </select>
            </label>
        @endif
        <div><x-filament::button type="submit">{{ __('provider_workspace.ui.assign') }}</x-filament::button></div>
    </form>
</x-filament::section>
