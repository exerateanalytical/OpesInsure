<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Accumulation\LargeLossNotifier;
use App\Application\Claims\Adjusters\ExpertAssignmentLifecycle;
use App\Application\Claims\Adjusters\ExpertAssignmentService;
use App\Application\Claims\Assessment\ClaimInvestigationService;
use App\Application\Claims\Assessment\Models\ClaimInvestigation;
use App\Application\Claims\ClaimCarrierExchangeService;
use App\Application\Claims\ClaimEvidenceService;
use App\Application\Claims\ClaimLifecycleService;
use App\Application\Claims\ClaimPaymentService;
use App\Application\Claims\Closure\ClaimClosureService;
use App\Application\Claims\Coverage\ClaimCoverageCheckService;
use App\Application\Claims\Coverage\CoverageAtLossEngine;
use App\Application\Claims\Decisions\ClaimDecisionService;
use App\Application\Claims\Evidence\ClaimEvidenceReviewService;
use App\Application\Claims\Execution\ClaimExecutionService;
use App\Application\Claims\Fnol\FnolService;
use App\Application\Claims\Parties\ClaimPartyService;
use App\Application\Claims\Settlement\ClaimSettlementService;
use App\Application\Claims\Types\ClaimReportingService;
use App\Application\Documents\SubjectDocuments;
use App\Application\PartnerWorkspace\PartnerWorkspaceScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimInvolvedParty;
use App\Models\ClaimPayment;
use App\Models\Document;
use App\Models\Policy;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Claim case-handling actions (UI coverage batches 1 and 2). Siblings of ClaimActions, built on WorkflowAction: same
 * permission as the API route (hidden without it, re-checked on run), same service call, refusals shown as
 * notifications. Labels live in resources/lang/{en,fr}/claim_actions.php.
 *
 *   coverageCheck        POST claims/{claim}/coverage-checks                 claims.coverage.check        ClaimCoverageCheckService::checkClaim
 *   coverageResolve      POST claims/{claim}/coverage-checks/{c}/resolve     claims.coverage.resolve      ClaimCoverageCheckService::resolve
 *   policyCoverageCheck  POST claims/coverage/check (list page)              claims.coverage.check        CoverageAtLossEngine::evaluate
 *   lateReportRecommend  POST claims/{claim}/late-report/recommend           claims.late_report.recommend ClaimReportingService::recommend
 *   lateReportDecide     POST claims/{claim}/late-report/decide              claims.late_report.approve   ClaimReportingService::decide
 *   appeal               POST claims/{claim}/appeals                         claims.decision.appeal       ClaimDecisionService::appeal
 *   largeLossCheck       POST claims/{claim}/large-loss-check                claims.view                  LargeLossNotifier::check
 *   transition           POST claims/{id}/transitions                        claims.transition            ClaimLifecycleService::transition
 *   investigationOpen|Findings|Indicators|Conclude  claims/…/investigations  claims.investigation.manage|conclude  ClaimInvestigationService
 *   partyAdd|Update|Remove  claims/{claim}/parties                           claims.parties.manage        ClaimPartyService::add|update|remove
 *   expertAssign|Cancel  claims/{id}/assignments/…                           claims.experts.assign        ExpertAssignmentService::assign|cancel
 *   expertReview         claims/{id}/assignments/{a}/report/accept|return    claims.experts.review        ExpertAssignmentService::acceptReport|returnReport
 *   evidenceAttach       POST claims/{id}/evidence                           claims.evidence.manage       ClaimEvidenceService::attach
 *   evidenceVerify       POST claims/{id}/evidence/{d}/verify                claims.evidence.verify       ClaimEvidenceService::verify
 *   evidenceReview       POST claims/{id}/evidence/{d}/review                claims.evidence.verify       ClaimEvidenceReviewService::review
 *   carrierQueue         POST claims/{id}/carrier-messages                   claims.carrier.exchange      ClaimCarrierExchangeService::queue
 *   carrierCallback      POST …/carrier-messages/{m}/acknowledged|failed     claims.carrier.callback      ClaimCarrierExchangeService::acknowledge|markFailure
 *   carrierManualEntry   POST claims/{id}/carrier-messages/manual            claims.carrier.manual_entry  ClaimExecutionService::proposeManualEntry
 *   carrierReviewEntry   POST …/manual/{e}/approve|reject                    claims.carrier.manual_approve ClaimExecutionService::reviewManualEntry
 *   executionSubmit      POST claims/{id}/execution/submit                   claims.carrier.exchange      ClaimExecutionService::submit
 *   settlementDischarge|ConfirmDischarge  claim-settlements/{s}/discharge[/confirm]  claims.settlement.discharge  ClaimSettlementService
 *   settlementPayment    POST claim-settlements/{s}/payment                  claims.settlement.pay        ClaimSettlementService::requestPayment
 *   paymentExecution     POST claims/{id}/payments/{p}/processing|failed     claims.payment.execute       ClaimPaymentService::processing|fail
 *   recoveryTransfer     POST claims/recoveries/{r}/transfer                 claims.close                 ClaimClosureService::transferRecovery
 *   agentRegister        POST mobile/partner/agent/claims (list page)        agent.clients.manage         FnolService::submitForCustomer
 *   brokerRegister       POST mobile/partner/broker/claims (list page)       broker.claims.file           FnolService::submitForBrokerClient
 * Not given a second web entry point on purpose (they duplicate an existing action): POST claims/{id}/decisions
 * (= ClaimActions::decide), POST claims/{id}/recoveries and …/receipts (= ClaimActions::openRecovery / updateRecovery).
 */
