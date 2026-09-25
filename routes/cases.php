<?php

declare(strict_types=1);

use App\Application\Cases\Http\CaseAdminController;
use App\Application\Cases\Http\CaseConfigurationController;
use App\Application\Cases\Http\CaseController;
use Illuminate\Support\Facades\Route;

/*
 | REQ-CAS-001 / REQ-CAL-001 — Case, task, diary & SLA engine (ICE E6 §6.9).
 | Loaded by App\Providers\CasesServiceProvider under the `api` group.
 | Confidentiality (NORMAL / RESTRICTED / STR_RESTRICTED) is filtered at the
 | query layer inside WorkCase, on top of these route permissions.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('me/work', [CaseController::class, 'myWork']);

    Route::middleware('permission:cases.view')->group(function (): void {
        Route::get('cases', [CaseController::class, 'index']);
        Route::get('cases/{case}', [CaseController::class, 'show'])->whereUuid('case');
        Route::get('cases/{case}/events', [CaseController::class, 'events'])->whereUuid('case');
        Route::get('cases/{case}/tasks', [CaseController::class, 'tasks'])->whereUuid('case');
        Route::get('cases/{case}/decisions', [CaseController::class, 'decisions'])->whereUuid('case');
        Route::get('cases/{case}/diary', [CaseController::class, 'diary'])->whereUuid('case');
        Route::get('queues', [CaseController::class, 'queues']);
        Route::get('case-types', [CaseController::class, 'caseTypes']);
        Route::get('case-families', [CaseConfigurationController::class, 'families']);
        Route::get('cases/{case}/sla-policies', [CaseConfigurationController::class, 'slaPolicies'])->whereUuid('case');
    });

    Route::middleware('permission:cases.manage')->group(function (): void {
        Route::post('cases', [CaseController::class, 'store']);
        Route::post('cases/{case}/transitions', [CaseController::class, 'transition'])->whereUuid('case');
        Route::post('cases/{case}/tasks', [CaseController::class, 'addTask'])->whereUuid('case');
        Route::post('cases/{case}/tasks/{task}/transitions', [CaseController::class, 'transitionTask'])->whereUuid('case')->whereUuid('task');
        Route::post('cases/{case}/diary', [CaseController::class, 'addDiary'])->whereUuid('case');
        Route::post('queues/{queue}/next', [CaseController::class, 'next'])->whereUuid('queue');
        Route::post('cases/{case}/subtype', [CaseConfigurationController::class, 'reclassify'])->whereUuid('case');
    });
    Route::post('cases/{case}/assign', [CaseController::class, 'assign'])->whereUuid('case')->middleware('permission:cases.assign');
    Route::post('cases/{case}/decisions', [CaseController::class, 'decide'])->whereUuid('case')->middleware('permission:cases.decide');

    Route::prefix('admin')->group(function (): void {
        Route::middleware('permission:cases.admin')->group(function (): void {
            Route::post('case-types', [CaseAdminController::class, 'draftType']);
            Route::post('case-types/{type}/approve', [CaseAdminController::class, 'approveType'])->whereUuid('type');
            Route::post('queues', [CaseAdminController::class, 'addQueue']);
            Route::post('queues/{queue}/members', [CaseAdminController::class, 'addMember'])->whereUuid('queue');
            Route::post('cases/links', [CaseController::class, 'link']);
            Route::get('sla-overrides', [CaseConfigurationController::class, 'overrides']);
            Route::post('sla-overrides', [CaseConfigurationController::class, 'addOverride']);
            Route::post('sla-overrides/{id}/retire', [CaseConfigurationController::class, 'retireOverride'])->whereUuid('id');
        });
        Route::middleware('permission:cases.calendar.manage')->group(function (): void {
            Route::get('calendars/hours', [CaseAdminController::class, 'hours']);
            Route::post('calendars/hours', [CaseAdminController::class, 'addHours']);
            Route::post('calendars/hours/{id}/end', [CaseAdminController::class, 'endHours'])->whereUuid('id');
            Route::get('calendars/exceptions', [CaseAdminController::class, 'exceptions']);
            Route::post('calendars/exceptions', [CaseAdminController::class, 'addException']);
            Route::get('calendars/business-time', [CaseAdminController::class, 'businessTime']);
            Route::get('calendars/breaks', [CaseConfigurationController::class, 'breaks']);
            Route::post('calendars/breaks', [CaseConfigurationController::class, 'addBreak']);
            Route::post('calendars/breaks/{id}/end', [CaseConfigurationController::class, 'endBreak'])->whereUuid('id');
        });
    });
});
