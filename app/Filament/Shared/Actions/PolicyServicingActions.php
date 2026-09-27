<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Policies\Lapse\PolicyRecoveryService;
use App\Application\Policies\Portability\PolicyPortabilityExportService;
use App\Application\Policies\Portability\PolicyPortfolioTransferService;
use App\Application\Policies\Suspension\PolicySuspension;
use App\Application\Policies\Suspension\PolicySuspensionService;
use App\Models\Policy;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/**
 * Policy servicing actions beyond endorsement/cancellation (mount on the policy detail page). Same services / permissions as:
 *   requestReinstatement  POST policies/{p}/reinstatement-requests                policies.reinstatement.request      PolicySuspensionService::requestReinstatement
 *   decideReinstatement   POST policies/{p}/reinstate | reinstatement-requests/reject  policies.reinstatement.approve  PolicySuspensionService::reinstate|rejectReinstatement
 *   portfolioTransfer     POST policy-portfolio-transfers (preview → request)        policies.portfolio_transfer.request PolicyPortfolioTransferService::preview|request
 *   portabilityExport     POST policies/{p}/portability-exports                      policies.portability.export         PolicyPortabilityExportService::export
 *   recoveryRequest       POST policies/{p}/recovery-cases                           policy.recovery.request             PolicyRecoveryService::open
 */