final class ClaimCaseActions
{
    /** @return list<ActionGroup> header groups for the claim detail page */
    public static function groups(): array
    {
        $g = fn (string $key, string $icon, array $actions) => ActionGroup::make($actions)->label(__('claim_actions.groups.'.$key))->icon($icon)->button()->color('gray');

        return [
            $g('review', 'lucide-shield-check', [self::coverageCheck(), self::coverageResolve(), self::lateReportRecommend(), self::lateReportDecide(), self::appeal(), self::largeLossCheck(), self::transition()]),
            $g('investigation', 'lucide-search', [self::investigationOpen(), self::investigationFindings(), self::investigationIndicators(), self::investigationConclude(), self::expertAssign(), self::expertCancel(), self::expertReview()]),
            $g('parties', 'lucide-users', [self::partyAdd(), self::partyUpdate(), self::partyRemove(), self::evidenceAttach(), self::evidenceVerify(), self::evidenceReview()]),
            $g('carrier', 'lucide-send', [self::carrierQueue(), self::carrierCallback(), self::carrierManualEntry(), self::carrierReviewEntry(), self::executionSubmit()]),
            $g('settlement', 'lucide-wallet', [self::settlementDischarge(), self::settlementConfirmDischarge(), self::settlementPayment(), self::paymentExecution(), self::recoveryTransfer()]),
        ];
    }

    /** @return list<Action> list-page header actions (no record) */
    public static function listActions(): array
    {
        return [self::policyCoverageCheck(), self::agentRegister(), self::brokerRegister()];
    }

    // ---- coverage, late report, appeal, large loss, transition -------------------------------------------------

