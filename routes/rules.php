<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Rules\QuestionSetController;
use App\Interfaces\Http\Controllers\Api\V1\Rules\RuleEvaluationController;
use App\Interfaces\Http\Controllers\Api\V1\Rules\RuleSetController;
use Illuminate\Support\Facades\Route;

/*
 | Batch 5B — REQ-RUL-001…004 / REQ-DUP-020. Loaded by App\Providers\RulesServiceProvider.
 | Rule sets + question sets are maker-checker configuration (approval_requests rule_set.approve / question_set.approve).
 | Mobile keeps GET /v1/mobile/catalogue/lines/{code}/risk-schema (same response), now served from question_sets.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::middleware('permission:rules.view')->group(function (): void {
        Route::get('rule-sets', [RuleSetController::class, 'index']);
        Route::get('rule-sets/{ruleSet}', [RuleSetController::class, 'show'])->whereUuid('ruleSet');
        Route::post('rule-sets/{ruleSet}/simulate', [RuleSetController::class, 'simulate'])->whereUuid('ruleSet');
        Route::get('question-sets', [QuestionSetController::class, 'index']);
        Route::get('question-sets/{questionSet}', [QuestionSetController::class, 'show'])->whereUuid('questionSet');
        Route::get('products/{product}/questionnaire', [QuestionSetController::class, 'questionnaire'])->whereUuid('product');
    });
    Route::middleware('permission:rules.manage')->group(function (): void {
        Route::post('rule-sets', [RuleSetController::class, 'store']);
        Route::post('rule-sets/validate-expression', [RuleSetController::class, 'validateExpression']);
        Route::post('rule-sets/{ruleSet}/submit', [RuleSetController::class, 'submit'])->whereUuid('ruleSet');
        Route::post('question-sets', [QuestionSetController::class, 'store']);
        Route::post('question-sets/{questionSet}/submit', [QuestionSetController::class, 'submit'])->whereUuid('questionSet');
    });
    Route::middleware('permission:rules.approve')->group(function (): void {
        Route::post('rule-sets/{ruleSet}/approve', [RuleSetController::class, 'approve'])->whereUuid('ruleSet');
        Route::post('rule-sets/{ruleSet}/reject', [RuleSetController::class, 'reject'])->whereUuid('ruleSet');
        Route::post('rule-sets/{ruleSet}/retire', [RuleSetController::class, 'retire'])->whereUuid('ruleSet');
        Route::post('question-sets/{questionSet}/approve', [QuestionSetController::class, 'approve'])->whereUuid('questionSet');
        Route::post('question-sets/{questionSet}/reject', [QuestionSetController::class, 'reject'])->whereUuid('questionSet');
    });
    Route::middleware('permission:rules.evaluate')->group(function (): void {
        Route::post('insurance/eligibility/check', [RuleEvaluationController::class, 'eligibility']);
        Route::post('insurance/completeness/check', [RuleEvaluationController::class, 'completeness']);
    });
});
