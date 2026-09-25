<?php
use App\Interfaces\Http\Controllers\Api\V1\Logistics\FulfilmentController;
use App\Interfaces\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Interfaces\Http\Controllers\Api\V1\Support\SupportController;
use Illuminate\Support\Facades\Route;
// REQ-DUP-002: canonical is fulfilment-orders (routes/api.php); these are deprecated aliases.
Route::post('fulfilments',[FulfilmentController::class,'store'])->middleware(['permission:fulfilments.manage', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('fulfilment-orders','REQ-DUP-002')]);
Route::post('fulfilments/courier-availability',[FulfilmentController::class,'availability'])->middleware('permission:fulfilments.manage');
Route::post('fulfilments/{order}/transitions',[FulfilmentController::class,'transition'])->middleware(['permission:fulfilments.manage', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('fulfilment-orders/{order}/transitions','REQ-DUP-002')]);
// REQ-DUP-003: canonical is communication-preferences (routes/api.php).
Route::put('communications/preferences',[NotificationController::class,'preference'])->middleware(['permission:communications.manage', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('communication-preferences','REQ-DUP-003')]);
Route::post('notification-templates',[NotificationController::class,'template'])->middleware('permission:communications.manage');
Route::post('notification-templates/{template}/approve',[NotificationController::class,'approveTemplate'])->middleware('permission:communications.approve');
Route::post('notifications',[NotificationController::class,'queue'])->middleware('permission:communications.manage');
Route::post('notifications/{d}/retry',[NotificationController::class,'retry'])->middleware('permission:communications.manage');
Route::post('notifications/{d}/cancel',[NotificationController::class,'cancel'])->middleware('permission:communications.manage');
// REQ-DUP-001: canonical is support-tickets (routes/api.php).
Route::post('support/tickets',[SupportController::class,'store'])->middleware(['permission:support.manage', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('support-tickets','REQ-DUP-001')]);
Route::post('support/tickets/{ticket}/transitions',[SupportController::class,'transition'])->middleware(['permission:support.manage', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('support-tickets/{ticket}/transitions','REQ-DUP-001')]);
Route::post('communications/logs',[SupportController::class,'communication'])->middleware('permission:communications.manage');
