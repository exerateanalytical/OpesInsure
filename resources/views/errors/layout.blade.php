{{-- Canonical UI handoff Batch 9: global exceptional states (403/404/409/419/422/429/500/503).
     Standalone on purpose (no DB, no session writes, no JS): it must render even when the app is failing.
     EN/FR: ?lang= > remembered public/panel choice > browser language; the stable HTTP code is always shown. --}}
@php
    $supported = ['en', 'fr'];
    $req = request();
    $loc = strtolower((string) $req->query('lang', ''));
    if (! in_array($loc, $supported, true)) {
        $loc = rescue(fn () => $req->hasSession() ? $req->session()->get('public_locale') : null, null, false);
    }
    if (! in_array($loc, $supported, true)) {
        $loc = $req->headers->has('Accept-Language') ? ($req->getPreferredLanguage($supported) ?: 'en') : (in_array(app()->getLocale(), $supported, true) ? app()->getLocale() : 'en');
    }
    app()->setLocale($loc);
    $code = (string) $code;
    $title = __('web_experience.errors.'.$code.'.title');
    $message = __('web_experience.errors.'.$code.'.message');
    $inPanel = preg_match('#^/?(admin|insurer|broker)(/|$)#', '/'.ltrim($req->path(), '/'), $m) === 1;
    $signIn = $inPanel ? '/'.$m[1].'/login' : '/login';
    $tone = in_array($code, ['500', '503'], true) ? 'danger' : (in_array($code, ['409', '419', '422', '429'], true) ? 'warning' : 'info');
    $icon = match ($tone) { 'danger' => 'circle-x', 'warning' => 'clock', default => 'info' };
