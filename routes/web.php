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
    if (config('demo.enabled')) {
        Route::get('/demo', fn () => view('public.demo', [
            'staff' => \Database\Seeders\DatabaseSeeder::DEMO_ACCOUNTS,
            'mobile' => \Database\Seeders\DemoMobileAccountSeeder::ACCOUNTS,
            'password' => config('demo.password'),
            'otp' => config('demo.otp'),
        ]))->name('demo');
    }

    // Certificate QR target (public, minimal disclosure, token required).
    Route::get('/verify', PublicVerifyPageController::class)->middleware('throttle:30,1')->name('public.verify');
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
