<x-filament::section>
    <x-slot name="heading">{{ __('admin_screens.branding.current') }}</x-slot>
    @if ($current)
        <div class="flex flex-wrap items-center gap-6">
            <div>
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ __('admin_screens.columns.logo') }}" style="max-height:64px;max-width:220px">
                @else
                    <span class="text-sm text-gray-500">{{ __('admin_screens.branding.no_logo') }}</span>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <span style="display:inline-block;width:28px;height:28px;border-radius:6px;border:1px solid #d1d5db;background:{{ preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $current->brand_color) ? $current->brand_color : 'transparent' }}"></span>
                <span class="text-sm">{{ $current->brand_color ?: __('admin_screens.branding.no_colour') }}</span>
            </div>
            <div class="text-sm text-gray-500">v{{ $current->version }}</div>
        </div>
        @if ($header)
            <img src="{{ $header }}" alt="" class="mt-4" style="max-height:90px;max-width:100%">
        @endif
    @else
        <p class="text-sm text-gray-500">{{ __('admin_screens.branding.none') }}</p>
    @endif
    @if ($pending)
        <p class="mt-3 text-sm text-warning-600">{{ __('admin_screens.branding.pending', ['version' => $pending->version]) }}</p>
    @endif
</x-filament::section>
