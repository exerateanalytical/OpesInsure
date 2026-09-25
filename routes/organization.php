<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Organization\FeatureFlagController;
use App\Interfaces\Http\Controllers\Api\V1\Organization\OrganizationStructureController;
use App\Interfaces\Http\Controllers\Api\V1\Organization\TimezoneSettingsController;
use Illuminate\Support\Facades\Route;

/*
 | Batch 2 / 2D — organization structure, timezone settings, feature flags
 | (REQ-TEN-001/002, REQ-ORG-001, REQ-SEC-004, REQ-TMP-003).
 | Loaded by App\Providers\OrganizationServiceProvider under the `api` group.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('settings/timezones', [TimezoneSettingsController::class, 'catalogue']);
    Route::get('me/settings', [TimezoneSettingsController::class, 'me']);
    Route::patch('me/settings', [TimezoneSettingsController::class, 'updateMe']);

    Route::get('organization/settings', [TimezoneSettingsController::class, 'organization']);
    Route::patch('organization/settings', [TimezoneSettingsController::class, 'updateOrganization'])->middleware('permission:tenant.manage');
    Route::get('organization/branches/tree', [OrganizationStructureController::class, 'branchTree'])->middleware('permission:tenant.manage');
    Route::patch('organization/branches/{branch}', [OrganizationStructureController::class, 'updateBranch'])->middleware('permission:tenant.manage')->whereUuid('branch');
    Route::get('organization/departments', [OrganizationStructureController::class, 'departments'])->middleware('permission:tenant.manage');
    Route::post('organization/departments', [OrganizationStructureController::class, 'storeDepartment'])->middleware('permission:tenant.manage');

    Route::patch('partners/{partner}/hierarchy', [OrganizationStructureController::class, 'placeAgent'])->middleware('permission:partners.manage')->whereUuid('partner');
    Route::post('partners/{partner}/suspension', [OrganizationStructureController::class, 'suspendAgent'])->middleware('permission:partners.manage')->whereUuid('partner');
    Route::get('partners/{partner}/downline', [OrganizationStructureController::class, 'downline'])->middleware('permission:partners.read')->whereUuid('partner');

    Route::get('feature-flags/evaluate', [FeatureFlagController::class, 'evaluate']);
    Route::get('admin/feature-flags', [FeatureFlagController::class, 'index'])->middleware('permission:tenant.manage');
    Route::put('admin/feature-flags', [FeatureFlagController::class, 'upsert'])->middleware('permission:tenant.manage');

    Route::get('admin/platform/settings/timezone', [TimezoneSettingsController::class, 'platform'])->middleware('permission:platform.settings.manage');
    Route::patch('admin/platform/settings/timezone', [TimezoneSettingsController::class, 'updatePlatform'])->middleware('permission:platform.settings.manage');
});
