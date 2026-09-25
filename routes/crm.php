<?php

declare(strict_types=1);

use App\Application\Attribution\Http\PortfolioController;
use App\Application\Customers\Beneficiaries\Http\BeneficiaryController;
use App\Application\PartnerWorkspace\Http\LeadDirectoryController;
use Illuminate\Support\Facades\Route;

/*
 | Batch 4C — CRM (REQ-CRM-001, REQ-CRM-003, REQ-CRM-004).
 | Loaded by App\Providers\CrmServiceProvider under the `api` group.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    // REQ-CRM-001 lead directory / assignment / activities
    Route::get('crm/leads', [LeadDirectoryController::class, 'index'])->middleware('permission:crm.leads.read');
    Route::get('crm/leads/{lead}', [LeadDirectoryController::class, 'show'])->middleware('permission:crm.leads.read');
    Route::get('crm/leads/{lead}/activities', [LeadDirectoryController::class, 'activities'])->middleware('permission:crm.leads.read');
    Route::post('crm/leads', [LeadDirectoryController::class, 'store'])->middleware(['permission:crm.leads.manage', 'throttle:60,1']);
    Route::post('crm/leads/{lead}/transitions', [LeadDirectoryController::class, 'transition'])->middleware('permission:crm.leads.manage');
    Route::post('crm/leads/{lead}/activities', [LeadDirectoryController::class, 'addActivity'])->middleware('permission:crm.leads.manage');
    Route::post('crm/leads/{lead}/assign', [LeadDirectoryController::class, 'assign'])->middleware('permission:crm.leads.assign');

    // Agent mobile: activities / follow-ups on the agent's own leads (same diary)
    Route::get('mobile/partner/agent/leads/{lead}/activities', [LeadDirectoryController::class, 'agentActivities'])->middleware('permission:agent.clients.read');
    Route::post('mobile/partner/agent/leads/{lead}/activities', [LeadDirectoryController::class, 'agentAddActivity'])->middleware(['permission:agent.clients.manage', 'throttle:30,1']);

    // REQ-CRM-003 attribution: portfolio transfer, ownership history, commission-ready policy attribution
    Route::post('crm/portfolio-transfers/preview', [PortfolioController::class, 'preview'])->middleware('permission:attribution.transfer');
    Route::post('crm/portfolio-transfers', [PortfolioController::class, 'execute'])->middleware(['permission:attribution.transfer', 'throttle:10,1']);
    Route::get('crm/portfolio-transfers', [PortfolioController::class, 'index'])->middleware('permission:attribution.read');
    Route::get('crm/portfolio-transfers/{transfer}', [PortfolioController::class, 'show'])->middleware('permission:attribution.read');
    Route::get('crm/customers/{party}/attribution-history', [PortfolioController::class, 'customerHistory'])->middleware('permission:attribution.read');
    Route::get('crm/policies/{policy}/attribution', [PortfolioController::class, 'policyAttribution'])->middleware('permission:attribution.read');

    // REQ-CRM-004 beneficiaries
    Route::get('policies/{policy}/beneficiaries', [BeneficiaryController::class, 'index'])->middleware('permission:beneficiaries.read');
    Route::get('policies/{policy}/beneficiaries/history', [BeneficiaryController::class, 'history'])->middleware('permission:beneficiaries.read');
    Route::put('policies/{policy}/beneficiaries', [BeneficiaryController::class, 'replace'])->middleware('permission:beneficiaries.manage');
});
