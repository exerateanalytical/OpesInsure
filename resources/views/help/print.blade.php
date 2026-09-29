<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $guide['title'] }} — OpesInsure</title>
@include('help.styles')
<style>
body{font-family:Manrope,Arial,Helvetica,sans-serif;margin:0;background:#fff}
.page{max-width:860px;margin:0 auto;padding:24px 16px}
header{display:flex;justify-content:space-between;align-items:center;gap:1rem;border-bottom:2px solid #1769E0;padding-bottom:.75rem}
header h1{font-size:1.5rem;margin:0}
.meta{color:#566776;font-size:.85rem}
.noprint button,.noprint a{font:inherit;border:1px solid #1769E0;background:#1769E0;color:#fff;border-radius:8px;padding:.45rem .9rem;cursor:pointer;text-decoration:none}
.opes-help-anchor{display:none}
@media print{.noprint{display:none}.page{padding:0;max-width:none}.opes-help-section{break-inside:auto}.opes-help h2{break-after:avoid}a{color:inherit}@page{margin:18mm 16mm}}
</style>
</head>
<body>
<div class="page">
  <header>
    <div><h1>{{ $guide['title'] }}</h1><div class="meta">OpesInsure · {{ __('help_guides.print_meta', ['date' => now()->format('Y-m-d')]) }}</div></div>
    <div class="noprint">
      <a href="?lang={{ app()->getLocale() === 'fr' ? 'en' : 'fr' }}" style="background:#fff;color:#1256B8">{{ app()->getLocale() === 'fr' ? 'English' : 'Français' }}</a>
      <button type="button" onclick="window.print()">{{ __('help_guides.print') }}</button>
    </div>
  </header>
  @include('help.guide-body', ['guide' => $guide, 'sections' => $guide['sections']])
</div>
</body>
</html>
