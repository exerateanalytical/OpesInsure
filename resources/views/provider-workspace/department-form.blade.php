{{-- Facilities: add a department or service unit to one of the provider's facilities (provider.settings.manage). --}}
@php($cls = 'mt-1 block w-full rounded-lg border-gray-300 text-sm')
@php($t = fn (string $k) => __('provider_portal_actions.department.'.$k))
@if ($this->allows('provider.settings.manage'))
    <x-filament::section collapsible collapsed data-form="department-add">
        <x-slot name="heading">{{ $t('heading') }}</x-slot>
        <form wire:submit="addDepartment" class="grid gap-3 md:grid-cols-3">
            <label class="text-sm">{{ $t('facility') }}
                <select wire:model.live="department_facility" class="{{ $cls }}" required>
                    <option value="">—</option>
                    @foreach ($this->facilityOptions() as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm">{{ $t('code') }}
                <input type="text" wire:model="department_code" class="{{ $cls }}" required maxlength="64" />
                @error('department_code')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm">{{ $t('name') }}
                <input type="text" wire:model="department_name" class="{{ $cls }}" required maxlength="200" />
                @error('department_name')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm">{{ $t('level') }}
                <select wire:model.live="department_level" class="{{ $cls }}">
                    <option value="DEPARTMENT">{{ $t('level_department') }}</option>
                    <option value="SERVICE_UNIT">{{ $t('level_service_unit') }}</option>
                </select>
            </label>
            @if ($this->department_level === 'SERVICE_UNIT')
                <label class="text-sm">{{ $t('parent') }}
                    <select wire:model="parent_department_id" class="{{ $cls }}" required>
                        <option value="">—</option>
                        @foreach ($this->parentDepartmentOptions() as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </label>
            @endif
            <label class="text-sm">{{ $t('specialty') }}
                <input type="text" wire:model="department_specialty" class="{{ $cls }}" maxlength="64" />
            </label>
            <div class="md:col-span-3"><x-filament::button type="submit">{{ $t('submit') }}</x-filament::button></div>
        </form>
    </x-filament::section>
@endif