    public static function coverageCheck(): Action
    {
        $p = 'claims.coverage.check';

        return self::make('coverageCheck', $p)->icon('lucide-shield-check')
            ->schema([
                TextInput::make('coverage_code')->label(self::f('coverage_code'))->maxLength(64),
                KeyValue::make('facts')->label(self::f('facts')),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimCoverageCheckService::class)->checkClaim(
                $record, filled($data['coverage_code'] ?? null) ? $data['coverage_code'] : null, (array) ($data['facts'] ?? []), auth()->user()), fn ($r) => (string) ($r->outcome ?? '')));
    }

    public static function coverageResolve(): Action
    {
        $p = 'claims.coverage.resolve';
        $open = fn (Claim $c) => DB::table('claim_coverage_checks')->where('claim_id', $c->id)->whereNull('resolution')->where('outcome', '!=', 'COVERAGE_CONFIRMED');

        return self::make('coverageResolve', $p)->icon('lucide-gavel')->requiresConfirmation()
            ->visible(fn (Claim $record) => $open($record)->exists())
            ->schema([
                Select::make('check_id')->label(self::f('coverage_check'))->required()
                    ->options(fn (Claim $record) => $open($record)->orderByDesc('checked_at')->get()->mapWithKeys(fn ($c) => [$c->id => $c->outcome.' · '.($c->coverage_code ?? '—').' · '.$c->checked_at])->all()),
                Select::make('resolution')->label(self::f('resolution'))->options(WorkflowAction::options(ClaimCoverageCheckService::RESOLUTIONS))->required(),
                Textarea::make('note')->label(self::f('note'))->required()->minLength(5)->maxLength(4000),
            ])
            ->action(function (Action $action, Claim $record, array $data) use ($p) {
                $s = app(ClaimCoverageCheckService::class);

                return self::run($action, $p, function () use ($s, $record, $data) {
                    $row = $s->find((string) $data['check_id']);
                    abort_if($row === null || $row->claim_id !== $record->id, 404);

                    return $s->resolve($row, $data['resolution'], $data['note'], auth()->user());
                });
            });
    }

    /** Coverage-at-loss evaluation for a policy and a loss date, before any claim exists (list page). */
    public static function policyCoverageCheck(): Action
    {
        $p = 'claims.coverage.check';

        return self::make('policyCoverageCheck', $p)->icon('lucide-shield-question')->color('gray')
            ->schema([
                Select::make('policy_id')->label(self::f('policy'))->required()->searchable()
                    ->options(fn () => Policy::where('tenant_id', self::tenant())->orderByDesc('created_at')->limit(200)->pluck('policy_number', 'id')),
                DateTimePicker::make('loss_occurred_at')->label(self::f('loss_occurred_at'))->required(),
                DateTimePicker::make('reported_at')->label(self::f('reported_at')),
                TextInput::make('coverage_code')->label(self::f('coverage_code'))->maxLength(64),
                KeyValue::make('facts')->label(self::f('facts')),
            ])
            ->action(fn (Action $action, array $data) => self::run($action, $p, fn () => app(CoverageAtLossEngine::class)->evaluate(
                Policy::where('tenant_id', self::tenant())->findOrFail($data['policy_id']), CarbonImmutable::parse($data['loss_occurred_at']),
                filled($data['reported_at'] ?? null) ? CarbonImmutable::parse($data['reported_at']) : null,
                filled($data['coverage_code'] ?? null) ? $data['coverage_code'] : null, (array) ($data['facts'] ?? [])), fn ($r) => (string) data_get($r, 'outcome', '')));
    }

    public static function lateReportRecommend(): Action
    {
        $p = 'claims.late_report.recommend';

        return self::make('lateReportRecommend', $p)->icon('lucide-clock-alert')
            ->visible(fn (Claim $record) => self::lateReport($record, ['PENDING', 'RECOMMENDED']))
            ->schema([
                Select::make('recommendation')->label(self::f('recommendation'))->options(['ACCEPT' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->required(),
                Textarea::make('rationale')->label(self::f('rationale'))->required()->maxLength(4000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p,
                fn () => app(ClaimReportingService::class)->recommend($record, $data['recommendation'], $data['rationale'], auth()->user())));
    }

    public static function lateReportDecide(): Action
    {
        $p = 'claims.late_report.approve';

        return self::make('lateReportDecide', $p)->icon('lucide-clock-check')->requiresConfirmation()
            ->visible(fn (Claim $record) => self::lateReport($record, ['RECOMMENDED']))
            ->schema([
                Select::make('decision')->label(self::f('decision'))->options(['APPROVE' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->required(),
                Textarea::make('rationale')->label(self::f('rationale'))->required()->maxLength(4000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p,
                fn () => app(ClaimReportingService::class)->decide($record, $data['decision'], $data['rationale'], auth()->user())));
    }

    public static function appeal(): Action
    {
        $p = 'claims.decision.appeal';

        return self::make('appeal', $p)->icon('lucide-megaphone')
            ->visible(fn (Claim $record) => in_array($record->status, ['DECLINED', 'PARTIALLY_APPROVED'], true))
            ->schema([
                TextInput::make('reason_code')->label(self::f('reason_code'))->required()->maxLength(64),
                Textarea::make('statement')->label(self::f('statement'))->required()->minLength(10)->maxLength(5000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p,
                fn () => app(ClaimDecisionService::class)->appeal($record, $data['reason_code'], $data['statement'], auth()->user())));
    }

    public static function largeLossCheck(): Action
    {
        $p = 'claims.view';

        return self::make('largeLossCheck', $p)->icon('lucide-triangle-alert')->requiresConfirmation()
            ->action(fn (Action $action, Claim $record) => self::run($action, $p,
                fn () => app(LargeLossNotifier::class)->check(self::tenant(), $record->id),
                fn ($r) => ! empty($r['notified']) ? __('claim_actions.largeLossCheck.notified') : __('claim_actions.largeLossCheck.reason.'.($r['reason'] ?? 'NOT_CONFIGURED'))));
    }

    public static function transition(): Action
    {
        $p = 'claims.transition';
        $targets = ['ACKNOWLEDGED', 'EVIDENCE_PENDING', 'ASSESSMENT', 'INVESTIGATING', 'CARRIER_REVIEW', 'PAYMENT_PENDING', 'CLOSED', 'REOPENED', 'REGISTERED',
            'INFORMATION_REQUIRED', 'UNDER_ASSESSMENT', 'DECISION_PENDING', 'SETTLEMENT_PENDING'];

        return self::make('transition', $p)->icon('lucide-arrow-right-left')->requiresConfirmation()
            ->schema([
                Select::make('to_status')->label(self::f('to_status'))->options(WorkflowAction::options($targets))->required(),
                TextInput::make('reason_code')->label(self::f('reason_code'))->required()->maxLength(64),
                Textarea::make('note')->label(self::f('note'))->maxLength(2000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimLifecycleService::class)->transition(
                $record, $data['to_status'], $data['reason_code'], array_filter(['note' => $data['note'] ?? null]), auth()->user())));
    }

    // ---- investigations -----------------------------------------------------------------------------------------

    public static function investigationOpen(): Action
    {
        $p = 'claims.investigation.manage';

        return self::make('investigationOpen', $p)->icon('lucide-search')
            ->visible(fn (Claim $record) => ! self::openInvestigations($record)->exists())
            ->schema([
                TextInput::make('reason_code')->label(self::f('reason_code'))->required()->maxLength(64),
                Textarea::make('reason')->label(self::f('reason'))->required()->minLength(5)->maxLength(5000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p,
                fn () => app(ClaimInvestigationService::class)->open($record, $data['reason_code'], $data['reason'], auth()->user())));
    }

    public static function investigationFindings(): Action
    {
        $p = 'claims.investigation.manage';

        return self::make('investigationFindings', $p)->icon('lucide-notebook-pen')
            ->visible(fn (Claim $record) => self::openInvestigations($record)->exists())
            ->schema([
                self::investigationSelect(),
                Textarea::make('findings')->label(self::f('findings'))->required()->minLength(5)->maxLength(20000)->rows(8),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p,
                fn () => app(ClaimInvestigationService::class)->recordFindings(self::investigation($record, $data['investigation_id']), $data['findings'], auth()->user())));
    }

    public static function investigationIndicators(): Action
    {
        $p = 'claims.investigation.manage';

        return self::make('investigationIndicators', $p)->icon('lucide-flag')
            ->visible(fn (Claim $record) => self::openInvestigations($record)->exists())
            ->schema([
                self::investigationSelect(),
                Select::make('indicator_ids')->label(self::f('indicators'))->multiple()->required()->searchable()->maxItems(100)
                    ->options(fn () => DB::table('fraud_indicators')->orderBy('code')->get()->mapWithKeys(fn ($i) => [$i->id => $i->code.' · '.$i->category.' · '.$i->severity])->all()),
            ])
            ->action(function (Action $action, Claim $record, array $data) use ($p) {
                $rows = DB::table('fraud_indicators')->whereIn('id', (array) $data['indicator_ids'])->get();
                $indicators = $rows->map(fn ($i) => ['indicator_id' => (string) $i->id, 'indicator_code' => $i->code, 'snapshot' => ['category' => $i->category, 'severity' => $i->severity]])->values()->all();

                return self::run($action, $p, fn () => app(ClaimInvestigationService::class)->attachIndicators(self::investigation($record, $data['investigation_id']), $indicators, auth()->user()));
            });
    }

    public static function investigationConclude(): Action
    {
        $p = 'claims.investigation.conclude';

        return self::make('investigationConclude', $p)->icon('lucide-circle-check-big')->requiresConfirmation()
            ->visible(fn (Claim $record) => self::openInvestigations($record)->exists())
            ->schema([
                self::investigationSelect(),
                Select::make('outcome')->label(self::f('outcome'))->options(WorkflowAction::options(ClaimInvestigationService::OUTCOMES))->required(),
                Textarea::make('summary')->label(self::f('summary'))->required()->minLength(5)->maxLength(5000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p,
                fn () => app(ClaimInvestigationService::class)->conclude(self::investigation($record, $data['investigation_id']), $data['outcome'], $data['summary'], auth()->user())));
    }

    // ---- involved parties ---------------------------------------------------------------------------------------

    public static function partyAdd(): Action
    {
        $p = 'claims.parties.manage';

        return self::make('partyAdd', $p)->icon('lucide-user-plus')
            ->schema([
                Select::make('role')->label(self::f('role'))->options(WorkflowAction::options(ClaimPartyService::ROLES))->required()->searchable(),
                TextInput::make('display_name')->label(self::f('display_name'))->required()->maxLength(255),
                Select::make('party_type')->label(self::f('party_type'))->options(WorkflowAction::options(['INDIVIDUAL', 'ORGANIZATION']))->default('INDIVIDUAL'),
                TextInput::make('contact_phone')->label(self::f('contact_phone'))->maxLength(32),
                TextInput::make('contact_email')->label(self::f('contact_email'))->email()->maxLength(255),
                Select::make('consent_basis')->label(self::f('consent_basis'))->options(WorkflowAction::options(ClaimPartyService::CONSENT_BASES)),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p,
                fn () => app(ClaimPartyService::class)->add($record, self::filled($data), auth()->user())));
    }

    public static function partyUpdate(): Action
    {
        $p = 'claims.parties.manage';

        return self::make('partyUpdate', $p)->icon('lucide-user-pen')
            ->visible(fn (Claim $record) => self::activeParties($record)->exists())
            ->schema([
                self::partySelect(),
                Select::make('role')->label(self::f('role'))->options(WorkflowAction::options(ClaimPartyService::ROLES))->searchable(),
                TextInput::make('display_name')->label(self::f('display_name'))->maxLength(255),
                TextInput::make('contact_phone')->label(self::f('contact_phone'))->maxLength(32),
                TextInput::make('contact_email')->label(self::f('contact_email'))->email()->maxLength(255),
                Textarea::make('reason')->label(self::f('reason'))->required()->minLength(5)->maxLength(500),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimPartyService::class)->update(
                self::activeParties($record)->whereKey($data['party_row_id'])->firstOrFail(), self::filled(array_diff_key($data, ['party_row_id' => 1])), auth()->user())));
    }

    public static function partyRemove(): Action
    {
        $p = 'claims.parties.manage';

        return self::make('partyRemove', $p)->icon('lucide-user-minus')->color('danger')->requiresConfirmation()
            ->visible(fn (Claim $record) => self::activeParties($record)->exists())
            ->schema([
                self::partySelect(),
                Textarea::make('reason')->label(self::f('reason'))->required()->minLength(5)->maxLength(500),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimPartyService::class)->remove(
                self::activeParties($record)->whereKey($data['party_row_id'])->firstOrFail(), $data['reason'], auth()->user())));
    }

    // ---- experts --------------------------------------------------------------------------------------------------

    public static function expertAssign(): Action
    {
        $p = 'claims.experts.assign';

        return self::make('expertAssign', $p)->icon('lucide-hard-hat')
            ->schema([
                Select::make('provider_id')->label(self::f('expert'))->required()->searchable()
                    ->options(fn () => DB::table('provider_profiles')->join('parties', 'parties.id', '=', 'provider_profiles.party_id')->whereIn('provider_profiles.category', ExpertAssignmentLifecycle::PROVIDER_CATEGORIES)->where('provider_profiles.credentialing_status', 'ACTIVE')->orderBy('parties.display_name')->limit(300)->pluck('parties.display_name', 'provider_profiles.id')->all()),
                Select::make('network_id')->label(self::f('network'))->required()
                    ->options(fn () => DB::table('provider_networks')->where('tenant_id', self::tenant())->orderBy('code')->get()->mapWithKeys(fn ($n) => [$n->id => $n->code.' · '.$n->name])->all()),
                Select::make('fee_service_id')->label(self::f('fee_service'))->required()->searchable()
                    ->options(fn () => DB::table('medical_services')->where('status', 'ACTIVE')->orderBy('code')->limit(500)->get()->mapWithKeys(fn ($s) => [$s->id => $s->code.' · '.$s->name])->all()),
                Textarea::make('instructions')->label(self::f('instructions'))->maxLength(5000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ExpertAssignmentService::class)->assign($record, [
                'provider_id' => $data['provider_id'], 'network_id' => $data['network_id'], 'fee_service_id' => $data['fee_service_id'], 'instructions' => $data['instructions'] ?? null,
            ], auth()->user())));
    }

    public static function expertCancel(): Action
    {
        $p = 'claims.experts.assign';
        $live = fn (Claim $c) => self::assignments($c)->whereNotIn('status', ExpertAssignmentLifecycle::TERMINAL);

        return self::make('expertCancel', $p)->icon('lucide-circle-x')->color('danger')->requiresConfirmation()
            ->visible(fn (Claim $record) => $live($record)->exists())
            ->schema([
                Select::make('assignment_id')->label(self::f('assignment'))->required()->options(fn (Claim $record) => self::assignmentOptions($live($record))),
                Textarea::make('reason')->label(self::f('reason'))->required()->minLength(5)->maxLength(5000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ExpertAssignmentService::class)->cancel(
                self::tenant(), (string) ($live($record)->where('claim_assignments.id', $data['assignment_id'])->value('claim_assignments.id') ?: abort(404)), $data['reason'], auth()->user())));
    }

    /** Accept or return a submitted expert report (one action; the outcome is chosen in the form). */
    public static function expertReview(): Action
    {
        $p = 'claims.experts.review';
        $submitted = fn (Claim $c) => self::assignments($c)->where('status', 'REPORT_SUBMITTED');

        return self::make('expertReview', $p)->icon('lucide-file-check')->requiresConfirmation()
            ->visible(fn (Claim $record) => $submitted($record)->exists())
            ->schema([
                Select::make('assignment_id')->label(self::f('assignment'))->required()->options(fn (Claim $record) => self::assignmentOptions($submitted($record))),
                Select::make('outcome')->label(self::f('outcome'))->options(['ACCEPT' => __('workflow_actions.accept'), 'RETURN' => __('workflow_actions.return_to_maker')])->required()->live(),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(5000)->minLength(fn (callable $get) => $get('outcome') === 'RETURN' ? 5 : null)
                    ->required(fn (callable $get) => $get('outcome') === 'RETURN'),
            ])
            ->action(function (Action $action, Claim $record, array $data) use ($p, $submitted) {
                $id = (string) ($submitted($record)->where('claim_assignments.id', $data['assignment_id'])->value('claim_assignments.id') ?: abort(404));
                $s = app(ExpertAssignmentService::class);

                return self::run($action, $p, fn () => $data['outcome'] === 'ACCEPT'
                    ? $s->acceptReport(self::tenant(), $id, filled($data['notes'] ?? null) ? $data['notes'] : null, auth()->user())
                    : $s->returnReport(self::tenant(), $id, (string) $data['notes'], auth()->user()));
            });
    }

    // ---- evidence -----------------------------------------------------------------------------------------------

    public static function evidenceAttach(): Action
    {
        $p = 'claims.evidence.manage';
        $candidates = fn (Claim $c) => app(SubjectDocuments::class)->query('CLAIM', $c->id)
            ->whereNotIn('documents.id', DB::table('claim_documents')->where('claim_id', $c->id)->select('document_id'));

        return self::make('evidenceAttach', $p)->icon('lucide-paperclip')
            ->visible(fn (Claim $record) => $candidates($record)->exists())
            ->schema([
                Select::make('document_id')->label(self::f('document'))->required()
                    ->options(fn (Claim $record) => $candidates($record)->select('documents.id', 'documents.category')->get()->mapWithKeys(fn ($d) => [$d->id => $d->category.' · '.Str::limit($d->id, 8, '')])->all()),
                TextInput::make('evidence_type')->label(self::f('evidence_type'))->required()->maxLength(64),
                TextInput::make('purpose')->label(self::f('purpose'))->required()->maxLength(96)->default('CLAIM_ASSESSMENT'),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimEvidenceService::class)->attach(
                $record, Document::where('tenant_id', $record->tenant_id)->findOrFail($data['document_id']), $data['evidence_type'], $data['purpose'], auth()->user())));
    }

    public static function evidenceVerify(): Action
    {
        $p = 'claims.evidence.verify';

        return self::make('evidenceVerify', $p)->icon('lucide-file-badge')->requiresConfirmation()
            ->visible(fn (Claim $record) => self::submittedEvidence($record)->exists())
            ->schema([
                self::evidenceSelect(),
                Toggle::make('accepted')->label(self::f('accepted'))->default(true),
                TextInput::make('purpose')->label(self::f('purpose'))->required()->maxLength(96),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimEvidenceService::class)->verify(
                $record, self::evidenceDocument($record, $data['document_id']), ! empty($data['accepted']), $data['purpose'], auth()->user())));
    }

    public static function evidenceReview(): Action
    {
        $p = 'claims.evidence.verify';

        return self::make('evidenceReview', $p)->icon('lucide-file-search')->requiresConfirmation()
            ->visible(fn (Claim $record) => self::submittedEvidence($record)->exists())
            ->schema([
                self::evidenceSelect(),
                Select::make('decision')->label(self::f('decision'))->options(['ACCEPT' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->required()->live(),
                TextInput::make('reason_code')->label(self::f('reason_code'))->maxLength(64)->required(fn (callable $get) => $get('decision') === 'REJECT'),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimEvidenceReviewService::class)->review(
                $record, self::evidenceDocument($record, $data['document_id']), $data['decision'], $data['reason_code'] ?? null, $data['notes'] ?? null, auth()->user())));
    }

    // ---- carrier exchange and execution -------------------------------------------------------------------------

    public static function carrierQueue(): Action
    {
        $p = 'claims.carrier.exchange';

        return self::make('carrierQueue', $p)->icon('lucide-send')
            ->schema([
                TextInput::make('message_type')->label(self::f('message_type'))->required()->maxLength(48)->default('CLAIM_SUBMISSION'),
                KeyValue::make('payload')->label(self::f('payload'))->required(),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimCarrierExchangeService::class)->queue(
                $record, $data['message_type'], (array) $data['payload'], (string) Str::uuid(), auth()->user())));
    }

    /** Record the carrier's acknowledgement of, or a delivery failure for, an outbound message. */
    public static function carrierCallback(): Action
    {
        $p = 'claims.carrier.callback';
        $pending = fn (Claim $c) => DB::table('carrier_exchange_messages')->where(['claim_id' => $c->id, 'direction' => 'OUTBOUND'])->whereIn('status', ['QUEUED', 'SENT', 'RETRY_PENDING']);
        $ack = fn (callable $get) => $get('outcome') === 'ACKNOWLEDGED';
        $failed = fn (callable $get) => $get('outcome') === 'FAILED';

        return self::make('carrierCallback', $p)->icon('lucide-inbox')
            ->visible(fn (Claim $record) => $pending($record)->exists())
            ->schema([
                Select::make('message_id')->label(self::f('message'))->required()
                    ->options(fn (Claim $record) => $pending($record)->orderByDesc('created_at')->get()->mapWithKeys(fn ($m) => [$m->id => $m->message_type.' · '.$m->status.' · '.$m->created_at])->all()),
                Select::make('outcome')->label(self::f('outcome'))->options(WorkflowAction::options(['ACKNOWLEDGED', 'FAILED']))->required()->live(),
                TextInput::make('external_reference')->label(self::f('external_reference'))->maxLength(255)->visible($ack)->required($ack),
                Textarea::make('reason')->label(self::f('reason'))->maxLength(2000)->visible($failed)->required($failed),
            ])
            ->action(function (Action $action, Claim $record, array $data) use ($p) {
                $s = app(ClaimCarrierExchangeService::class);

                return self::run($action, $p, fn () => $data['outcome'] === 'ACKNOWLEDGED'
                    ? $s->acknowledge($record, $data['message_id'], (string) $data['external_reference'])
                    : $s->markFailure($record, $data['message_id'], (string) $data['reason']));
            });
    }

    public static function carrierManualEntry(): Action
    {
        $p = 'claims.carrier.manual_entry';
        $is = fn (string $t) => fn (callable $get) => $get('message_type') === $t;

        return self::make('carrierManualEntry', $p)->icon('lucide-keyboard')
            ->schema([
                Select::make('message_type')->label(self::f('message_type'))->options(WorkflowAction::options(ClaimExecutionService::INBOUND_TYPES))->required()->live(),
                TextInput::make('external_reference')->label(self::f('external_reference'))->maxLength(255)->required($is('CLAIM_ACKNOWLEDGEMENT')),
                Select::make('outcome')->label(self::f('outcome'))->options(WorkflowAction::options(ClaimExecutionService::DECISION_OUTCOMES))->visible($is('CLAIM_DECISION'))->required($is('CLAIM_DECISION')),
                TextInput::make('amount_minor')->label(self::f('amount_minor'))->integer()->minValue(0)->visible($is('CLAIM_DECISION')),
                Textarea::make('decision_reason')->label(self::f('reason'))->maxLength(2000)->visible($is('CLAIM_DECISION')),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
            ])
            ->action(function (Action $action, Claim $record, array $data) use ($p) {
                $input = array_filter(['message_type' => $data['message_type'], 'external_reference' => $data['external_reference'] ?? null, 'notes' => $data['notes'] ?? null], fn ($v) => filled($v));
                if ($data['message_type'] === 'CLAIM_DECISION') {
                    $input['decision'] = array_filter(['outcome' => $data['outcome'] ?? null, 'amount_minor' => filled($data['amount_minor'] ?? null) ? (int) $data['amount_minor'] : null,
                        'currency' => $record->currency, 'reason' => $data['decision_reason'] ?? null], fn ($v) => $v !== null && $v !== '');
                }

                return self::run($action, $p, fn () => app(ClaimExecutionService::class)->proposeManualEntry($record, $input, auth()->user()));
            });
    }

    public static function carrierReviewEntry(): Action
    {
        $p = 'claims.carrier.manual_approve';
        $pending = fn (Claim $c) => DB::table('claim_carrier_manual_entries')->where(['claim_id' => $c->id, 'status' => 'PENDING_APPROVAL']);

        return self::make('carrierReviewEntry', $p)->icon('lucide-badge-check')->requiresConfirmation()
            ->visible(fn (Claim $record) => $pending($record)->exists())
            ->schema([
                Select::make('entry_id')->label(self::f('entry'))->required()
                    ->options(fn (Claim $record) => $pending($record)->orderBy('created_at')->get()->mapWithKeys(fn ($e) => [$e->id => $e->message_type.' · '.$e->created_at])->all()),
                Select::make('outcome')->label(self::f('outcome'))->options(['APPROVE' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->required()->live(),
                Textarea::make('reason')->label(self::f('reason'))->maxLength(2000)->required(fn (callable $get) => $get('outcome') === 'REJECT'),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimExecutionService::class)->reviewManualEntry(
                $record, $data['entry_id'], $data['outcome'] === 'APPROVE', auth()->user(), $data['outcome'] === 'APPROVE' ? null : (string) $data['reason'])));
    }

    public static function executionSubmit(): Action
    {
        $p = 'claims.carrier.exchange';

        return self::make('executionSubmit', $p)->icon('lucide-upload')->requiresConfirmation()
            ->schema([
                TextInput::make('portal_reference')->label(self::f('portal_reference'))->maxLength(255),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
            ])
            ->action(function (Action $action, Claim $record, array $data) use ($p) {
                $key = (string) Str::uuid();

                return self::run($action, $p, fn () => app(ClaimExecutionService::class)->submit($record, $key, auth()->user(),
                    ['idempotency_key' => $key, 'portal_reference' => $data['portal_reference'] ?? null, 'notes' => $data['notes'] ?? null]));
            });
    }

    // ---- settlement, payment execution, recovery transfer -------------------------------------------------------

    public static function settlementDischarge(): Action
    {
        $p = 'claims.settlement.discharge';
        $accepted = fn (Claim $c) => self::settlements($c)->where('status', 'ACCEPTED');

        return self::make('settlementDischarge', $p)->icon('lucide-signature')->requiresConfirmation()
            ->visible(fn (Claim $record) => $accepted($record)->exists())
            ->schema([self::settlementSelect($accepted)])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimSettlementService::class)->requestDischarge(
                self::pick($accepted($record), $data['settlement_id']), auth()->user())));
    }

    public static function settlementConfirmDischarge(): Action
    {
        $p = 'claims.settlement.discharge';
        $awaiting = fn (Claim $c) => self::settlements($c)->where('status', 'ACCEPTED')->whereNotNull('signature_request_id');

        return self::make('settlementConfirmDischarge', $p)->icon('lucide-file-signature')->requiresConfirmation()
            ->visible(fn (Claim $record) => $awaiting($record)->exists())
            ->schema([self::settlementSelect($awaiting)])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimSettlementService::class)->confirmDischarge(
                self::pick($awaiting($record), $data['settlement_id']), auth()->user())));
    }

    public static function settlementPayment(): Action
    {
        $p = 'claims.settlement.pay';
        $signed = fn (Claim $c) => self::settlements($c)->where('status', 'DISCHARGE_SIGNED');

        return self::make('settlementPayment', $p)->icon('lucide-circle-dollar-sign')->requiresConfirmation()
            ->visible(fn (Claim $record) => $signed($record)->exists())
            ->schema([self::settlementSelect($signed)])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimSettlementService::class)->requestPayment(
                self::pick($signed($record), $data['settlement_id']), auth()->user())));
    }

    /** Manual payment-rail step: mark an approved payment as processing, or a processing one as failed. */
    public static function paymentExecution(): Action
    {
        $p = 'claims.payment.execute';
        $open = fn (Claim $c) => ClaimPayment::where('claim_id', $c->id)->whereIn('status', ['APPROVED', 'RETRY_PENDING', 'PROCESSING']);
        $failing = fn (callable $get) => $get('operation') === 'FAILED';

        return self::make('paymentExecution', $p)->icon('lucide-landmark')->requiresConfirmation()
            ->visible(fn (Claim $record) => $open($record)->exists())
            ->schema([
                Select::make('payment_id')->label(self::f('payment'))->required()
                    ->options(fn (Claim $record) => $open($record)->get()->mapWithKeys(fn ($x) => [$x->id => number_format((int) $x->amount_minor).' '.$x->currency.' · '.$x->status])),
                Select::make('operation')->label(self::f('operation'))->options(WorkflowAction::options(['PROCESSING', 'FAILED']))->required()->live(),
                Textarea::make('reason')->label(self::f('reason'))->maxLength(2000)->visible($failing)->required($failing),
            ])
            ->action(function (Action $action, Claim $record, array $data) use ($p, $open) {
                $payment = $open($record)->whereKey($data['payment_id'])->firstOrFail();
                $s = app(ClaimPaymentService::class);

                return self::run($action, $p, fn () => $data['operation'] === 'PROCESSING' ? $s->processing($payment) : $s->fail($payment, (string) $data['reason']));
            });
    }

    public static function recoveryTransfer(): Action
    {
        $p = 'claims.close';
        $open = fn (Claim $c) => DB::table('claim_recoveries')->where(['claim_id' => $c->id, 'status' => 'OPEN']);

        return self::make('recoveryTransfer', $p)->icon('lucide-arrow-right-left')->requiresConfirmation()
            ->visible(fn (Claim $record) => $open($record)->exists())
            ->schema([
                Select::make('recovery_id')->label(self::f('recovery'))->required()
                    ->options(fn (Claim $record) => $open($record)->get()->mapWithKeys(fn ($r) => [$r->id => $r->reference.' · '.$r->type.' · '.$r->counterparty_name])->all()),
                TextInput::make('transferee')->label(self::f('transferee'))->required()->maxLength(255),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => self::run($action, $p, fn () => app(ClaimClosureService::class)->transferRecovery(
                self::pick($open($record), $data['recovery_id']), $data['transferee'], auth()->user())));
    }

    // ---- intermediary-assisted FNOL (list page) -----------------------------------------------------------------

    public static function agentRegister(): Action
    {
        return self::assistedFnol('agentRegister', 'agent.clients.manage', fn ($s, array $d) => $s->submitForCustomer(self::tenant(), $d, auth()->user()),
            fn (PartnerWorkspaceScope $scope) => $scope->bookPartyIds(auth()->user(), $scope->activeAgent(auth()->user())));
    }

    public static function brokerRegister(): Action
    {
        return self::assistedFnol('brokerRegister', 'broker.claims.file', fn ($s, array $d) => $s->submitForBrokerClient(self::tenant(), $d, auth()->user()),
            fn (PartnerWorkspaceScope $scope) => ($b = $scope->broker(auth()->user())) ? $scope->bookPartyIds(auth()->user(), $b) : []);
    }

    private static function assistedFnol(string $name, string $p, callable $submit, callable $book): Action
    {
        $policies = fn () => Policy::where('tenant_id', self::tenant())->whereIn('status', ['ACTIVE', 'EXPIRING', 'ENDORSEMENT_PENDING'])
            ->whereIn('party_id', rescue(fn () => $book(app(PartnerWorkspaceScope::class)), [], false) ?: ['00000000-0000-0000-0000-000000000000']);

        return self::make($name, $p)->icon('lucide-file-plus')->color('gray')
            ->schema([
                Select::make('policy_id')->label(self::f('policy'))->required()->searchable()->options(fn () => $policies()->limit(200)->pluck('policy_number', 'id')),
                DateTimePicker::make('loss_occurred_at')->label(self::f('loss_occurred_at'))->required()->maxDate(now()),
                TextInput::make('loss_location')->label(self::f('loss_location'))->maxLength(255),
                Textarea::make('description')->label(self::f('description'))->required()->maxLength(5000),
                TextInput::make('estimated_loss_minor')->label(self::f('estimated_loss_minor'))->integer()->minValue(0),
            ])
            ->action(function (Action $action, array $data) use ($p, $submit, $policies) {
                $policy = $policies()->findOrFail($data['policy_id']);

                return self::run($action, $p, fn () => $submit(app(FnolService::class), [
                    'policy_id' => $policy->id, 'claimant_party_id' => $policy->party_id, 'loss_occurred_at' => $data['loss_occurred_at'],
                    'loss_details' => ['description' => $data['description']], 'loss_location' => $data['loss_location'] ?? null,
                    'estimated_loss_minor' => filled($data['estimated_loss_minor'] ?? null) ? (int) $data['estimated_loss_minor'] : null,
                    'idempotency_key' => (string) Str::uuid(),
                ]));
            });
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /** WorkflowAction with this class's labels (claim_actions.{name}.label|help). */
    public static function make(string $name, ?string $permission): Action
    {
        $help = __("claim_actions.{$name}.help");

        return WorkflowAction::make($name, $permission)
            ->label(__("claim_actions.{$name}.label"))
            ->modalHeading(__("claim_actions.{$name}.label"))
            ->modalDescription($help === "claim_actions.{$name}.help" ? null : $help);
    }

    /** WorkflowAction::run with this class's success message, optionally followed by a detail from the result. */
    private static function run(Action $action, string $permission, callable $call, ?callable $detail = null): mixed
    {
        $title = __("claim_actions.{$action->getName()}.done");
        // The claim state machine refuses an invalid move with a DomainException: show it like any other refusal.
        $guarded = function () use ($call) {
            try {
                return $call();
            } catch (\DomainException $e) {
                throw ValidationException::withMessages(['status' => $e->getMessage()]);
            }
        };
        $result = WorkflowAction::run($action, $permission, $guarded, $title);
        if ($detail === null) {
            return $result;
        }
        $body = $detail($result);
        if ($body !== '') {
            Notification::make()->info()->title($title)->body($body)->send();
        }

        return $result;
    }

    private static function f(string $key): string
    {
        return __('claim_actions.fields.'.$key);
    }

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    /** @param  array<string, mixed>  $data */
    private static function filled(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    private static function pick($query, mixed $id): string
    {
        return (string) ((clone $query)->where('id', $id)->value('id') ?: abort(404));
    }

    private static function lateReport(Claim $c, array $statuses): bool
    {
        return DB::table('claim_reporting_checks')->where('claim_id', $c->id)->where('late', true)->whereIn('approval_status', $statuses)->exists();
    }

    private static function openInvestigations(Claim $c)
    {
        return ClaimInvestigation::where(['claim_id' => $c->id, 'status' => 'OPEN']);
    }

    private static function investigationSelect(): Select
    {
        return Select::make('investigation_id')->label(self::f('investigation'))->required()
            ->options(fn (Claim $record) => self::openInvestigations($record)->get()->mapWithKeys(fn ($i) => [$i->id => $i->reason_code.' · '.optional($i->created_at)->toDateString()]));
    }

    private static function investigation(Claim $c, mixed $id): ClaimInvestigation
    {
        return self::openInvestigations($c)->whereKey($id)->firstOrFail();
    }

    private static function activeParties(Claim $c)
    {
        return ClaimInvolvedParty::where('claim_id', $c->id)->whereNull('removed_at');
    }

    private static function partySelect(): Select
    {
        return Select::make('party_row_id')->label(self::f('involved_party'))->required()
            ->options(fn (Claim $record) => self::activeParties($record)->get()->mapWithKeys(fn ($r) => [$r->id => $r->role.' · '.($r->display_name ?? '—')]));
    }

    private static function assignments(Claim $c)
    {
        return DB::table('claim_assignments')->where('claim_id', $c->id)->where('assignment_type', 'EXPERT');
    }

    private static function assignmentOptions($query): array
    {
        return $query->leftJoin('provider_profiles', 'provider_profiles.id', '=', 'claim_assignments.provider_profile_id')->leftJoin('parties', 'parties.id', '=', 'provider_profiles.party_id')
            ->select('claim_assignments.id', 'claim_assignments.status', 'parties.display_name as name')->get()
            ->mapWithKeys(fn ($a) => [$a->id => ($a->name ?? '—').' · '.$a->status])->all();
    }

    private static function submittedEvidence(Claim $c)
    {
        return DB::table('claim_documents')->where(['claim_id' => $c->id, 'status' => 'SUBMITTED']);
    }

    private static function evidenceSelect(): Select
    {
        return Select::make('document_id')->label(self::f('document'))->required()
            ->options(fn (Claim $record) => self::submittedEvidence($record)->leftJoin('documents', 'documents.id', '=', 'claim_documents.document_id')
                ->select('claim_documents.document_id', 'claim_documents.evidence_type', 'documents.category')->get()
                ->mapWithKeys(fn ($d) => [$d->document_id => $d->evidence_type.' · '.($d->category ?? '').' · '.Str::limit($d->document_id, 8, '')])->all());
    }

    private static function evidenceDocument(Claim $c, mixed $id): Document
    {
        abort_unless(self::submittedEvidence($c)->where('document_id', $id)->exists(), 404);

        return Document::where('tenant_id', $c->tenant_id)->findOrFail($id);
    }

    private static function settlements(Claim $c)
    {
        return DB::table('claim_settlements')->where('claim_id', $c->id);
    }

    private static function settlementSelect(callable $query): Select
    {
        return Select::make('settlement_id')->label(self::f('settlement'))->required()
            ->options(fn (Claim $record) => $query($record)->get()->mapWithKeys(fn ($s) => [$s->id => $s->reference.' · '.number_format((int) $s->amount_minor).' '.$s->currency.' · '.$s->status])->all());
    }
}
