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
