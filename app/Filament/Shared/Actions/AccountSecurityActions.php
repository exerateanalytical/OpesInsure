<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Operations\NotificationDeliveryService;
use App\Application\Security\Findings\SecurityFindingService;
use App\Domain\Tenancy\TenantContext;
use App\Models\NotificationDelivery;
use App\Models\SecurityFinding;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Communications and security-centre actions. Same permission + validation + service call as the API:
 *   notificationQueue   POST notifications               communications.manage     NotificationDeliveryService::queue
 *   notificationRetry   POST notifications/{d}/retry     communications.manage     NotificationDeliveryService::retry
 *   notificationCancel  POST notifications/{d}/cancel    communications.manage     NotificationDeliveryService::cancel
 *   findingReport       POST security-centre/findings    security.findings.manage  SecurityFindingService::report
 *   findingTransition   POST security-centre/findings/{f}/transition  security.findings.manage (+ security.findings.accept_risk
 *                       for RISK_ACCEPTED)  SecurityFindingService::transition — the service keeps the maker-checker rule
 *                       (the reporter or owner cannot accept the risk).
 */
final class AccountSecurityActions
{
    private const LANG = 'support_actions';

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private static function f(string $key): string
    {
        return __(self::LANG.".fields.{$key}");
    }

    /** @param list<string> $values */
    private static function tr(array $values, string $group): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => __(self::LANG.".{$group}.{$v}")])->all();
    }

    public static function notificationQueue(): Action
    {
        $p = 'communications.manage';

        return WorkflowAction::make('notificationQueue', $p, self::LANG)->icon('lucide-send')
            ->schema([
                SupportActions::customerSelect()->required(),
                Select::make('template_id')->label(self::f('template'))->required()
                    ->options(fn () => DB::table('notification_templates')->where('status', 'ACTIVE')->where(fn ($q) => $q->where('tenant_id', self::tenant())->orWhereNull('tenant_id'))
                        ->orderBy('code')->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->code} · {$t->channel} · {$t->locale} v{$t->version}"])->all()),
                TextInput::make('destination')->label(self::f('destination'))->required()->maxLength(190),
                KeyValue::make('variables')->label(self::f('variables')),
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(NotificationDeliveryService::class)->queue(self::tenant(), ['variables' => (array) ($data['variables'] ?? [])] + $data),
                __('support_actions.notificationQueue.done')));
    }

    public static function notificationRetry(): Action
    {
        $p = 'communications.manage';

        return WorkflowAction::make('notificationRetry', $p, self::LANG)->icon('lucide-rotate-cw')->requiresConfirmation()
            ->visible(fn (NotificationDelivery $record) => in_array($record->status, ['FAILED', 'QUEUED'], true))
            ->action(fn (Action $action, NotificationDelivery $record) => WorkflowAction::run($action, $p,
                fn () => app(NotificationDeliveryService::class)->retry(self::owned($record)), __('support_actions.notificationRetry.done')));
    }

    public static function notificationCancel(): Action
    {
        $p = 'communications.manage';

        return WorkflowAction::make('notificationCancel', $p, self::LANG)->icon('lucide-ban')->color('danger')->requiresConfirmation()
            ->visible(fn (NotificationDelivery $record) => in_array($record->status, ['FAILED', 'QUEUED'], true))
            ->action(fn (Action $action, NotificationDelivery $record) => WorkflowAction::run($action, $p,
                fn () => app(NotificationDeliveryService::class)->cancel(self::owned($record)), __('support_actions.notificationCancel.done')));
    }

    private static function owned(NotificationDelivery $record): NotificationDelivery
    {
        return NotificationDelivery::where(['id' => $record->id, 'tenant_id' => self::tenant()])->firstOrFail();
    }

    public static function findingReport(): Action
    {
        $p = 'security.findings.manage';

        return WorkflowAction::make('findingReport', $p, self::LANG)->icon('lucide-shield-alert')
            ->schema([
                Select::make('source')->label(self::f('source'))->required()->options(self::tr(SecurityFindingService::SOURCES, 'finding_sources')),
                Select::make('severity')->label(self::f('severity'))->required()->options(self::tr(SecurityFindingService::SEVERITIES, 'severities')),
                TextInput::make('title')->label(self::f('title'))->required()->maxLength(255),
                Textarea::make('description')->label(self::f('description'))->required()->maxLength(10000),
                TextInput::make('category')->label(self::f('category'))->maxLength(48),
                TextInput::make('affected_asset')->label(self::f('affected_asset'))->maxLength(191),
                TextInput::make('cve')->label(self::f('cve'))->maxLength(32),
                Select::make('owner_id')->label(self::f('owner'))->searchable()->options(fn () => self::members()),
                DateTimePicker::make('due_at')->label(self::f('due_at')),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SecurityFindingService::class)->report(self::tenant(), array_filter($data, fn ($v) => $v !== null && $v !== ''), auth()->user()),
                __('support_actions.findingReport.done')));
    }

    public static function findingTransition(): Action
    {
        $p = 'security.findings.manage';

        return WorkflowAction::make('findingTransition', $p, self::LANG)->icon('lucide-arrow-right-left')
            ->schema([
                Select::make('to')->label(self::f('to_status'))->required()->live()
                    ->options(self::tr(['TRIAGED', 'IN_REMEDIATION', 'RESOLVED', 'RISK_ACCEPTED', 'FALSE_POSITIVE', 'OPEN'], 'finding_statuses')),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(4000),
                Textarea::make('remediation_plan')->label(self::f('remediation_plan'))->maxLength(4000)->visible(fn ($get) => $get('to') === 'IN_REMEDIATION'),
                DateTimePicker::make('risk_acceptance_expires_at')->label(self::f('risk_acceptance_expires_at'))->visible(fn ($get) => $get('to') === 'RISK_ACCEPTED'),
            ])
            ->action(fn (Action $action, SecurityFinding $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                abort_unless(DB::table('security_findings')->where('tenant_id', self::tenant())->where('id', $record->id)->exists(), 404);
                if ($data['to'] === 'RISK_ACCEPTED' && ! auth()->user()->hasPermission('security.findings.accept_risk')) {
                    abort(403, __('support_actions.findingTransition.accept_risk_denied'));
                }

                return app(SecurityFindingService::class)->transition(SecurityFinding::query()->findOrFail($record->id), $data['to'], auth()->user(), array_filter($data, fn ($v) => $v !== null && $v !== ''));
            }, __('support_actions.findingTransition.done')));
    }

    private static function members(): array
    {
        $tenant = self::tenant();

        return User::where('status', 'ACTIVE')->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenant)->where('status', 'ACTIVE'))->pluck('full_name', 'id')->all();
    }
}
