<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Health\Preauth\PreauthLifecycle;
use App\Application\Health\Preauth\PreauthorizationService;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Health\ProviderClaims\ProviderSettlementService;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use App\Application\Health\ProviderClaims\ProviderClaimPricer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
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
 *   preauthProposeExtension POST health/preauthorizations/{p}/extensions/{e}/proposal health.preauth.review PreauthorizationService::proposeExtension
 *   preauthDecideExtension  POST health/preauthorizations/{p}/extensions/{e}/decision health.preauth.approve PreauthorizationService::decideExtension
 *   providerDisputeResolve  POST provider-disputes/{id}/resolve             health.provider_claims.adjudicate       ProviderOperationsService::resolveDispute
 *   providerClaimReview POST health/provider-claims/{c}/review               health.provider_claims.adjudicate       ProviderClaimService::startReview
 *   providerClaimAdjudicate POST health/provider-claims/{c}/adjudicate       health.provider_claims.adjudicate       ProviderClaimService::adjudicate (line overrides)
 *   providerClaimResolveDispute POST health/provider-claims/{c}/dispute/resolve health.provider_claims.adjudicate   ProviderClaimService::resolveDispute
 *   providerClaimPayable POST health/provider-claims/{c}/payable             health.provider_claims.approve_payment  ProviderClaimService::markPayable
 *   settlementCreate    POST health/provider-settlements                     health.provider_settlements.manage      ProviderSettlementService::createBatch
 *   settlementPay       POST health/provider-settlements/{b}/pay             health.provider_settlements.pay         ProviderSettlementService::payBatch
 */
final class HealthProviderActions
{
    /** @return list<Action> */
    public static function preauth(): array
    {
        return [self::preauthRequestInfo(), self::preauthPropose(), self::preauthReturn(), self::preauthDecide(), self::preauthProposeExtension(), self::preauthDecideExtension(), self::preauthCancel()];
    }

    /** Stay extensions of the pre-authorization in the given statuses: id => "#seq until date". @param list<string> $statuses */
    private static function extensions(mixed $record, array $statuses): array
    {
        return $record === null ? [] : DB::table('health_preauthorization_extensions')->where('health_preauthorization_id', WorkflowAction::id($record))
            ->whereIn('status', $statuses)->orderBy('sequence')->get()->mapWithKeys(fn ($e) => [$e->id => '#'.$e->sequence.' → '.$e->requested_until.' ('.$e->status.')'])->all();
    }

