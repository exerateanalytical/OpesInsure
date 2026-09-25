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
    $signIn = $inPanel ? '/'.$m[1].'/login' : '/admin/login';
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
<link rel="preload" href="/fonts/filament/filament/inter/inter-latin-wght-normal-DIHYUR35.woff2" as="font" type="font/woff2" crossorigin>
<style>
@font-face{font-family:'Inter Variable';font-style:normal;font-display:swap;font-weight:100 900;src:url(/fonts/filament/filament/inter/inter-latin-wght-normal-DIHYUR35.woff2) format('woff2-variations');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
:root{--navy:#071A2B;--blue:#1769E0;--emerald:#07855B;--gold:#D99100;--red:#C9363E;--canvas:#F6F8FA;--card:#FFFFFF;--border:#DCE3E8;--muted:#566776;
  --info-soft:#EEF5FF;--info-text:#1256B8;--warning-soft:#FFF6DD;--warning-text:#764B00;--danger-soft:#FDEDEF;--danger-text:#98272E}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:var(--canvas);color:var(--navy);font:16px/1.55 'Inter Variable',Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif}
.skip{position:absolute;left:12px;top:-60px;background:var(--blue);color:#fff;padding:10px 14px;border-radius:8px;font-weight:600}
.skip:focus{top:10px}
header{background:var(--navy);color:#fff}
.bar{max-width:1440px;margin:0 auto;padding:0 16px;min-height:56px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.brand{color:#fff;font-weight:700;font-size:1.125rem;text-decoration:none}
.lang{display:inline-flex;border:1px solid rgba(255,255,255,.35);border-radius:999px;overflow:hidden;font-size:.8125rem;font-weight:600}
.lang a{display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:40px;color:#DCE7EF;text-decoration:none}
.lang a[aria-current=true]{background:#fff;color:var(--navy)}
main{flex:1;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px}
.card{width:100%;max-width:760px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:24px}
.state{display:flex;gap:12px;align-items:flex-start;padding:12px 16px;border-radius:12px;margin-bottom:20px}
.state svg{width:22px;height:22px;flex:none;margin-top:2px}
.state--info{background:var(--info-soft);color:var(--info-text)}.state--warning{background:var(--warning-soft);color:var(--warning-text)}.state--danger{background:var(--danger-soft);color:var(--danger-text)}
.code{font-variant-numeric:tabular-nums;font-weight:600;margin:0}
h1{font-size:clamp(1.5rem,4vw,2rem);line-height:1.2;margin:0 0 12px}
p{margin:0 0 16px;max-width:65ch}
.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:8px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 20px;border-radius:8px;font-weight:600;text-decoration:none;border:1px solid var(--blue)}
.btn--primary{background:var(--blue);color:#fff}
.btn--secondary{background:#fff;color:var(--blue)}
a:focus-visible{outline:3px solid var(--blue);outline-offset:2px}
header a:focus-visible{outline-color:#8CB8F5}
footer{color:var(--muted);font-size:.8125rem;text-align:center;padding:16px}
@media (min-width:480px){.bar{padding:0 24px}main{padding:56px 24px}.card{padding:32px}}
@media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}
@media (prefers-contrast:more){.card{border-color:var(--muted)}.btn{border-width:2px}}
</style>
</head>
<body>
<a class="skip" href="#main">{{ __('web_experience.shell.skip') }}</a>
<header>
  <div class="bar">
    <a class="brand" href="/">OpesInsure</a>
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
