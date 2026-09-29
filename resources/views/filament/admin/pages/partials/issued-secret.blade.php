<x-filament::section>
    <x-slot name="heading">{{ __('admin_screens.secret.heading', ['name' => $issued['name']]) }}</x-slot>
    <p class="text-sm text-warning-600">{{ __('admin_screens.secret.once') }}</p>
    <dl class="mt-3 grid grid-cols-1 gap-2 text-sm">
        <div><dt class="font-medium">{{ __('admin_screens.secret.client_id') }}</dt><dd class="font-mono break-all">{{ $issued['client_id'] }}</dd></div>
        @if (! empty($issued['secret']))
            <div><dt class="font-medium">{{ __('admin_screens.secret.secret') }}</dt><dd class="font-mono break-all">{{ $issued['secret'] }}</dd></div>
        @endif
    </dl>
    <div class="mt-3">
        <x-filament::button color="gray" wire:click="dismissSecret">{{ __('admin_screens.secret.dismiss') }}</x-filament::button>
    </div>
</x-filament::section>
