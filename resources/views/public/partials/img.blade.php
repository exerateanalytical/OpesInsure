@php
  // Optimised decorative/illustrative layer from public/landing/img
  // (generated from "landing page/*.png": cropped, WebP at 1x/2x widths,
  // quantised PNG fallback at the small width).
  $dims = [
    'map-connected' => [340, 320, 600], 'map-dark' => [300, 287, 520], 'pin-cameroon' => [64, 90, 128],
    'safer-brighter-africa' => [170, 141, 340], 'glow' => [140, 141, 280], 'gold-stroke' => [240, 28, 480],
    'wave-gold' => [720, 232, 1200], 'wave-light' => [720, 130, 1200], 'pattern-right' => [48, 480, 96],
    'app-icon' => [48, 47, 96], 'blue-sweep' => [600, 400, 1000],
  ];
  [$w, $h, $w2] = $dims[$name];
  $base = '/landing/img/'.$name;
  $lazy = !($eager ?? false);
@endphp
<picture><source type="image/webp" srcset="{{ $base }}-{{ $w }}.webp {{ $w }}w, {{ $base }}-{{ $w2 }}.webp {{ $w2 }}w" sizes="{{ $sizes ?? $w.'px' }}"><img src="{{ $base }}-{{ $w }}.png" width="{{ $w }}" height="{{ $h }}" alt="{{ $alt ?? '' }}" @if($lazy) loading="lazy" @else fetchpriority="{{ $priority ?? 'auto' }}" @endif decoding="async" @isset($class) class="{{ $class }}" @endisset></picture>
