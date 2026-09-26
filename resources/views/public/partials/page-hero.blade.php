{{-- Info-page hero (new desktop layout): white-first, breadcrumb, navy title, lede, photo on the right, trust points.
     Params: $title, $lede?, $eyebrow?, $img? (basename in /landing/img/desk, default "banner"), $points? (list of strings; defaults to site.hero.trust; pass [] to hide), $crumb? --}}
@php
  $heroImg = $img ?? 'banner';
  $heroPoints = $points ?? __('site.hero.trust');
  $heroJpg = file_exists(public_path('landing/img/desk/'.$heroImg.'.jpg'));
@endphp
<section class="ip-hero" aria-labelledby="page-title">
  <div class="ip-photo" aria-hidden="true"><picture><source type="image/webp" srcset="/landing/img/desk/{{ $heroImg }}.webp"><img src="/landing/img/desk/{{ $heroImg }}.{{ $heroJpg ? 'jpg' : 'webp' }}" alt="" fetchpriority="high"></picture></div>
  <div class="wrap-x">
    <div class="ip-copy">
      <nav class="crumbs" aria-label="Breadcrumb"><a href="/">{{ __('desk.home') }}</a>@include('public.partials.i', ['n' => 'chev'])<span aria-current="page">{{ $crumb ?? $title }}</span></nav>
      @isset($eyebrow)<p class="d-eyebrow">{{ $eyebrow }}</p>@endisset
      <h1 id="page-title">{{ $title }}</h1>
      @isset($lede)<p class="lede">{{ $lede }}</p>@endisset
      @if(is_array($heroPoints) && count($heroPoints))
        <ul class="ip-points">
          @foreach($heroPoints as $pi => $pt)
            <li><span class="d d{{ $pi % 4 }}">@include('public.partials.i', ['n' => ['shield', 'lock', 'check', 'scale'][$pi % 4]])</span>{{ $pt }}</li>
          @endforeach
        </ul>
      @endif
    </div>
  </div>
</section>
