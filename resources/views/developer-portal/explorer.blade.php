@php($op = $this->selectedOperation())
@if ($op)
    <x-filament::section data-operation="{{ $op['operation_id'] }}">
        <x-slot name="heading">{{ $op['method'] }} {{ $op['path'] }}</x-slot>
        <dl class="grid gap-3 md:grid-cols-3 mb-3 text-sm">
            <div><dt class="text-xs text-gray-500">{{ __('developer_portal.columns.scopes') }}</dt><dd>{{ $op['scopes'] ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('developer_portal.columns.parameters') }}</dt><dd>{{ $op['parameters'] ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">{{ __('developer_portal.columns.body_fields') }}</dt><dd>{{ $op['body_fields'] ?: '—' }}</dd></div>
        </dl>
        <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100">{{ $op['curl'] }}</pre>
        <p class="mt-2 text-xs text-gray-500">{{ __('developer_portal.ui.explorer_note') }}</p>
    </x-filament::section>
@endif