    public static function preauthProposeExtension(): Action
    {
        $p = 'health.preauth.review';

        return WorkflowAction::make('preauthProposeExtension', $p)->icon('heroicon-o-calendar-days')
            ->visible(fn ($record) => self::extensions($record, ['REQUESTED']) !== [])
            ->schema(fn ($record) => [
                Select::make('extension_id')->label(__('workflow_actions.fields.extension'))->options(self::extensions($record, ['REQUESTED']))->required(),
                Select::make('decision')->label(__('workflow_actions.fields.decision'))->options(WorkflowAction::options(PreauthLifecycle::DECISIONS, 'preauth'))->required()->live(),
                DatePicker::make('approved_until')->label(__('workflow_actions.fields.valid_until')),
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->maxLength(64)->required(fn (callable $get) => $get('decision') === 'DECLINED'),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PreauthorizationService::class)->proposeExtension(
                self::tenant(), WorkflowAction::id($record), $data['extension_id'], array_filter(array_diff_key($data, ['extension_id' => 1]), fn ($v) => filled($v)), auth()->user())));
    }

    public static function preauthDecideExtension(): Action
    {
        $p = 'health.preauth.approve';

        return WorkflowAction::make('preauthDecideExtension', $p)->icon('heroicon-o-shield-check')->color('success')->requiresConfirmation()
            ->visible(fn ($record) => self::extensions($record, ['PENDING_APPROVAL', 'REFERRED']) !== [])
            ->schema(fn ($record) => [Select::make('extension_id')->label(__('workflow_actions.fields.extension'))->options(self::extensions($record, ['PENDING_APPROVAL', 'REFERRED']))->required()])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PreauthorizationService::class)->decideExtension(self::tenant(), WorkflowAction::id($record), $data['extension_id'], auth()->user())));
    }

    /** Insurer-side resolution of a provider-portal dispute (provider_disputes). */
    public static function providerDisputeResolve(): Action
    {
        $p = 'health.provider_claims.adjudicate';

        return WorkflowAction::make('providerDisputeResolve', $p)->icon('heroicon-o-scale')
            ->visible(fn ($record) => in_array(self::status($record), \App\Application\Providers\Workspace\ProviderOperationsService::OPEN_DISPUTE, true))
            ->schema([
                Select::make('status')->label(__('workflow_actions.fields.outcome'))->required()
                    ->options(WorkflowAction::options(['ACKNOWLEDGED', 'UNDER_REVIEW', 'MORE_INFORMATION_REQUIRED', 'RESOLVED_PROVIDER', 'RESOLVED_INSURER', 'PARTIALLY_RESOLVED', 'ESCALATED', 'CLOSED'])),
                TextInput::make('resolution_amount_minor')->label(__('workflow_actions.fields.amount_minor'))->integer()->minValue(0),
                Textarea::make('response')->label(__('workflow_actions.fields.resolution'))->required()->minLength(3)->maxLength(5000),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(\App\Application\Providers\Workspace\ProviderOperationsService::class)->resolveDispute(
                self::tenant(), WorkflowAction::id($record), $data['status'], filled($data['resolution_amount_minor'] ?? null) ? (int) $data['resolution_amount_minor'] : null, $data['response'], auth()->user())));
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
                // Line-by-line decision (PARTIAL): a line left untouched is approved in full; reduce or decline it with a reason.
                Repeater::make('lines')->label(__('workflow_actions.fields.lines'))->visible(fn (callable $get) => $get('decision') === 'PARTIAL')
                    ->addable(false)->deletable(false)->reorderable(false)->columns(4)
                    ->itemLabel(fn (array $state) => $state['summary'] ?? null)
                    ->schema([
                        Hidden::make('line_id'), Hidden::make('summary'),
                        TextInput::make('approved_quantity')->label(__('workflow_actions.fields.approved_quantity'))->numeric()->minValue(0),
                        TextInput::make('approved_amount_minor')->label(__('workflow_actions.fields.approved_amount_minor'))->integer()->minValue(0),
                        TextInput::make('decline_reason')->label(__('workflow_actions.fields.decline_reason'))->maxLength(200)->columnSpan(2),
                    ]),
            ])
            ->fillForm(fn ($record) => ['lines' => DB::table('health_preauthorization_lines')->where('health_preauthorization_id', WorkflowAction::id($record))->whereNull('extension_id')
                ->orderBy('line_no')->get()->map(fn ($l) => ['line_id' => $l->id, 'approved_quantity' => $l->quantity, 'approved_amount_minor' => (int) $l->insurer_amount_minor,
                    'summary' => '#'.$l->line_no.' '.$l->service_code.' x '.$l->quantity.' = '.number_format((int) $l->insurer_amount_minor)])->all()])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PreauthorizationService::class)->propose(
                self::tenant(), WorkflowAction::id($record), self::proposal($data), auth()->user())));
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

    /** @return list<Action> insurer-side provider claim adjudication actions in lifecycle order */
    public static function providerClaim(): array
    {
        return [self::providerClaimReview(), self::providerClaimAdjudicate(), self::providerClaimPayable(), self::providerClaimResolveDispute()];
    }

    public static function providerClaimReview(): Action
    {
        $p = 'health.provider_claims.adjudicate';

        return WorkflowAction::make('providerClaimReview', $p)->icon('heroicon-o-magnifying-glass')->requiresConfirmation()
            ->visible(fn ($record) => self::status($record) === 'SUBMITTED')
            ->action(fn (Action $action, $record) => WorkflowAction::run($action, $p,
                fn () => app(ProviderClaimService::class)->startReview(self::tenant(), WorkflowAction::id($record), auth()->id())));
    }

    /** Line-by-line adjudication: each line may be rejected (reason code) or have its allowed amount reduced; the rest is priced on the tariff. */
    public static function providerClaimAdjudicate(): Action
    {
        $p = 'health.provider_claims.adjudicate';

        return WorkflowAction::make('providerClaimAdjudicate', $p)->icon('heroicon-o-scale')->modalWidth('5xl')
            ->visible(fn ($record) => self::status($record) === 'UNDER_REVIEW')
            ->fillForm(fn ($record) => ['lines' => collect(app(ProviderClaimService::class)->find(self::tenant(), WorkflowAction::id($record))->lines)
                ->map(fn ($l) => ['line_no' => $l->line_no, 'reject' => false,
                    'summary' => '#'.$l->line_no.' '.$l->service_code.' x '.$l->quantity.' = '.number_format((int) $l->billed_minor)])->all()])
            ->schema([
                Repeater::make('lines')->label(__('workflow_actions.fields.lines'))->addable(false)->deletable(false)->reorderable(false)->columns(4)
                    ->itemLabel(fn (array $state) => $state['summary'] ?? null)
                    ->schema([
                        Hidden::make('line_no'), Hidden::make('summary'),
                        Toggle::make('reject')->label(__('workflow_actions.fields.reject_line'))->live(),
                        Select::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->options(WorkflowAction::options(ProviderClaimPricer::REASONS))
                            ->required(fn (callable $get) => (bool) $get('reject')),
                        TextInput::make('allowed_minor')->label(__('workflow_actions.fields.allowed_minor'))->integer()->minValue(0)->hidden(fn (callable $get) => (bool) $get('reject')),
                        TextInput::make('explanation')->label(__('workflow_actions.fields.explanation'))->maxLength(2000),
                    ]),
                Textarea::make('note')->label(__('workflow_actions.fields.note'))->maxLength(2000),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ProviderClaimService::class)->adjudicate(
                self::tenant(), WorkflowAction::id($record), self::overrides($data['lines'] ?? []), $data['note'] ?? null, auth()->id())));
    }

    public static function providerClaimResolveDispute(): Action
    {
        $p = 'health.provider_claims.adjudicate';

        return WorkflowAction::make('providerClaimResolveDispute', $p)->icon('heroicon-o-chat-bubble-left-right')
            ->visible(fn ($record) => self::status($record) === 'DISPUTED')
            ->schema([
                Select::make('outcome')->label(__('workflow_actions.fields.outcome'))->options(WorkflowAction::options(['REOPEN', 'UPHOLD'], 'provider_dispute'))->required(),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->minLength(5)->maxLength(2000),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ProviderClaimService::class)->resolveDispute(self::tenant(), WorkflowAction::id($record), $data['outcome'], $data['reason'], auth()->id())));
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

    /** Preauth proposal payload: line rows only for a PARTIAL decision, each with its non-empty fields. */
    private static function proposal(array $data): array
    {
        $lines = ($data['decision'] ?? null) === 'PARTIAL'
            ? array_values(array_map(fn ($l) => array_filter(array_diff_key($l, ['summary' => 1]), fn ($v) => filled($v)), $data['lines'] ?? []))
            : [];
        unset($data['lines']);

        return array_filter($data, fn ($v) => filled($v)) + ($lines === [] ? [] : ['lines' => $lines]);
    }

    /**
     * Adjudication overrides in the API shape (lines.*.line_no / reject / reason_code / allowed_minor / explanation): only lines
     * the adjudicator touched are sent; untouched lines are priced on the tariff.
     *
     * @return list<array<string, mixed>>
     */
    private static function overrides(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $o = ['line_no' => (int) $l['line_no']];
            if (! empty($l['reject'])) {
                $o += ['reject' => true, 'reason_code' => $l['reason_code'] ?? null];
            } elseif (filled($l['allowed_minor'] ?? null)) {
                $o['allowed_minor'] = (int) $l['allowed_minor'];
            }
            if (filled($l['explanation'] ?? null)) {
                $o['explanation'] = $l['explanation'];
            }
            if (count($o) > 1) {
                $out[] = $o;
            }
        }

        return $out;
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
