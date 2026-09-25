{{-- Canonical UI handoff (bilingual): EN/FR switch in every panel top bar and auth page. --}}
@php($current = app()->getLocale())
<nav class="oi-lang" aria-label="{{ __('web_experience.shell.language') }}" data-testid="language-switch">
    @foreach (['en' => 'EN', 'fr' => 'FR'] as $code => $label)
        <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" hreflang="{{ $code }}" lang="{{ $code }}"
           @if ($current === $code) aria-current="true" @endif
           title="{{ __('web_experience.shell.language_'.$code) }}">{{ $label }}</a>
    @endforeach
</nav>
