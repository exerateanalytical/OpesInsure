<div class="flex flex-wrap items-end gap-3" data-form="documents-filter">
    <label class="text-sm">{{ __('provider_workspace.ui.document_type') }}
        <select wire:model.live="type" class="mt-1 block rounded-lg border-gray-300 text-sm">
            <option value="">{{ __('provider_workspace.ui.all') }}</option>
            @foreach ($this->typeOptions() as $t)<option value="{{ $t }}">{{ str_replace('_', ' ', $t) }}</option>@endforeach
        </select>
    </label>
</div>
