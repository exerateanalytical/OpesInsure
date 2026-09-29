<div class="flex flex-wrap items-end gap-3">
    @foreach ($this->filters() as $name => $options)
        <label class="text-sm">
            <span class="block text-xs text-gray-500">{{ __('developer_portal.columns.'.$name) }}</span>
            <select wire:model.live="{{ $name }}" class="rounded-lg border-gray-300 text-sm">
                <option value="">{{ __('developer_portal.ui.all') }}</option>
                @foreach ($options as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach
            </select>
        </label>
    @endforeach
</div>
