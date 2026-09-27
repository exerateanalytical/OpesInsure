{{-- One labelled form field bound to a Livewire property: @include('provider-workspace.field', ['model' => 'x', 'label' => 'ui.x', 'type' => 'text|date|number|textarea|select', 'options' => [value => label], 'required' => bool, 'live' => bool]) --}}
@php($cls = 'mt-1 block w-full rounded-lg border-gray-300 text-sm')
@php($wire = ($live ?? false) ? 'wire:model.live' : 'wire:model')
<label class="text-sm {{ $span ?? '' }}">{{ __('provider_workspace.'.$label) }}
    @if (($type ?? 'text') === 'select')
        <select {{ $wire }}="{{ $model }}" class="{{ $cls }}" @required($required ?? false)>
            @unless ($required ?? false)<option value="">—</option>@endunless
            @foreach ($options as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
        </select>
    @elseif (($type ?? 'text') === 'textarea')
        <textarea {{ $wire }}="{{ $model }}" rows="2" class="{{ $cls }}" @required($required ?? false)></textarea>
    @else
        <input type="{{ $type ?? 'text' }}" {{ $wire }}="{{ $model }}" class="{{ $cls }}" @required($required ?? false) />
    @endif
    @error($model)<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
</label>
