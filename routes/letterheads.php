<?php

use App\Interfaces\Http\Controllers\Api\V1\Letterheads\PublicLetterheadLogoController;
use Illuminate\Support\Facades\Route;

// Document letterheads: public logo (public-display versions only). Loaded by LetterheadServiceProvider under /api/v1.
Route::get('public/letterheads/{asset}/logo', PublicLetterheadLogoController::class)->middleware('throttle:120,1')->name('letterheads.logo');
