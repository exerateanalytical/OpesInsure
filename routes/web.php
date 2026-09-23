<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('public.home'))->name('home');

Route::get('/download', fn () => view('public.download', [
    'version' => config('mobile_app.version'),
    'android' => config('mobile_app.android_url') ?: config('mobile_app.play_store_url'),
    'ios' => config('mobile_app.ios_url') ?: config('mobile_app.app_store_url'),
    'androidSize' => config('mobile_app.android_size'),
    'minAndroid' => config('mobile_app.min_android'),
    'minIos' => config('mobile_app.min_ios'),
]))->name('download');

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