@endphp
<!doctype html>
<html lang="{{ $loc }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }} · OpesInsure</title>
<link rel="preload" href="/fonts/manrope/manrope-latin-variable.woff2" as="font" type="font/woff2" crossorigin>
<style>
/* Same pattern as the public desktop pages (landing page/…/Desktop_Pack); standalone so it renders even when the app is failing. */
@font-face{font-family:Manrope;src:url(/fonts/manrope/manrope-latin-variable.woff2) format("woff2");font-weight:200 800;font-display:swap}
:root{--navy:#0A1E4D;--royal:#0D4FDB;--gold:#F2A12E;--canvas:#F5F8FD;--card:#fff;--border:#E3EAF4;--muted:#46546B;
  --info-soft:#E8EFFF;--info-text:#1D4ED8;--warning-soft:#FFF1D6;--warning-text:#8A5300;--danger-soft:#FDECEC;--danger-text:#B42318}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:var(--canvas);color:var(--navy);font:16px/1.55 Manrope,ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;padding-left:48px;position:relative}
body::before{content:"";position:fixed;left:0;top:0;bottom:0;width:48px;background:url(/landing/img/desk/tribal-v.webp) top/48px auto repeat-y}
.skip{position:absolute;left:60px;top:-60px;background:var(--royal);color:#fff;padding:10px 14px;border-radius:8px;font-weight:700}
.skip:focus{top:10px}
header{background:#fff;border-bottom:1px solid var(--border)}
.bar{max-width:1440px;margin:0 auto;padding:0 24px;min-height:72px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.brand{display:flex;align-items:center;gap:10px;color:var(--navy);font-weight:800;font-size:1.5rem;letter-spacing:-.03em;text-decoration:none}
.brand img{width:40px;height:40px;border-radius:10px}.brand b{color:var(--gold)}
.lang{display:inline-flex;border:1px solid #D8E1EE;border-radius:999px;overflow:hidden;font-size:.8125rem;font-weight:700}
.lang a{display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:38px;color:var(--muted);text-decoration:none}
.lang a[aria-current=true]{background:var(--navy);color:#fff}
main{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 16px;background:linear-gradient(180deg,#F8FBFF,#EEF4FD)}
.card{position:relative;overflow:hidden;width:100%;max-width:820px;background:var(--card);border:1px solid var(--border);border-radius:16px;padding:32px;box-shadow:0 20px 50px rgba(3,28,68,.10)}
.card::after{content:"";position:absolute;right:-40px;top:-30px;width:260px;height:260px;background:url(/landing/img/desk/map-orange.webp) center/contain no-repeat;opacity:.18;pointer-events:none}
.card>*{position:relative;z-index:1}
.eyebrow{font-size:12px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:var(--royal);margin:0 0 8px}
.state{display:inline-flex;gap:10px;align-items:center;padding:8px 14px;border-radius:999px;margin-bottom:16px}
.state svg{width:20px;height:20px;flex:none}
.state--info{background:var(--info-soft);color:var(--info-text)}.state--warning{background:var(--warning-soft);color:var(--warning-text)}.state--danger{background:var(--danger-soft);color:var(--danger-text)}
.code{font-variant-numeric:tabular-nums;font-weight:700;margin:0}
h1{font-size:clamp(1.75rem,4vw,2.6rem);font-weight:800;letter-spacing:-.03em;line-height:1.1;margin:0 0 12px;max-width:22ch}
.rule{display:block;width:90px;height:3px;background:linear-gradient(90deg,#C8862F,#F1C06D);margin:0 0 16px}
p{margin:0 0 16px;max-width:60ch;color:var(--muted)}
.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:8px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 24px;border-radius:8px;font-weight:700;text-decoration:none;border:1.5px solid var(--royal)}
.btn--primary{background:var(--royal);color:#fff;box-shadow:0 8px 18px rgba(13,79,219,.22)}
.btn--secondary{background:#fff;color:var(--royal)}
a:focus-visible{outline:3px solid #F1C06D;outline-offset:2px}
footer{color:var(--muted);font-size:.8125rem;text-align:center;padding:16px;background:#fff;border-top:1px solid var(--border)}
@media (max-width:640px){body{padding-left:0}body::before{display:none}.card{padding:24px}.card::after{display:none}}
@media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}
@media (prefers-contrast:more){.card{border-color:var(--muted)}.btn{border-width:2px}}
</style>
</head>
<body>
<a class="skip" href="#main">{{ __('web_experience.shell.skip') }}</a>
<header>
  <div class="bar">
    <a class="brand" href="/"><img src="/landing/img/app-icon-48.png" alt="" width="40" height="40"><span>Opes<b>Insure</b></span></a>
    <nav class="lang" aria-label="{{ __('web_experience.shell.language') }}">
      <a href="{{ $req->fullUrlWithQuery(['lang' => 'en']) }}" hreflang="en" lang="en" @if($loc === 'en') aria-current="true" @endif>EN</a>
      <a href="{{ $req->fullUrlWithQuery(['lang' => 'fr']) }}" hreflang="fr" lang="fr" @if($loc === 'fr') aria-current="true" @endif>FR</a>
    </nav>
  </div>
</header>
<main id="main">
  <section class="card" aria-labelledby="err-title" data-error="{{ $code }}">
    <div class="state state--{{ $tone }}" role="{{ $tone === 'info' ? 'status' : 'alert' }}">
      {{ svg('lucide-'.$icon, '', ['aria-hidden' => 'true', 'focusable' => 'false']) }}
      <p class="code">{{ __('web_experience.errors.code', ['code' => $code]) }}</p>
    </div>
    <h1 id="err-title">{{ $title }}</h1>
    <span class="rule" aria-hidden="true"></span>
    <p>{{ $message }}</p>
    <div class="actions">
      @if (in_array($code, ['409', '419', '429', '500', '503'], true))
        <a class="btn btn--primary" href="{{ $req->fullUrl() }}">{{ __('web_experience.errors.retry') }}</a>
        <a class="btn btn--secondary" href="/">{{ __('web_experience.errors.home') }}</a>
      @elseif ($code === '403')
        <a class="btn btn--primary" href="/">{{ __('web_experience.errors.home') }}</a>
        <a class="btn btn--secondary" href="{{ $signIn }}">{{ __('web_experience.errors.sign_in') }}</a>
      @else
        <a class="btn btn--primary" href="/">{{ __('web_experience.errors.home') }}</a>
        <a class="btn btn--secondary" href="/contact">{{ __('web_experience.errors.contact') }}</a>
      @endif
    </div>
  </section>
</main>
<footer><span class="code">{{ now()->format('Y-m-d H:i T') }}</span></footer>
</body>
</html>
