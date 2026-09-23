<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title ?? 'OpesInsure' }}</title>
<meta name="description" content="{{ $description ?? __('public.meta_description') }}">
<link rel="icon" type="image/png" href="{{ asset('img/app-icon.png') }}">
<link rel="apple-touch-icon" href="{{ asset('img/app-icon.png') }}">
<link rel="preload" href="{{ asset('fonts/manrope/manrope-latin-variable.woff2') }}" as="font" type="font/woff2" crossorigin>
<style>
@font-face{font-family:Manrope;src:url("{{ asset('fonts/manrope/manrope-latin-variable.woff2') }}") format("woff2");font-weight:200 800;font-display:swap}
:root{--navy:#071A2B;--navy-2:#0b2942;--teal:#0f766e;--teal-2:#075d56;--blue:#155FCC;--gold:#D5A13C;--ink:#111820;--muted:#566776;--line:#DCE3E8;--bg:#F5F7F8;--white:#fff;--radius:14px}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:Manrope,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:var(--white);line-height:1.6;font-size:16px}
a{color:inherit}
.wrap{max-width:1080px;margin:0 auto;padding:0 24px}
header.site{background:var(--navy);color:#fff}
header.site .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:68px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;letter-spacing:-.01em;text-decoration:none;color:#fff;padding:12px 0}
.brand img{height:34px;width:auto;display:block}
@media(max-width:640px){.brand img{height:28px}}
nav.site a{color:#cfdae4;text-decoration:none;margin-left:22px;font-size:.94rem;font-weight:600}
nav.site a:hover,nav.site a:focus-visible{color:#fff}
.hero{background:linear-gradient(160deg,var(--navy) 0%,var(--navy-2) 58%,#0d3550 100%);color:#fff;padding:72px 0 80px}
.hero h1{margin:0 0 16px;font-size:clamp(2rem,5vw,3.15rem);line-height:1.1;letter-spacing:-.025em;font-weight:800;max-width:19ch}
.hero p.lede{margin:0 0 30px;font-size:clamp(1.02rem,2vw,1.2rem);color:#c3d3e0;max-width:58ch}
.eyebrow{display:inline-block;font-size:.76rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--gold);margin-bottom:18px}
.btn{display:inline-flex;align-items:center;gap:10px;padding:13px 24px;border-radius:11px;font-weight:700;text-decoration:none;font-size:.97rem;border:1.5px solid transparent;transition:transform .12s ease,box-shadow .12s ease,background .12s ease}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:linear-gradient(135deg,var(--teal),var(--teal-2));color:#fff;box-shadow:0 6px 20px rgba(15,118,110,.32)}
.btn-ghost{border-color:rgba(255,255,255,.34);color:#fff}
.btn-ghost:hover{background:rgba(255,255,255,.09)}
.btn[aria-disabled=true]{opacity:.5;pointer-events:none;box-shadow:none}
.btn-row{display:flex;gap:14px;flex-wrap:wrap}
.app-icon{width:104px;height:104px;border-radius:24px;display:block;box-shadow:0 12px 34px rgba(0,0,0,.42)}
.hero-flex{display:flex;gap:30px;align-items:flex-start;flex-wrap:wrap}
.hero-flex>div{flex:1;min-width:280px}
@media(max-width:640px){.app-icon{width:78px;height:78px;border-radius:18px}}

section{padding:66px 0}
section.alt{background:var(--bg);border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
h2{font-size:clamp(1.45rem,3vw,2rem);letter-spacing:-.02em;margin:0 0 12px;font-weight:800}
.sub{color:var(--muted);margin:0 0 34px;max-width:62ch}
.grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fit,minmax(248px,1fr))}
.card{background:var(--white);border:1px solid var(--line);border-radius:var(--radius);padding:24px}
.card h3{margin:0 0 8px;font-size:1.03rem;font-weight:700}
.card p{margin:0;color:var(--muted);font-size:.93rem}
.ico{display:grid;place-items:center;width:38px;height:38px;border-radius:10px;background:rgba(15,118,110,.1);color:var(--teal);margin-bottom:14px;font-size:1.05rem}
footer.site{background:var(--navy);color:#8fa3b4;padding:34px 0;font-size:.88rem}
footer.site .wrap{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}
footer.site a{color:#c3d3e0;text-decoration:none;margin-left:18px}
.foot-brand img{height:26px;width:auto;display:block;margin-bottom:9px;opacity:.92}
.note{display:flex;gap:12px;padding:15px 17px;border-radius:11px;background:rgba(213,161,60,.1);border:1px solid rgba(213,161,60,.34);color:#6b5115;font-size:.9rem;align-items:flex-start}
.note strong{color:#4a380e}
.tablewrap{overflow-x:auto;border:1px solid var(--line);border-radius:var(--radius);background:var(--white)}
table.demo{width:100%;border-collapse:collapse;font-size:.93rem;min-width:420px}
table.demo th{text-align:left;font-weight:700;padding:13px 18px;background:var(--bg);border-bottom:1px solid var(--line);white-space:nowrap}
table.demo td{padding:13px 18px;border-bottom:1px solid var(--line)}
table.demo tr:last-child td{border-bottom:0}
table.demo code{background:rgba(7,26,43,.06);padding:3px 8px;border-radius:6px;font-size:.88em}

@media(max-width:640px){
 nav.site{display:none}
 .hero{padding:52px 0 58px}
 section{padding:48px 0}
 footer.site .wrap{flex-direction:column}
 footer.site a{margin:0 16px 0 0}
}
</style>
</head>
<body>
<header class="site"><div class="wrap">
  <a class="brand" href="/"><img src="{{ asset('img/logo-white.png') }}" alt="OpesInsure" width="2172" height="724"></a>
  <nav class="site">
    <a href="/download">{{ __('public.nav_download') }}</a>
    @if(config('demo.enabled'))<a href="/demo">{{ __('public.nav_demo') }}</a>@endif
    <a href="/admin">{{ __('public.nav_portal') }}</a>
  </nav>
</div></header>
{{ $slot }}
<footer class="site"><div class="wrap">
  <span class="foot-brand"><img src="{{ asset('img/logo-white.png') }}" alt="OpesInsure" width="2172" height="724"><br>&copy; {{ date('Y') }} OpesInsure</span>
  <span><a href="/download">{{ __('public.nav_download') }}</a><a href="/admin">{{ __('public.nav_portal') }}</a></span>
</div></footer>
</body>
</html>
