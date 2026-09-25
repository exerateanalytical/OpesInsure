<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Approvals\ApprovalService;
use App\Application\Rules\Approvals\QuestionSetApprovalHandler;
use App\Application\Rules\Approvals\RuleSetApprovalHandler;
use App\Application\Rules\Console\SyncQuestionSetsCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Batch 5B — REQ-RUL-001…004 / REQ-DUP-020: routes/rules.php, approval handlers, rules:sync-question-sets. */
final class RulesServiceProvider extends ServiceProvider
{
    private const HANDLERS = ['rule_set.approve' => RuleSetApprovalHandler::class, 'question_set.approve' => QuestionSetApprovalHandler::class];

    public function boot(): void
    {
        $this->app->afterResolving(ApprovalService::class, function (ApprovalService $approvals): void {
            foreach (self::HANDLERS as $action => $handler) {
                $approvals->registerHandler($action, $handler);
            }
        });
        if ($this->app->resolved(ApprovalService::class)) {
            foreach (self::HANDLERS as $action => $handler) {
                $this->app->make(ApprovalService::class)->registerHandler($action, $handler);
            }
        }
        if ($this->app->runningInConsole()) {
            $this->commands([SyncQuestionSetsCommand::class]);
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/rules.php'));
        }
    }
}
