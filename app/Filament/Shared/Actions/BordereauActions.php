<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\FinancialDistribution\BordereauService;
use App\Application\FinancialDistribution\ReinsuranceReference;
use App\Domain\Tenancy\TenantContext;
use App\Models\Bordereau;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * Carrier bordereaux (UI coverage batch 10), admin panel. BordereauService is the one service behind bordereaux/* and the
 * deprecated broker/bordereaux/* and carrier/bordereaux/{b}/decision aliases; it refuses approval or submission by the preparer.
 *   bordereauPrepare     POST bordereaux                     bordereaux.prepare  BordereauService::prepare
 *   bordereauApprove     POST bordereaux/{b}/approve         bordereaux.approve  BordereauService::approve
 *   bordereauSubmit      POST bordereaux/{b}/submit          bordereaux.submit   BordereauService::submit
 *   bordereauAcknowledge POST bordereaux/{b}/acknowledge     bordereaux.confirm  BordereauService::acknowledge
 *   bordereauReject      POST bordereaux/{b}/reject          bordereaux.confirm  BordereauService::reject
 */
final class BordereauActions
{
    private const L = 'finance_actions';

    public static function prepare(): Action
    {
        $p = 'bordereaux.prepare';

        return WorkflowAction::make('bordereauPrepare', $p, self::L)->icon('lucide-plus')
            ->schema([
                Select::make('carrier_id')->label(__('finance_actions.fields.carrier'))->options(fn () => FinanceOptions::carriers())->searchable()->required(),
                Select::make('type')->label(__('finance_actions.fields.bordereau_type'))
                    ->options(collect(ReinsuranceReference::PRODUCIBLE_BORDEREAU_TYPES)->mapWithKeys(fn ($t) => [$t => __("finance_actions.codes.{$t}")])->all())->required(),
                ...StatementPayoutActions::periodFields(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(BordereauService::class)->prepare([
                'carrier_id' => $data['carrier_id'], 'type' => ReinsuranceReference::bordereauType($data['type']), 'period_start' => $data['period_start'], 'period_end' => $data['period_end'],
                'currency' => $data['currency'], 'idempotency_key' => 'web-'.Str::uuid(), 'tenant_id' => self::tenant(),
            ], auth()->user()), __('finance_actions.bordereauPrepare.done')));
    }

    public static function approve(): Action
    {
        $p = 'bordereaux.approve';

        return WorkflowAction::make('bordereauApprove', $p, self::L)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn (Bordereau $record) => $record->status === 'DRAFT')
            ->action(fn (Action $action, Bordereau $record) => WorkflowAction::run($action, $p,
                fn () => app(BordereauService::class)->approve(self::bordereau($record), auth()->user()), __('finance_actions.bordereauApprove.done')));
    }

    public static function submit(): Action
    {
        $p = 'bordereaux.submit';

        return WorkflowAction::make('bordereauSubmit', $p, self::L)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (Bordereau $record) => $record->status === 'APPROVED')
            ->action(fn (Action $action, Bordereau $record) => WorkflowAction::run($action, $p,
                fn () => app(BordereauService::class)->submit(self::bordereau($record), auth()->user()), __('finance_actions.bordereauSubmit.done')));
    }

    public static function acknowledge(): Action
    {
        $p = 'bordereaux.confirm';

        return WorkflowAction::make('bordereauAcknowledge', $p, self::L)->icon('lucide-circle-check')->color('success')
            ->visible(fn (Bordereau $record) => $record->status === 'SUBMITTED')
            ->schema([TextInput::make('carrier_reference')->label(__('finance_actions.fields.carrier_reference'))->required()->maxLength(120)])
            ->action(fn (Action $action, Bordereau $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(BordereauService::class)->acknowledge(self::bordereau($record), $data['carrier_reference'], auth()->user()), __('finance_actions.bordereauAcknowledge.done')));
    }

    public static function reject(): Action
    {
        $p = 'bordereaux.confirm';

        return WorkflowAction::make('bordereauReject', $p, self::L)->icon('lucide-circle-x')->color('danger')
            ->visible(fn (Bordereau $record) => $record->status === 'SUBMITTED')
            ->schema([Textarea::make('reason')->label(__('finance_actions.fields.reason'))->required()->maxLength(1000)])
            ->action(fn (Action $action, Bordereau $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(BordereauService::class)->reject(self::bordereau($record), $data['reason'], auth()->user()), __('finance_actions.bordereauReject.done')));
    }

    /** @return list<Action> */
    public static function recordActions(): array
    {
        return [self::approve(), self::submit(), self::acknowledge(), self::reject()];
    }

    private static function bordereau(Bordereau $b): Bordereau
    {
        return Bordereau::where('tenant_id', self::tenant())->findOrFail($b->id);
    }

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
