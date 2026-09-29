<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($queues as $key => $n)
            <x-filament::section>
                <div class="text-sm font-medium text-gray-500">{{ __('finance_ops_actions.queues.'.$key) }}</div>
                <div class="mt-1 text-2xl font-semibold {{ $n > 0 ? 'text-warning-600' : '' }}">{{ $n }}</div>
            </x-filament::section>
        @endforeach
    </div>
    <p class="text-sm text-gray-500">{{ __('finance_ops_actions.page.help') }}</p>
</x-filament-panels::page>
