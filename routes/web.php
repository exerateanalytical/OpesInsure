<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Web\PublicSiteController as Site;
use App\Interfaces\Http\Controllers\Web\PublicVerifyPageController;
use App\Interfaces\Http\Controllers\Web\SetPublicLocale;
use Illuminate\Support\Facades\Route;

// Public website (EN/FR via ?lang= remembered in the session). One canonical
// landing view: resources/views/public/landing.blade.php; every page shares
// resources/views/public/layout.blade.php.
Route::middleware(SetPublicLocale::class)->group(function (): void {
    Route::get('/', [Site::class, 'home'])->name('home');

    Route::get('/about', [Site::class, 'about'])->name('public.about');
    Route::get('/how-it-works', [Site::class, 'howItWorks'])->name('public.how-it-works');
    Route::get('/claims', [Site::class, 'claims'])->name('public.claims');
    Route::get('/faq', [Site::class, 'faq'])->name('public.faq');
    Route::get('/privacy', [Site::class, 'privacy'])->name('public.privacy');
    Route::get('/terms', [Site::class, 'terms'])->name('public.terms');
    Route::get('/partners', [Site::class, 'partners'])->name('public.partners');

    // Partner self-service application (insurer / broker / agent) and "Claim this organisation" on the public directory.
    // Anonymous, throttled, honeypot; later steps only through the applicant's secret status link (token hashed in the DB).
    Route::controller(\App\Interfaces\Http\Controllers\Web\PartnerApplicationPageController::class)->group(function (): void {
        Route::get('/partners/apply', 'form')->middleware('throttle:120,1')->name('public.partner-apply');
        Route::post('/partners/apply', 'submit')->middleware('throttle:5,10')->name('public.partner-apply.submit');
        Route::get('/partners/apply/status/{token}', 'status')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:120,1')->name('public.partner-apply.status');
        Route::post('/partners/apply/status/{token}/verify', 'verify')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('public.partner-apply.verify');
        Route::post('/partners/apply/status/{token}/resend', 'resend')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:3,1')->name('public.partner-apply.resend');
        Route::post('/partners/apply/status/{token}/respond', 'respond')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:5,1')->name('public.partner-apply.respond');
        Route::get('/partners/apply/brokerage/{token}', 'brokerage')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:120,1')->name('public.partner-apply.brokerage');
        Route::post('/partners/apply/brokerage/{token}', 'brokerageAnswer')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('public.partner-apply.brokerage.answer');
    });
    Route::controller(\App\Interfaces\Http\Controllers\Web\OrganisationClaimPageController::class)->group(function (): void {
        Route::get('/organisations/claim/status/{token}', 'status')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:120,1')->name('public.org-claim.status');
        Route::post('/organisations/claim/status/{token}/verify', 'verify')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('public.org-claim.verify');
        Route::post('/organisations/claim/status/{token}/resend', 'resend')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:3,1')->name('public.org-claim.resend');
        Route::post('/organisations/claim/status/{token}/respond', 'respond')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:5,1')->name('public.org-claim.respond');
        Route::get('/organisations/claim/{kind}/{id}', 'form')->where(['kind' => 'insurer|broker', 'id' => '[0-9a-fA-F-]{36}'])->middleware('throttle:120,1')->name('public.org-claim');
        Route::post('/organisations/claim/{kind}/{id}', 'submit')->where(['kind' => 'insurer|broker', 'id' => '[0-9a-fA-F-]{36}'])->middleware('throttle:5,10')->name('public.org-claim.submit');
    });

    // Marketplace hub, one directory per insurance line, and side-by-side compare.
    Route::get('/insurance', [Site::class, 'marketplace'])->name('public.marketplace');
    Route::get('/insurance/{line}', [Site::class, 'directory'])->where('line', 'motor|health|travel|home|business|life|accident')->name('public.directory');
    Route::get('/compare', [Site::class, 'compare'])->name('public.compare');

    // Website sign-in / sign-up: same customer accounts as the app (api/v1 auth/mobile/*, public/accounts).
    Route::get('/login', [Site::class, 'login'])->name('public.login');
    Route::get('/signup', [Site::class, 'signup'])->name('public.signup');
    // Contract acceptance for a customer without the app (agent/broker sale): signed, expiring, single-use link sent by
    // SMS + OTP to the proposal's phone; no sign-in (ProposalAcceptanceLinks). The signature is checked in the controller.
    Route::get('/account/accept/{token}', [\App\Interfaces\Http\Controllers\Web\ProposalAcceptancePageController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{32,64}')->middleware('throttle:120,1')->name('public.acceptance.show');
    Route::post('/account/accept/{token}', [\App\Interfaces\Http\Controllers\Web\ProposalAcceptancePageController::class, 'act'])
        ->where('token', '[A-Za-z0-9]{32,64}')->middleware('throttle:10,1')->name('public.acceptance.act');
    // Signed-in account area (customer, agent/broker and claims-officer screens). Pages are
    // static shells; their data comes from api/v1 with the bearer token from /login.
    Route::get('/account/{path?}', [Site::class, 'account'])->where('path', '(?!delete$|accept/)[A-Za-z0-9/_-]*')->name('public.account');
    // S11 in-app help: printable (PDF-friendly) version of one role guide, ?lang=en|fr.
    Route::get('/help/{guide}/print', \App\Interfaces\Http\Controllers\Web\HelpGuidePrintController::class)->where('guide', '[a-z_]+')->name('help.print');

    Route::get('/providers', [Site::class, 'providers'])->name('public.providers');
    Route::get('/contact', [Site::class, 'contact'])->name('public.contact');
    Route::post('/contact', [Site::class, 'submitContact'])->middleware('throttle:5,1')->name('public.contact.submit');

    // Play Store account-deletion URL (also linked from mobile app 1.3.0).
    Route::get('/account/delete', [Site::class, 'accountDelete'])->name('public.account.delete');
    Route::post('/account/delete', [Site::class, 'submitAccountDelete'])->middleware('throttle:5,1')->name('public.account.delete.submit');

    // App Links / Universal Links (https://…/app/…) land here when the app is not installed.
    Route::get('/app/{path?}', [Site::class, 'appLink'])->where('path', '.*')->name('public.app');

    Route::get('/sitemap.xml', [Site::class, 'sitemap'])->name('public.sitemap');
    Route::get('/robots.txt', [Site::class, 'robots'])->name('public.robots');

    Route::get('/download', fn () => view('public.download', [
        'version' => config('mobile_app.version'),
        'android' => config('mobile_app.android_url') ?: config('mobile_app.play_store_url'),
        'ios' => config('mobile_app.ios_url') ?: config('mobile_app.app_store_url'),
        'androidSize' => config('mobile_app.android_size'),
        'minAndroid' => config('mobile_app.min_android'),
        'minIos' => config('mobile_app.min_ios'),
    ]))->name('download');

    // Demo credential directory. The route itself only exists while demo mode
    // is enabled, so disabling the flag removes the page rather than leaving it
    // reachable and empty.
    // S13: checked per request (not at route-cache time) so demo:exit takes effect without a re-cache.
    {
        Route::get('/demo', fn () => abort_unless(config('demo.enabled'), 404) ?? view('public.demo', [
            'staff' => \Database\Seeders\DatabaseSeeder::DEMO_ACCOUNTS,
            'mobile' => \Database\Seeders\DemoMobileAccountSeeder::ACCOUNTS,
            'password' => config('demo.password'),
            'otp' => config('demo.otp'),
        ]))->name('demo');
    }

    // Certificate QR target (public, minimal disclosure, token required).
    // Page GETs: 120/min per IP and route (PerRouteThrottle) — real users browse behind shared carrier NAT (live QA #14).
    Route::get('/verify', PublicVerifyPageController::class)->middleware('throttle:120,1')->name('public.verify');
});

// Serves the Android package with the correct MIME type and an explicit .apk
// filename. Static nginx delivery falls back to application/octet-stream,
// which some Android browsers and download managers handle poorly. Uses a
// BinaryFileResponse so the 86 MB file streams (and supports range requests
// for resumable downloads) rather than being buffered in PHP memory.
Route::get('/download/android', function () {
    $path = storage_path('app/public/downloads/opesinsure-'.config('mobile_app.version').'.apk');

    abort_unless(is_file($path), 404);

    // download() already emits an attachment Content-Disposition with this
    // filename; the header override just corrects the MIME type.
    return response()->download($path, 'OpesInsure-'.config('mobile_app.version').'.apk', [
        'Content-Type' => 'application/vnd.android.package-archive',
    ]);
})->name('download.android');

// Agent B7 — REQ-MOB-007 verified app links (config security_centre.app_links). Must live at the web root, not under /api.
Route::get('/.well-known/assetlinks.json', [\App\Application\Security\AppLinks\AppLinksController::class, 'assetLinks'])->name('well-known.assetlinks');
Route::get('/.well-known/apple-app-site-association', [\App\Application\Security\AppLinks\AppLinksController::class, 'appleAppSiteAssociation'])->name('well-known.aasa');
// End Agent B7
