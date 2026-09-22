<?php
use App\Interfaces\Http\Controllers\Api\V1\Release\Wave11Controller;
use Illuminate\Support\Facades\Route;

Route::prefix('release-assurance')->controller(Wave11Controller::class)->group(function (): void {
    Route::post('candidates','create')->middleware('permission:releases.create');
    Route::post('candidates/{candidate}/gates','gate')->middleware('permission:releases.assess');
    Route::post('candidates/{candidate}/certify','certify')->middleware('permission:releases.certify');
    Route::post('security-findings','finding')->middleware('permission:releases.security-findings.create');
    Route::post('recovery-exercises','recovery')->middleware('permission:releases.recovery-exercises.create');
});
