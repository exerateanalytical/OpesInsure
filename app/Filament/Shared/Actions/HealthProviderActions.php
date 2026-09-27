<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Health\Preauth\PreauthLifecycle;
use App\Application\Health\Preauth\PreauthorizationService;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Health\ProviderClaims\ProviderSettlementService;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/**
 * Insurer-side health pre-authorization (guarantee of payment) and provider settlement actions. The record may be an
 * Eloquent model, a query row (stdClass) or an array — only its `id` / `status` are read. Same services / permissions as:
 *   preauthRequestInfo  POST health/preauthorizations/{p}/info-request       health.preauth.review   PreauthorizationService::requestInfo
 *   preauthPropose      POST health/preauthorizations/{p}/proposal           health.preauth.review   PreauthorizationService::propose
 *   preauthReturn       POST health/preauthorizations/{p}/proposal/return    health.preauth.approve  PreauthorizationService::returnProposal
 *   preauthDecide       POST health/preauthorizations/{p}/decision           health.preauth.approve  PreauthorizationService::decide  (issues the guarantee of payment)
 *   preauthCancel       POST health/preauthorizations/{p}/cancel             health.preauth.review   PreauthorizationService::cancel
 *   providerClaimPayable POST health/provider-claims/{c}/payable             health.provider_claims.approve_payment  ProviderClaimService::markPayable
 *   settlementCreate    POST health/provider-settlements                     health.provider_settlements.manage      ProviderSettlementService::createBatch
 *   settlementPay       POST health/provider-settlements/{b}/pay             health.provider_settlements.pay         ProviderSettlementService::payBatch
 */
final class HealthProviderActions
{
    /** @return list<Action> */
    public static function preauth(): array
    {
        return [self::preauthRequestInfo(), self::preauthPropose(), self::preauthReturn(), self::preauthDecide(), self::preauthCancel()];
    }

    public static function preauthRequestInfo(): Action
    {
        $p = 'health.preauth.review';

        return WorkflowAction::make('preauthRequestInfo', $p)->icon('heroicon-o-question-mark-circle')
            ->visible(fn ($record) => self::status($record) === 'REQUESTED')
            ->schema([Textarea::make('question')->label(__('workflow_actions.fields.question'))->required()->maxLength(2000)])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PreauthorizationService::class)->requestInfo(self::tenant(), WorkflowAction::id($record), $data['question'], auth()->user())));
    }

    public static function preauthPropose(): Action
    {
        $p = 'health.preauth.review';

        return WorkflowAction::make('preauthPropose', $p)->icon('heroicon-o-scale')
            ->visible(fn ($record) => self::status($record) === 'REQUESTED')
            ->schema([
                Select::make('decision')->label(__('workflow_actions.fields.decision'))->options(WorkflowAction::options(PreauthLifecycle::DECISIONS, 'preauth'))->required()->live(),
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->maxLength(64)->required(fn (callable $get) => $get('decision') === 'DECLINED'),
                DatePicker::make('valid_from')->label(__('workflow_actions.fields.valid_from')),
                DatePicker::make('valid_until')->label(__('workflow_actions.fields.valid_until'))->afterOrEqual('valid_from'),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(5000),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PreauthorizationService::class)->propose(
                self::tenant(), WorkflowAction::id($record), array_filter($data, fn ($v) => filled($v)), auth()->user())));
    }

    public static function preauthReturn(): Action
    {
        $p = 'health.preauth.approve';

        return WorkflowAction::make('preauthReturn', $p)->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn ($record) => in_array(self::status($record), ['PENDING_APPROVAL', 'REFERRED'], true))
            ->schema([Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PreauthorizationService::class)->returnProposal(self::tenant(), WorkflowAction::id($record), $data['reason'], auth()->user())));
    }

    public static function preauthDecide(): Action
    {
        $p = 'health.preauth.approve';

        return WorkflowAction::make('preauthDecide', $p)->icon('heroicon-o-shield-check')->color('success')->requiresConfirmation()
            ->visible(fn ($record) => in_array(self::status($record), ['PENDING_APPROVAL', 'REFERRED'], true))
            ->action(fn (Action $action, $record) => WorkflowAction::run($action, $p,
                fn () => app(PreauthorizationService::class)->decide(self::tenant(), WorkflowAction::id($record), auth()->user())));
    }

    public static function preauthCancel(): Action
    {
        $p = 'health.preauth.review';

        return WorkflowAction::make('preauthCancel', $p)->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
            ->visible(fn ($record) => ! in_array(self::status($record), PreauthLifecycle::TERMINAL, true))
            ->schema([Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PreauthorizationService::class)->cancel(self::tenant(), WorkflowAction::id($record), $data['reason'], auth()->user())));
    }

    public static function providerClaimPayable(): Action
    {
        $p = 'health.provider_claims.approve_payment';

        return WorkflowAction::make('providerClaimPayable', $p)->icon('heroicon-o-banknotes')->color('success')->requiresConfirmation()
            ->visible(fn ($record) => in_array(self::status($record), ['APPROVED', 'PARTIALLY_APPROVED'], true))
            ->action(fn (Action $action, $record) => WorkflowAction::run($action, $p,
                fn () => app(ProviderClaimService::class)->markPayable(self::tenant(), WorkflowAction::id($record), auth()->id())));
    }

    /** List-page header action (no record): batch the provider's unbatched PAYABLE claims. */
    public static function settlementCreate(): Action
    {
        $p = 'health.provider_settlements.manage';

        return WorkflowAction::make('settlementCreate', $p)->icon('heroicon-o-rectangle-stack')->requiresConfirmation()
            ->schema([
                Select::make('provider_id')->label(__('workflow_actions.fields.provider'))->required()->searchable()
                    ->options(fn () => DB::table('health_provider_claims')->join('provider_profiles', 'provider_profiles.id', '=', 'health_provider_claims.provider_profile_id')
                        ->where('health_provider_claims.tenant_id', self::tenant())->where('health_provider_claims.status', 'PAYABLE')->whereNull('settlement_batch_id')
                        ->join('parties', 'parties.id', '=', 'provider_profiles.party_id')->distinct()->pluck('parties.display_name', 'provider_profiles.id')->all()),
                TextInput::make('currency')->label(__('workflow_actions.fields.currency'))->required()->length(3)->default('XAF'),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ProviderSettlementService::class)->createBatch(self::tenant(), $data['provider_id'], $data['currency'], null, auth()->id())));
    }

    public static function settlementPay(): Action
    {
        $p = 'health.provider_settlements.pay';

        return WorkflowAction::make('settlementPay', $p)->icon('heroicon-o-credit-card')->color('success')->requiresConfirmation()
            ->visible(fn ($record) => self::status($record) === 'OPEN')
            ->schema([TextInput::make('payment_reference')->label(__('workflow_actions.fields.payment_reference'))->required()->maxLength(120)])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ProviderSettlementService::class)->payBatch(self::tenant(), WorkflowAction::id($record), $data['payment_reference'], auth()->id())));
    }

    private static function status(mixed $record): ?string
    {
        return $record === null ? null : (is_array($record) ? ($record['status'] ?? null) : $record->status);
    }

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
