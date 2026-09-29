{{-- S11 in-app help (App\Filament\Shared\Pages\HelpPage): role guide, searchable, anchored sections, printable version. --}}
<x-filament-panels::page>
    @include('help.styles')
    <div class="fi-section" style="padding:16px;border-radius:12px;background:#fff;border:1px solid #e5e7eb">
        @if(count($offered) > 1)
            <nav class="opes-help-tabs" aria-label="{{ __('help_guides.other_guides') }}">
                @foreach($offered as $g)
                    <a href="?guide={{ $g }}" @if($g === $guideKey) aria-current="page" @endif>{{ __('help_guides.guides.'.$g) }}</a>
                @endforeach
            </nav>
        @endif
        <div class="opes-help-bar">
            <input type="search" wire:model.live.debounce.300ms="q" maxlength="100" placeholder="{{ __('help_guides.search_placeholder') }}" aria-label="{{ __('help_guides.search') }}" data-help-search>
            <a href="{{ $printUrl }}" target="_blank" rel="noopener">{{ __('help_guides.print_version') }}</a>
        </div>
        <h2 style="font-size:1.35rem;font-weight:700;margin:.5rem 0">{{ $guideData['title'] }}</h2>
        @include('help.guide-body', ['helpGuide' => $guideData, 'sections' => $sections])
    </div>
</x-filament-panels::page>