final class PolicyServicingActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::requestReinstatement(), self::decideReinstatement(), self::portfolioTransfer(), self::portabilityExport(), self::recoveryRequest()])
            ->label(__('workflow_actions.policy_servicing_group'))->icon('lucide-wrench')->button();
    }

    public static function requestReinstatement(): Action
    {
        $p = 'policies.reinstatement.request';

        return WorkflowAction::make('policyRequestReinstatement', $p)->icon('lucide-refresh-cw')
            ->visible(fn (Policy $record) => $record->status === 'SUSPENDED' && self::suspension($record)?->status !== 'REINSTATEMENT_REQUESTED')
            ->schema([
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PolicySuspensionService::class)->requestReinstatement($record, $data['reason_code'], auth()->user(), $data['notes'] ?? null)));
    }

    public static function decideReinstatement(): Action
    {
        $p = 'policies.reinstatement.approve';

        return WorkflowAction::make('policyDecideReinstatement', $p)->icon('lucide-badge-check')->requiresConfirmation()
            ->visible(fn (Policy $record) => $record->status === 'SUSPENDED' && self::suspension($record)?->status === 'REINSTATEMENT_REQUESTED')
            ->schema([
                Select::make('outcome')->label(__('workflow_actions.fields.outcome'))->options(['APPROVE' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->required()->live(),
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->maxLength(64)->required(fn (callable $get) => $get('outcome') === 'APPROVE'),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->maxLength(2000)->required(fn (callable $get) => $get('outcome') === 'REJECT'),
            ])
            ->action(function (Action $action, Policy $record, array $data) use ($p) {
                $s = app(PolicySuspensionService::class);

                return WorkflowAction::run($action, $p, fn () => $data['outcome'] === 'APPROVE'
                    ? $s->reinstate($record, (string) $data['reason_code'], auth()->user())
                    : $s->rejectReinstatement($record, (string) $data['reason'], auth()->user()));
            });
    }

    public static function portfolioTransfer(): Action
    {
        $p = 'policies.portfolio_transfer.request';

        return WorkflowAction::make('policyPortfolioTransfer', $p)->icon('lucide-arrow-left-right')->requiresConfirmation()
            ->visible(fn (Policy $record) => in_array($record->status, PolicyPortfolioTransferService::ELIGIBLE_STATUSES, true))
            ->schema([
                Select::make('scope')->label(__('workflow_actions.fields.scope'))->required()->live()->options(WorkflowAction::options(PolicyPortfolioTransferService::SCOPES, 'transfer_scope')),
                Select::make('to_id')->label(__('workflow_actions.fields.transfer_to'))->required()->searchable()
                    ->options(fn (callable $get, Policy $record) => match ($get('scope')) {
                        'CARRIER' => DB::table('carriers as c')->leftJoin('parties as p', 'p.id', '=', 'c.party_id')->where('c.status', 'ACTIVE')->pluck('p.display_name', 'c.id')->all(),
                        'INTERMEDIARY' => DB::table('partners as x')->leftJoin('parties as p', 'p.id', '=', 'x.party_id')->where('x.tenant_id', $record->tenant_id)
                            ->whereIn('x.type', ['AGENT', 'BROKER'])->where('x.status', 'ACTIVE')->pluck('p.display_name', 'x.id')->all(),
                        default => [],
                    }),
                Select::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->options(WorkflowAction::options(PolicyPortfolioTransferService::REASONS)),
                Select::make('notice_mode')->label(__('workflow_actions.fields.notice_mode'))->required()->options(WorkflowAction::options(['NOTICE', 'CONSENT'])),
                DateTimePicker::make('effective_at')->label(__('workflow_actions.fields.effective_at')),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $svc = app(PolicyPortfolioTransferService::class);
                $from = self::transferSource($record, $data['scope']);
                // The same two API calls: preview (freezes the selection hash), then request with that hash.
                $preview = $svc->preview($record->tenant_id, $data['scope'], $from, $data['to_id'], [$record->id]);

                return $svc->request($record->tenant_id, array_filter([
                    'scope' => $data['scope'], 'from_id' => $from, 'to_id' => $data['to_id'], 'policy_ids' => [$record->id], 'preview_hash' => $preview['preview_hash'],
                    'reason_code' => $data['reason_code'], 'notice_mode' => $data['notice_mode'], 'effective_at' => $data['effective_at'] ?? null, 'notes' => $data['notes'] ?? null,
                ], fn ($v) => $v !== null), auth()->user());
            }));
    }

    public static function portabilityExport(): Action
    {
        $p = 'policies.portability.export';

        return WorkflowAction::make('policyPortabilityExport', $p)->icon('lucide-download')
            ->schema([
                Select::make('purpose')->label(__('workflow_actions.fields.purpose'))->required()->options(WorkflowAction::options(PolicyPortabilityExportService::PURPOSES)),
                TextInput::make('consent_reference')->label(__('workflow_actions.fields.consent_reference'))->required()->minLength(3)->maxLength(191),
                TextInput::make('recipient')->label(__('workflow_actions.fields.recipient'))->maxLength(191),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PolicyPortabilityExportService::class)->export(
                $record, $data['purpose'], $data['consent_reference'], filled($data['recipient'] ?? null) ? $data['recipient'] : null, auth()->user())));
    }

    public static function recoveryRequest(): Action
    {
        $p = 'policy.recovery.request';

        return WorkflowAction::make('policyRecoveryRequest', $p)->icon('lucide-life-buoy')
            ->visible(fn (Policy $record) => in_array($record->status, PolicyRecoveryService::RECOVERABLE, true))
            ->schema([
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                DatePicker::make('new_coverage_ends_at')->label(__('workflow_actions.fields.new_coverage_ends_at')),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PolicyRecoveryService::class)->open($record, array_filter($data, fn ($v) => filled($v)), auth()->user())));
    }

    private static function suspension(Policy $policy): ?PolicySuspension
    {
        return PolicySuspension::where('policy_id', $policy->id)->whereIn('status', ['SUSPENDED', 'REINSTATEMENT_REQUESTED'])->latest('created_at')->first();
    }

    /** The side the policy currently sits on (PolicyPortfolioTransferService::currentBook). */
    private static function transferSource(Policy $policy, string $scope): string
    {
        if ($scope === 'CARRIER') {
            return (string) ($policy->servicing_carrier_id ?? $policy->carrier_id);
        }

        return (string) ($policy->servicing_partner_id ?? DB::table('customer_attributions')->where('party_id', $policy->party_id)->where('status', 'ACTIVE')->value('partner_id')
            ?? throw \Illuminate\Validation\ValidationException::withMessages(['scope' => __('workflow_actions.policyPortfolioTransfer.no_intermediary')]));
    }
}
