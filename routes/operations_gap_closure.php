<?php

declare(strict_types=1);

use App\Application\OperationsTaxonomy\Http\OperationsTaxonomyController as O;
use App\Application\PrivateOnboarding\Http\PrivateOnboardingController as P;
use Illuminate\Support\Facades\Route;

/*
 | Gap Closure Pack v1 files 10 (operations taxonomies / gates) and 11 (private tenant onboarding templates).
 | Loaded by App\Application\OperationsTaxonomy\OperationsGapClosureServiceProvider.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('operations/taxonomy', [O::class, 'index'])->middleware('permission:operations.taxonomy.read');
    Route::post('operations/cases/{case}/escalate', [O::class, 'escalate'])->middleware('permission:operations.cases.escalate')->whereUuid('case');
    Route::put('operations/queues/{queue}/queue-type', [O::class, 'queueType'])->middleware('permission:operations.taxonomy.manage')->whereUuid('queue');
    Route::get('operations/notification-templates', [O::class, 'notificationTemplates'])->middleware('permission:operations.taxonomy.read');
    Route::post('operations/notification-templates/{template}/approve', [O::class, 'approveTemplate'])->middleware('permission:operations.notification_templates.approve')->whereUuid('template');

    Route::get('onboarding/private-datasets', [P::class, 'datasets'])->middleware('permission:onboarding.private_data.view');
    Route::get('onboarding/private-datasets/{dataset}/template', [P::class, 'template'])->middleware('permission:onboarding.private_data.view');
    Route::get('onboarding/private-records', [P::class, 'records'])->middleware('permission:onboarding.private_data.view');
    Route::post('onboarding/private-records/{record}/review', [P::class, 'review'])->middleware('permission:onboarding.private_data.review')->whereUuid('record');
    Route::get('onboarding/private-readiness', [P::class, 'readiness'])->middleware('permission:onboarding.private_data.view');
});
