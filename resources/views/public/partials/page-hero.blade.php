<section class="hero" aria-labelledby="page-title">
  @include('public.partials.img', ['name' => 'map-connected', 'class' => 'hero-decor', 'sizes' => '(max-width: 640px) 55vw, 460px', 'eager' => true])
  <div class="wrap">
    @isset($eyebrow)<p class="eyebrow">{{ $eyebrow }}</p>@endisset
    <h1 id="page-title">{{ $title }}</h1>
    @isset($lede)<p class="lede">{{ $lede }}</p>@endisset
  </div>
</section>
