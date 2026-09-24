<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OpesInsure — Insurance from trusted providers. One place. Anywhere. Anytime.</title>
<meta name="description" content="Compare insurance from multiple insurance companies, brokers and agents in Cameroon. Buy securely, manage policies, renew cover and follow claims from one app.">
<link rel="icon" href="/landing/app-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Caveat:wght@600&display=swap" rel="stylesheet">
<style>
:root{--navy:#031C44;--navy2:#042C5F;--navy3:#0B3875;--blue:#0E6FE2;--azure:#0080FF;--gold:#D3934B;--gold2:#F1C071;--ice:#F4F8FC;--ice2:#DBE7F2;--ink:#080A0F;--muted:#485366;--slate:#5E7A9D}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:Manrope,system-ui,sans-serif;color:var(--ink);background:#fff;line-height:1.5;overflow-x:hidden}
img{max-width:100%;display:block}
a{color:inherit;text-decoration:none}
.wrap{width:min(1180px,100% - 40px);margin-inline:auto}
.btn{display:inline-flex;align-items:center;gap:10px;padding:14px 26px;border-radius:999px;font-weight:700;font-size:15px;transition:transform .15s,box-shadow .15s;border:2px solid transparent}
.btn:hover{transform:translateY(-1px)}
.btn-gold{background:linear-gradient(90deg,#C9862D,#F3C66E,#D89C3B);color:var(--navy);box-shadow:0 8px 24px rgba(211,147,75,.35)}
.btn-outline{border-color:var(--navy3);color:var(--navy3);background:#fff}
.btn-white{border-color:#fff;color:#fff}
.eyebrow{letter-spacing:.22em;font-size:11px;font-weight:700;color:var(--slate)}
h1,h2,h3{font-weight:800;color:var(--navy);line-height:1.1}
h2{font-size:clamp(26px,3.4vw,36px)}
.sub{color:var(--muted);margin-top:8px}
.decor{position:absolute;pointer-events:none;user-select:none}
/* nav */
header{position:sticky;top:0;z-index:50;background:var(--navy);color:#fff}
.nav{display:flex;align-items:center;gap:28px;height:64px}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:22px}
.brand img{width:38px;height:38px;border-radius:9px}
.brand span{color:var(--azure)}
.nav ul{display:flex;gap:22px;list-style:none;font-weight:600;font-size:14px;opacity:.92}
.nav .spacer{flex:1}
.nav .btn{padding:10px 20px;font-size:14px}
.menu-btn{display:none;background:none;border:0;color:#fff;font-size:26px}
/* hero */
.hero{position:relative;overflow:hidden;background:radial-gradient(120% 90% at 100% 0%,#DCEBFB 0%,#F4F8FC 45%,#fff 70%)}
.hero .wrap{display:grid;grid-template-columns:1.1fr .9fr;gap:40px;align-items:center;padding:56px 0 48px;position:relative;z-index:2}
.hero h1{font-size:clamp(34px,4.6vw,58px)}
.hero h1 .accent{color:var(--blue)}
.hero .lede{color:var(--muted);font-size:17px;max-width:560px;margin:18px 0 26px}
.hero .cta{display:flex;gap:14px;flex-wrap:wrap}
.trust{display:flex;gap:26px;flex-wrap:wrap;margin-top:28px;font-size:13px;font-weight:600;color:var(--navy3)}
.trust li{list-style:none;display:flex;align-items:center;gap:8px}
.trust svg{width:18px;height:18px}
.hero-art{position:relative;min-height:520px}
.hero-art .map{position:absolute;right:120px;top:-10px;width:520px;opacity:.95}
.hero-art .star{position:absolute;right:240px;top:150px;width:110px}
.hero-art .pin{position:absolute;right:330px;top:190px;width:70px}
.hero-art .safer{position:absolute;right:120px;top:-30px;width:190px;transform:rotate(-8deg)}
.phone{position:absolute;right:0;top:20px;width:290px;border-radius:38px;background:#111;padding:10px;box-shadow:0 30px 60px rgba(3,28,68,.35)}
.phone .screen{border-radius:30px;overflow:hidden;background:#fff;height:600px;font-size:12px}
.phone .top{background:linear-gradient(160deg,var(--navy),var(--navy3));color:#fff;padding:26px 18px 22px}
.phone .top .brand{font-size:16px}
.phone .top .brand img{width:28px;height:28px}
.phone .top h4{font-size:19px;font-weight:800;margin-top:12px;line-height:1.15}
.phone .search{margin-top:14px;background:#fff;border-radius:10px;padding:10px 12px;color:#8A98A6}
.phone .tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:16px}
.phone .tile{background:var(--ice);border-radius:12px;padding:12px 6px;text-align:center;font-weight:700;color:var(--navy3);font-size:11px}
.phone .tile b{display:block;font-size:22px;margin-bottom:4px}
.phone .tabs{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:space-around;padding:12px 0 16px;border-top:1px solid var(--ice2);background:#fff;color:#8A98A6;font-size:10px;font-weight:700}
.phone .tabs .on{color:var(--blue)}
.side-tag{position:absolute;right:-8px;top:200px;writing-mode:vertical-rl;font-size:11px;letter-spacing:.24em;font-weight:700;color:var(--slate)}
/* sections */
section{padding:64px 0;position:relative}
.explore .cats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:16px;margin-top:28px}
.cat{text-align:center;padding:18px 8px;border-radius:16px;transition:background .15s}
.cat:hover{background:var(--ice)}
.cat .ic{width:52px;height:52px;margin:0 auto 10px;display:grid;place-items:center;color:var(--blue)}
.cat .ic svg{width:34px;height:34px}
.cat b{display:block;color:var(--navy);font-size:14px;line-height:1.2}
.providers .logos{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-top:26px}
.logo{border:1px solid var(--ice2);border-radius:14px;padding:18px 12px;text-align:center;font-weight:800;color:var(--navy3);background:#fff;letter-spacing:.02em}
.logo small{display:block;font-weight:600;color:var(--slate);font-size:11px;margin-top:4px}
.section-head{display:flex;justify-content:space-between;align-items:end;gap:20px;flex-wrap:wrap}
.link{color:var(--blue);font-weight:700}
.how{background:linear-gradient(180deg,#F4F8FC,#fff)}
.how .steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:26px;margin-top:34px}
.step{display:flex;gap:14px}
.step .n{width:40px;height:40px;border-radius:50%;background:var(--ice2);color:var(--navy3);font-weight:800;display:grid;place-items:center;flex:none}
.step h3{font-size:17px;margin-bottom:4px}
.step p{color:var(--muted);font-size:14px}
.pocket{overflow:hidden}
.pocket .wrap{display:grid;grid-template-columns:.9fr 1.1fr;gap:40px;align-items:center}
.pocket .feats{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin:26px 0}
.pocket .feats b{display:block;color:var(--navy);font-size:15px}
.pocket .feats p{color:var(--muted);font-size:13px}
.stores{display:flex;gap:12px;flex-wrap:wrap}
.store{display:inline-flex;align-items:center;gap:10px;background:#111;color:#fff;border-radius:10px;padding:10px 16px;font-weight:700;font-size:14px}
.store small{display:block;font-weight:500;font-size:10px;opacity:.8}
.pocket .script{font-family:Caveat,cursive;font-size:36px;color:var(--navy);line-height:1;transform:rotate(-6deg);display:inline-block;margin-top:20px}
.pocket .goldline{width:220px;margin-top:-6px}
.pocket .art{position:relative;min-height:480px}
.pocket .art .phone{left:0;right:auto;top:0}
.ecosystem{background:linear-gradient(90deg,var(--navy),var(--navy2) 60%,#0759A8);color:#fff;overflow:hidden}
.ecosystem .wrap{display:grid;grid-template-columns:1fr 2fr;gap:40px;align-items:center;position:relative;z-index:2}
.ecosystem h2{color:#fff}
.ecosystem .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
.ecosystem .stat{border-left:1px solid rgba(255,255,255,.25);padding-left:22px}
.ecosystem .stat b{display:block;font-size:40px;font-weight:800}
.ecosystem .stat span{opacity:.85;font-size:14px}
.ecosystem .bgmap{position:absolute;left:-60px;top:-40px;width:420px;opacity:.55}
.partner{background:var(--ice);padding:28px 0}
.partner .wrap{display:flex;align-items:center;gap:22px;flex-wrap:wrap}
.partner h3{font-size:20px}
.partner p{color:var(--muted);font-size:14px}
.partner .btn{margin-left:auto}
.final{text-align:center;position:relative;overflow:hidden}
.final .wave{position:absolute;left:0;right:0;bottom:-40px;width:100%;opacity:.6}
.final .wrap{position:relative;z-index:2}
footer{background:#fff;border-top:1px solid var(--ice2);padding:44px 0 28px;font-size:14px}
footer .cols{display:grid;grid-template-columns:1.4fr repeat(3,1fr) 1fr;gap:28px}
footer h4{font-size:14px;margin-bottom:10px;color:var(--navy)}
footer ul{list-style:none}
footer li{margin:6px 0;color:var(--muted)}
footer .copy{margin-top:30px;color:var(--slate);font-size:12px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px}
@media(max-width:960px){
 .nav ul,.nav .btn-outline{display:none}.menu-btn{display:block}
 .hero .wrap,.pocket .wrap,.ecosystem .wrap{grid-template-columns:1fr}
 .hero-art{min-height:640px}.hero-art .map{right:auto;left:-40px;width:420px}.hero-art .safer{right:10px}.phone{right:10px}
 .pocket .art{min-height:640px}
 .ecosystem .stats{grid-template-columns:1fr}
 footer .cols{grid-template-columns:1fr 1fr}
}
@media(max-width:600px){.phone{width:250px}.phone .screen{height:520px}.pocket .feats{grid-template-columns:1fr}.partner .btn{margin-left:0}footer .cols{grid-template-columns:1fr}}
</style>
</head>
<body>

<header><div class="wrap nav">
  <a class="brand" href="/"><img src="/landing/app-icon.png" alt="">Opes<span>Insure</span></a>
  <ul><li><a href="#explore">Insurance</a></li><li><a href="#providers">Providers</a></li><li><a href="#how">How it works</a></li><li><a href="#app">Mobile app</a></li><li><a href="/docs/api">API</a></li></ul>
  <span class="spacer"></span>
  <a class="btn btn-white btn-outline" href="/admin/login" style="color:#fff;border-color:#fff;background:transparent">Sign in</a>
  <a class="btn btn-gold" href="/download">Download the app →</a>
  <button class="menu-btn" aria-label="Menu" onclick="document.querySelector('.nav ul').style.display=document.querySelector('.nav ul').style.display==='flex'?'none':'flex'">☰</button>
</div></header>

<section class="hero">
  <img class="decor" src="/landing/pattern-left.png" alt="" style="left:-60px;top:0;height:100%;opacity:.35">
  <img class="decor" src="/landing/blue-sweep.png" alt="" style="right:0;top:0;width:60%;opacity:.5">
  <div class="wrap">
    <div>
      <span class="eyebrow">PEOPLE · PROTECTION · A BRIGHTER TOMORROW</span>
      <h1>Insurance from trusted providers. <span class="accent">One place. Anywhere. Anytime.</span></h1>
      <p class="lede">Compare insurance options from multiple insurance companies, brokers and agents. Buy securely, manage your policies, make payments, renew cover, and follow claims — all from one app.</p>
      <div class="cta"><a class="btn btn-gold" href="/download">Compare insurance →</a><a class="btn btn-outline" href="/download">Get covered</a></div>
      <ul class="trust">
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4z"/><path d="m9 12 2 2 4-4"/></svg>Licensed providers</li>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Secure payments</li>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>Verified products</li>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>Regulated &amp; trusted</li>
      </ul>
    </div>
    <div class="hero-art">
      <img class="map" src="/landing/map-connected.png" alt="">
      <img class="star" src="/landing/star.png" alt="">
      <img class="pin" src="/landing/pin-cameroon.png" alt="Cameroon">
      <img class="safer" src="/landing/safer-brighter-africa.png" alt="A Safer Brighter Africa">
      <div class="phone"><div class="screen">
        <div class="top"><div class="brand"><img src="/landing/app-icon.png" alt="">Opes<span>Insure</span></div><h4>Your protection<br>in your hands</h4><div class="search">Search insurance…</div></div>
        <div class="tiles"><div class="tile"><b>🚗</b>Motor</div><div class="tile"><b>❤️</b>Health</div><div class="tile"><b>✈️</b>Travel</div><div class="tile"><b>🏠</b>Home</div><div class="tile"><b>🏢</b>Business</div><div class="tile"><b>🛡️</b>Life</div></div>
        <div class="tabs"><span class="on">Home</span><span>Compare</span><span>Policies</span><span>Claims</span><span>Account</span></div>
      </div></div>
      <div class="side-tag">MORE CHOICE · MORE PEOPLE · A BRIGHTER TOMORROW</div>
    </div>
  </div>
</section>

<section class="explore" id="explore"><div class="wrap">
  <h2>Explore insurance</h2><p class="sub">Find and compare the best insurance plans from trusted providers.</p>
  <div class="cats">
    @foreach([['Motor','M5 17h14M7 17V9l2-4h6l2 4v8M7 17a2 2 0 1 0 4 0M13 17a2 2 0 1 0 4 0'],['Health','M12 21s-8-5.5-8-11a4.5 4.5 0 0 1 8-2.5A4.5 4.5 0 0 1 20 10c0 5.5-8 11-8 11z'],['Travel','M2 16l20-8-8 12-2-6-6-2z'],['Home','M3 11l9-8 9 8M5 10v10h14V10'],['Business','M3 7h18v13H3zM8 7V4h8v3'],['Life','M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4z'],['Accident','M12 9v4M12 17h.01M5 20h14L12 4z']] as [$name,$d])
    <a class="cat" href="/download"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $d }}"/></svg></div><b>{{ $name }}<br>insurance</b></a>
    @endforeach
  </div>
</div></section>

<section class="providers" id="providers"><div class="wrap">
  <div class="section-head"><div><h2>Trusted insurance providers</h2><p class="sub">Compare plans from leading insurance companies, brokers and agents in Cameroon and beyond.</p></div><a class="link" href="/download">View all providers →</a></div>
  <div class="logos">
    @foreach(['Chanas Assurances','SanlamAllianz','AXA Cameroun','ACTIVA Assurances','NSIA Assurances','SAAR Assurances','SUNU Assurances','Zenithe Insurance'] as $p)
      <div class="logo">{{ $p }}<small>ASAC member</small></div>
    @endforeach
  </div>
</div></section>

<section class="how" id="how"><div class="wrap" style="text-align:center">
  <h2>How OpesInsure works</h2><p class="sub">Get insured in just a few simple steps.</p>
  <div class="steps" style="text-align:left">
    <div class="step"><div class="n">1</div><div><h3>Choose cover</h3><p>Browse and select the insurance you need.</p></div></div>
    <div class="step"><div class="n">2</div><div><h3>Compare offers</h3><p>Compare live plans from multiple providers.</p></div></div>
    <div class="step"><div class="n">3</div><div><h3>Buy online</h3><p>Pay securely by mobile money and get your policy instantly.</p></div></div>
    <div class="step"><div class="n">4</div><div><h3>Manage &amp; claim</h3><p>Access your policy, renew or file a claim anytime.</p></div></div>
  </div>
</div></section>

<section class="pocket" id="app">
  <img class="decor" src="/landing/wave-light.png" alt="" style="left:0;bottom:0;width:100%;opacity:.7">
  <div class="wrap">
    <div class="art">
      <div class="phone"><div class="screen">
        <div class="top" style="height:100%;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center"><img src="/landing/app-icon.png" alt="" style="width:110px;border-radius:26px"><h4 style="font-size:26px;margin-top:22px">Opes<span style="color:var(--azure)">Insure</span></h4><p style="opacity:.9;margin-top:10px">Insurance. Anywhere. Anytime.</p><img src="/landing/gold-stroke.png" alt="" style="width:160px;margin-top:14px"></div>
      </div></div>
    </div>
    <div>
      <h2>Your insurance<br>in your pocket</h2>
      <p class="sub">Buy, manage, renew and claim on the go with the OpesInsure mobile app. Available anywhere, anytime.</p>
      <div class="feats"><div><b>Manage policies</b><p>All your policies in one place.</p></div><div><b>Renew instantly</b><p>Stay covered without interruption.</p></div><div><b>File &amp; track claims</b><p>Submit and track your claims easily.</p></div></div>
      <div class="stores"><a class="store" href="/download/android">▶ <span><small>Android</small>Download APK</span></a><a class="store" href="/download" style="background:#333"> <span><small>Coming soon</small>App Store</span></a></div>
      <span class="script">Same Protection.<br>More Freedom.</span><img class="goldline" src="/landing/gold-stroke.png" alt="">
    </div>
  </div>
</section>

<section class="ecosystem">
  <img class="decor bgmap" src="/landing/map-dark.png" alt="">
  <div class="wrap">
    <h2>A connected insurance ecosystem for a stronger Africa.</h2>
    <div class="stats"><div class="stat"><b>{{ $carriers }}+</b><span>Insurance providers</span></div><div class="stat"><b>{{ $lines }}</b><span>Insurance lines</span></div><div class="stat"><b>{{ $products }}+</b><span>Live products to compare</span></div></div>
  </div>
</section>

<section class="partner"><div class="wrap">
  <div><h3>Are you an insurer, broker or agent?</h3><p>Join the OpesInsure marketplace and reach more customers.</p></div>
  <a class="btn btn-gold" href="mailto:partners@opesdatacenter.tech">Partner with us →</a>
</div></section>

<section class="final">
  <img class="wave" src="/landing/wave-gold.png" alt="">
  <div class="wrap"><h2>Find the right cover today.</h2><p class="sub">Compare plans from trusted providers. Buy. Stay protected. Anywhere. Anytime.</p><p style="margin-top:22px"><a class="btn btn-gold" href="/download">Compare insurance →</a></p></div>
</section>

<footer><div class="wrap">
  <div class="cols">
    <div><a class="brand" href="/" style="color:var(--navy)"><img src="/landing/app-icon.png" alt="">Opes<span>Insure</span></a><p style="color:var(--muted);margin-top:10px">Protection for a brighter tomorrow.</p></div>
    <div><h4>Insurance</h4><ul><li>Motor</li><li>Health</li><li>Travel</li><li>Home</li><li>Business</li><li>Life</li></ul></div>
    <div><h4>Company</h4><ul><li><a href="#how">How it works</a></li><li><a href="#providers">Our providers</a></li><li><a href="mailto:partners@opesdatacenter.tech">Become a partner</a></li><li><a href="/docs/api">API</a></li></ul></div>
    <div><h4>Support</h4><ul><li><a href="/download">Download the app</a></li>@if(config('demo.enabled'))<li><a href="/demo">Demo accounts</a></li>@endif<li><a href="/admin">Partner portal</a></li></ul></div>
    <div><h4>Regions</h4><ul><li>Cameroon</li><li>Africa</li><li>The world</li></ul></div>
  </div>
  <div class="copy"><span>© {{ date('Y') }} OpesInsure · Opesware Technologies. All rights reserved.</span><span>PEOPLE · PROTECTION · A BRIGHTER TOMORROW</span></div>
</div></footer>
</body>
</html>
