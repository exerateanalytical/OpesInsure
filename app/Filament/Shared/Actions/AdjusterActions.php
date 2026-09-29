<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Claims\Adjusters\ExpertAssignmentLifecycle;
use App\Application\Claims\Adjusters\ExpertAssignmentService;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;

/**
 * Adjuster (expert) workspace actions (UI batch 24) — the caller's own assignments only. Same permission + service +
 * validation as AdjusterAssignmentController: every call first runs ExpertAssignmentService::assertAdjusterOwns.
 *   adjusterAccept      POST adjuster/assignments/{a}/accept      claims.experts.work  ExpertAssignmentService::accept
 *   adjusterDecline     POST adjuster/assignments/{a}/decline     claims.experts.work  ExpertAssignmentService::decline
 *   adjusterSchedule    POST adjuster/assignments/{a}/inspection  claims.experts.work  ExpertAssignmentService::scheduleInspection
 *   adjusterInspected   POST adjuster/assignments/{a}/inspected   claims.experts.work  ExpertAssignmentService::recordInspection
 *   adjusterReport      POST adjuster/assignments/{a}/report      claims.experts.work  ExpertAssignmentService::submitReport
 */
final class AdjusterActions
{
    private const L = 'operations_actions';

    private const P = 'claims.experts.work';

    public static function accept(): Action
    {
        return self::make('adjusterAccept', 'accept', 'lucide-check')->requiresConfirmation()
            ->action(fn (Action $action, mixed $record) => self::run($action, $record, fn (ExpertAssignmentService $s, string $t, string $id) => $s->accept($t, $id, auth()->user())));
    }

    public static function decline(): Action
    {
        return self::make('adjusterDecline', 'decline', 'lucide-x')->color('danger')
            ->schema([Textarea::make('reason')->label(__(self::L.'.fields.reason'))->required()->minLength(5)->maxLength(5000)])
            ->action(fn (Action $action, mixed $record, array $data) => self::run($action, $record,
                fn (ExpertAssignmentService $s, string $t, string $id) => $s->decline($t, $id, $data['reason'], auth()->user())));
    }

    public static function scheduleInspection(): Action
    {
        return self::make('adjusterSchedule', 'schedule_inspection', 'lucide-calendar-plus')
            ->schema([
                DateTimePicker::make('scheduled_for')->label(__(self::L.'.fields.scheduled_for'))->required()->after('now'),
                TextInput::make('location')->label(__(self::L.'.fields.location'))->maxLength(255),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => self::run($action, $record,
                fn (ExpertAssignmentService $s, string $t, string $id) => $s->scheduleInspection($t, $id, Carbon::parse($data['scheduled_for'])->toIso8601String(), $data['location'] ?? null, auth()->user())));
    }

    public static function recordInspection(): Action
    {
        return self::make('adjusterInspected', 'record_inspection', 'lucide-clipboard-check')
            ->schema([
                DateTimePicker::make('inspected_at')->label(__(self::L.'.fields.inspected_at')),
                Textarea::make('notes')->label(__(self::L.'.fields.notes'))->maxLength(10000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => self::run($action, $record,
                fn (ExpertAssignmentService $s, string $t, string $id) => $s->recordInspection($t, $id, $data['notes'] ?? null,
                    filled($data['inspected_at'] ?? null) ? Carbon::parse($data['inspected_at'])->toIso8601String() : null, auth()->user())));
    }

    public static function submitReport(): Action
    {
        return self::make('adjusterReport', 'submit_report', 'lucide-file-text')
            ->schema([
                Textarea::make('summary')->label(__(self::L.'.fields.summary'))->required()->minLength(20)->maxLength(20000),
                TextInput::make('assessed_loss_minor')->label(__(self::L.'.fields.assessed_loss_minor'))->required()->integer()->minValue(0),
                TextInput::make('document_id')->label(__(self::L.'.fields.document_id'))->uuid(),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => self::run($action, $record,
                fn (ExpertAssignmentService $s, string $t, string $id) => $s->submitReport($t, $id, ['summary' => $data['summary'],
                    'assessed_loss_minor' => (int) $data['assessed_loss_minor'], 'document_id' => filled($data['document_id'] ?? null) ? $data['document_id'] : null], auth()->user())));
    }

    private static function make(string $name, string $event, string $icon): Action
    {
        return WorkflowAction::make($name, self::P, self::L)->icon($icon)
            ->visible(fn (mixed $record) => ExpertAssignmentLifecycle::target((string) ($record['status'] ?? ''), $event) !== null);
    }

    private static function run(Action $action, mixed $record, \Closure $call): mixed
    {
        return WorkflowAction::run($action, self::P, function () use ($record, $call) {
            $s = app(ExpertAssignmentService::class);
            $tenant = app(TenantContext::class)->id();
            $id = WorkflowAction::id($record);
            $s->assertAdjusterOwns($tenant, auth()->user(), $id);

            return $call($s, $tenant, $id);
        }, __(self::L.'.'.$action->getName().'.done'));
    }
}
