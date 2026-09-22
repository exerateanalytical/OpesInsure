<?php
use App\Interfaces\Http\Controllers\Api\V1\WebExperiences\Wave10Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('web-experiences')->controller(Wave10Controller::class)->group(function (): void {
    Route::get('{portal}/dashboard', 'dashboard');
    Route::put('{portal}/workspace', 'workspace');
    Route::post('marketplace/publications', 'publish');
    Route::post('marketplace/publications/{publication}/approve', 'approve');
    Route::post('marketplace/comparisons', 'saveComparison');
});
