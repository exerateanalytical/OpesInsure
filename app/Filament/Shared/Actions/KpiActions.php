<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Reporting\Kpi\KpiCatalogueService;
use App\Application\Reporting\Kpi\KpiQueryRegistry;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * KPI governance catalogue (UI batch 26). Same permission + service + validation as ReportingController:
 *   kpiDraft    POST reporting/kpis                                reporting.kpis.manage   KpiCatalogueService::draft (registered queries only)
 *   kpiSubmit   POST reporting/kpi-definitions/{d}/submit          reporting.kpis.manage   KpiCatalogueService::submit
 *   kpiApprove  POST reporting/kpi-definitions/{d}/approve         reporting.kpis.approve  KpiCatalogueService::approve (author cannot approve)
 *   kpiReject   POST reporting/kpi-definitions/{d}/reject          reporting.kpis.approve  KpiCatalogueService::reject (author cannot reject)
 *   kpiRetire   POST reporting/kpi-definitions/{d}/retire          reporting.kpis.approve  KpiCatalogueService::retire
 */
final class KpiActions
{
    private const L = 'operations_actions';

    public static function draft(): Action
    {
        $p = 'reporting.kpis.manage';

        return WorkflowAction::make('kpiDraft', $p, self::L)->icon('lucide-plus')
            ->schema([
                TextInput::make('code')->label(__(self::L.'.fields.code'))->required()->maxLength(80)->regex('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/'),
                TextInput::make('name')->label(__(self::L.'.fields.name'))->required()->maxLength(255),
                Textarea::make('definition')->label(__(self::L.'.fields.definition'))->required()->maxLength(4000),
                Select::make('query_key')->label(__(self::L.'.fields.query_key'))->required()->searchable()
                    ->options(fn () => collect(KpiQueryRegistry::definitions())->mapWithKeys(fn ($q, $k) => [$k => $k.' — '.$q['label']])->all()),
                TextInput::make('formula')->label(__(self::L.'.fields.formula'))->maxLength(2000),
                KeyValue::make('filters')->label(__(self::L.'.fields.filters')),
                TextInput::make('currency')->label(__(self::L.'.fields.currency'))->length(3),
                TextInput::make('owner')->label(__(self::L.'.fields.owner'))->required()->maxLength(120),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(KpiCatalogueService::class)->draft(
                app(TenantContext::class)->id(), auth()->user(), array_filter($data, fn ($v) => filled($v))), __(self::L.'.kpiDraft.done')));
    }

    public static function submit(): Action
    {
        return self::transition('kpiSubmit', 'reporting.kpis.manage', 'DRAFT', 'lucide-send',
            fn (KpiCatalogueService $s, string $t, string $id) => $s->submit($t, $id, auth()->user()));
    }

    public static function approve(): Action
    {
        return self::transition('kpiApprove', 'reporting.kpis.approve', 'PENDING_APPROVAL', 'lucide-badge-check',
            fn (KpiCatalogueService $s, string $t, string $id) => $s->approve($t, $id, auth()->user()));
    }

    public static function reject(): Action
    {
        $p = 'reporting.kpis.approve';

        return WorkflowAction::make('kpiReject', $p, self::L)->icon('lucide-x')->color('danger')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'PENDING_APPROVAL')
            ->schema([Textarea::make('reason')->label(__(self::L.'.fields.reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(KpiCatalogueService::class)->reject(app(TenantContext::class)->id(), WorkflowAction::id($record), auth()->user(), $data['reason']),
                __(self::L.'.kpiReject.done')));
    }

    public static function retire(): Action
    {
        return self::transition('kpiRetire', 'reporting.kpis.approve', 'ACTIVE', 'lucide-archive',
            fn (KpiCatalogueService $s, string $t, string $id) => $s->retire($t, $id, auth()->user()));
    }

    private static function transition(string $name, string $p, string $from, string $icon, \Closure $call): Action
    {
        return WorkflowAction::make($name, $p, self::L)->icon($icon)->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === $from)
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => $call(app(KpiCatalogueService::class), app(TenantContext::class)->id(), WorkflowAction::id($record)), __(self::L.".{$name}.done")));
    }
}
