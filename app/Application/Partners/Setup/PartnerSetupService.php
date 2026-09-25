<?php

declare(strict_types=1);

namespace App\Application\Partners\Setup;

use App\Application\Approvals\ApprovalService;
use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseProblem;
use App\Application\CarrierOperations\Setup\CarrierSetupService;
use App\Application\CarrierOperations\Setup\SetupChecklist;
use App\Application\Partners\PartnerStatusService;
use App\Application\Partners\Setup\Models\PartnerSetup;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\StateMachine\Contracts\TransitionEventPublisher;
use App\Domain\Shared\StateMachine\Contracts\TransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\GuardResult;
use App\Domain\Shared\StateMachine\StateMachineDefinition;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionDenied;
use App\Domain\Tenancy\TenantContext;
use App\Models\ApprovalRequest;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-SET-003 — broker setup lifecycle DRAFT → REVIEW → CONFIGURATION → TESTING → ACTIVE;
 * SUSPENDED, TERMINATED (SETUP_CONFIGURATION_FRAMEWORK_V1). 19-item activation checklist
 * following the framework's broker configuration sections 29–50 (owner's verbatim list not in
 * repo). partners.status stays the operational flag: it is synced through the existing
 * PartnerStatusService (partner_status_history) — no second status history.
 */
final class PartnerSetupService
{
    public const SETUP_TYPE = 'PARTNER';

    public const MACHINE = 'broker_setup';

    public const PARTNER_TYPES = ['BROKER'];

    /** setup status => partners.status (partners vocabulary: PENDING/ACTIVE/SUSPENDED/REJECTED) */
    private const PARTNER_STATUS = ['ACTIVE' => 'ACTIVE', 'SUSPENDED' => 'SUSPENDED', 'TERMINATED' => 'SUSPENDED'];

    private readonly StateMachineEngine $engine;

    private static ?StateMachineDefinition $machine = null;

    public function __construct(
        TransitionHistoryRecorder $history,
        TransitionEventPublisher $events,
        private readonly SetupChecklist $checklist,
        private readonly PartnerStatusService $partnerStatus,
        private readonly AuditWriter $audit,
        private readonly Clock $clock,
        private readonly ApprovalService $approvals,
        private readonly CarrierSetupService $carrierSetups,
    ) {
        $this->engine = new StateMachineEngine($history, $events, CarrierSetupService::permissionChecker());
        $this->engine->registerGuard('review_passed', fn ($t, TransitionContext $c) => $this->gate($c->subject, ['PROFILE_AND_REGULATORY']));
        $this->engine->registerGuard('checklist_complete', fn ($t, TransitionContext $c) => $this->gate($c->subject, null));
        $this->engine->registerGuard('approval_granted', function ($t, TransitionContext $c) {
            $req = $c->subject->approval_request_id ? ApprovalRequest::find($c->subject->approval_request_id) : null;

            return $req !== null && $this->approvals->isApproved($req)
                ? GuardResult::pass()
                : GuardResult::fail('Activation needs an APPROVED broker_setup.activate request in the approval inbox.');
        });
        $this->engine->registerGuard('maker_checker', function ($t, TransitionContext $c) {
            $maker = $c->subject->submitted_for_activation_by;

            return $maker !== null && $maker === $c->actorId()
                ? GuardResult::fail('Maker-checker: the user who moved the broker to testing cannot activate it.')
                : GuardResult::pass();
        });
    }

    public static function machine(): StateMachineDefinition
    {
        $manage = 'partner_setup.manage';
        $approve = 'partner_setup.approve';

        return self::$machine ??= StateMachineDefinition::fromArray([
            'name' => self::MACHINE, 'version' => 1, 'subject_type' => 'partner_setup',
            'states' => ['DRAFT' => ['initial' => true], 'REVIEW' => [], 'CONFIGURATION' => [], 'TESTING' => [], 'ACTIVE' => [], 'SUSPENDED' => [], 'TERMINATED' => ['terminal' => true]],
            'transitions' => [
                ['event' => 'submit_review', 'from' => ['DRAFT'], 'to' => 'REVIEW', 'permission' => $manage, 'domain_event' => 'broker.setup.review_requested'],
                ['event' => 'review_passed', 'from' => ['REVIEW'], 'to' => 'CONFIGURATION', 'permission' => $approve, 'guards' => ['review_passed'], 'domain_event' => 'broker.setup.review_passed'],
                ['event' => 'review_rejected', 'from' => ['REVIEW'], 'to' => 'DRAFT', 'permission' => $approve, 'domain_event' => 'broker.setup.review_rejected'],
                ['event' => 'start_testing', 'from' => ['CONFIGURATION'], 'to' => 'TESTING', 'permission' => $manage, 'guards' => ['checklist_complete'], 'domain_event' => 'broker.setup.testing_started'],
                ['event' => 'back_to_configuration', 'from' => ['TESTING'], 'to' => 'CONFIGURATION', 'permission' => $manage, 'domain_event' => 'broker.setup.returned_to_configuration'],
                ['event' => 'activate', 'from' => ['TESTING'], 'to' => 'ACTIVE', 'permission' => $approve, 'guards' => ['checklist_complete', 'maker_checker', 'approval_granted'], 'domain_event' => 'broker.setup.activated'],
                ['event' => 'reject_activation', 'from' => ['TESTING'], 'to' => 'CONFIGURATION', 'permission' => $approve, 'domain_event' => 'broker.setup.activation_rejected'],
                ['event' => 'suspend', 'from' => ['ACTIVE'], 'to' => 'SUSPENDED', 'permission' => $approve, 'domain_event' => 'broker.setup.suspended'],
                ['event' => 'reinstate', 'from' => ['SUSPENDED'], 'to' => 'ACTIVE', 'permission' => $approve, 'guards' => ['checklist_complete'], 'domain_event' => 'broker.setup.reinstated'],
                ['event' => 'terminate', 'from' => ['DRAFT', 'REVIEW', 'CONFIGURATION', 'TESTING', 'ACTIVE', 'SUSPENDED'], 'to' => 'TERMINATED', 'permission' => $approve, 'domain_event' => 'broker.setup.terminated'],
            ],
        ]);
    }

    public function open(Partner $partner, User $actor, ?string $notes = null): PartnerSetup
    {
        if (! in_array($partner->type, self::PARTNER_TYPES, true)) {
            throw ValidationException::withMessages(['partner' => 'The broker setup lifecycle applies to BROKER partners only.']);
        }
        if (PartnerSetup::where('partner_id', $partner->id)->exists()) {
            throw ValidationException::withMessages(['partner' => 'This broker already has a setup record.']);
        }
        $setup = PartnerSetup::create(['partner_id' => $partner->id, 'tenant_id' => $partner->tenant_id, 'status' => 'DRAFT', 'created_by' => $actor->id, 'notes' => $notes]);
        $this->audit->record('broker.setup.opened', 'partner_setup', $setup->id, ['partner_id' => $partner->id]);

        return $setup;
    }

    public function transition(PartnerSetup $setup, string $event, User $actor, string $reason): PartnerSetup
    {
        return DB::transaction(function () use ($setup, $event, $actor, $reason) {
            $setup = PartnerSetup::whereKey($setup->id)->lockForUpdate()->firstOrFail();
            $ctx = new TransitionContext('partner_setup', $setup->id, $setup->status, $actor, null, ['partner_id' => $setup->partner_id], $reason, $setup);
            try {
                $result = $this->engine->apply(self::machine(), $event, $ctx);
            } catch (TransitionDenied $e) {
                throw CaseProblem::fromDenied($e); // shared REQ-API-003 envelope for state-machine denials
            }
            $now = $this->clock->now();
            $attrs = ['status' => $result->to];
            if ($event === 'start_testing') {
                $req = $this->approvals->open($actor, ['action_code' => 'broker_setup.activate', 'subject_type' => 'partner_setup', 'subject_id' => $setup->id,
                    'reason' => $reason, 'payload' => ['partner_id' => $setup->partner_id], 'excluded_user_ids' => [$actor->id],
                    'tenant_id' => $setup->tenant_id ?? rescue(fn () => app(TenantContext::class)->id(), null, false)]);
                $attrs += ['submitted_for_activation_by' => $actor->id, 'submitted_for_activation_at' => $now, 'approval_request_id' => $req->id];
            }
            if (in_array($event, ['back_to_configuration', 'reject_activation'], true)) {
                $attrs += ['submitted_for_activation_by' => null, 'submitted_for_activation_at' => null, 'approval_request_id' => null];
            }
            if ($event === 'activate') {
                $attrs += ['approved_by' => $actor->id, 'activated_at' => $now];
            }
            $setup->forceFill($attrs)->save();
            if ($event === 'activate') {
                $setup->forceFill(['activation_snapshot' => ['checklist' => $this->evaluate($setup), 'at' => $now->toIso8601String()]])->save();
            }
            if (isset(self::PARTNER_STATUS[$result->to])) {
                $this->partnerStatus->transition(Partner::findOrFail($setup->partner_id), self::PARTNER_STATUS[$result->to], "Broker setup {$event}: {$reason}", $actor);
            }
            $this->audit->record('broker.setup.'.$event, 'partner_setup', $setup->id, ['from' => $result->from, 'to' => $result->to, 'partner_id' => $setup->partner_id], $reason);

            return $setup->refresh();
        });
    }

    /** @return list<string> */
    public function available(PartnerSetup $setup, User $actor): array
    {
        $ctx = new TransitionContext('partner_setup', $setup->id, $setup->status, $actor, null, [], null, $setup);

        return array_map(fn ($t) => $t->event, $this->engine->available(self::machine(), $ctx));
    }

    public function evaluate(PartnerSetup $setup): array
    {
        return $this->checklist->evaluate(self::SETUP_TYPE, $setup->id, $this->items($setup));
    }

    public function attest(PartnerSetup $setup, string $code, string $status, ?string $evidence, ?string $notes, User $actor): array
    {
        if (in_array($setup->status, ['ACTIVE', 'TERMINATED'], true)) {
            throw ValidationException::withMessages(['status' => "Checklist is frozen while the broker setup is {$setup->status}."]);
        }
        $this->checklist->attest(self::SETUP_TYPE, $setup->id, $this->items($setup), $code, $status, $evidence, $notes, $actor);

        return $this->evaluate($setup);
    }

    /** @return list<array<string,mixed>> the 19 ordered items */
    public function items(PartnerSetup $setup): array
    {
        $pid = $setup->partner_id;
        $today = $this->clock->today()->toDateString();

        return [
            ['code' => 'PROFILE_AND_REGULATORY', 'label' => 'Broker profile and verified intermediary licence',
                'check' => fn () => DB::table('partner_licences')->where('partner_id', $pid)->where('status', 'VERIFIED')
                    ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', $today))->exists()],
            ['code' => 'BRANCHES', 'label' => 'Branches', 'na' => true],
            ['code' => 'DEPARTMENTS', 'label' => 'Departments', 'na' => true],
            ['code' => 'STAFF_AND_PERMISSIONS', 'label' => 'Staff, permissions and thresholds'],
            ['code' => 'AGENT_HIERARCHY', 'label' => 'Agent types and hierarchy', 'na' => true],
            ['code' => 'CARRIER_PORTFOLIO', 'label' => 'Carrier portfolio (active carrier_broker_agreements)',
                'check' => fn () => DB::table('carrier_broker_agreements')->where('partner_id', $pid)->where('status', 'ACTIVE')
                    ->where('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today))->exists()],
            ['code' => 'PRODUCT_CATALOGUE', 'label' => 'Catalogue derived from carrier agreements (never invented)',
                'check' => fn () => $this->carrierSetups->authorizedAgreementLines(partnerId: $pid) > 0],
            ['code' => 'INTERNAL_PRODUCT_CONTROLS', 'label' => 'Internal product controls', 'na' => true],
            ['code' => 'COMMISSION_SPLIT', 'label' => 'Internal commission split'],
            ['code' => 'CUSTOMER_OWNERSHIP_AND_LEADS', 'label' => 'Customer ownership and lead assignment rules'],
            ['code' => 'QUOTE_CONTROLS', 'label' => 'Quote controls'],
            ['code' => 'SERVICING_PERMISSIONS', 'label' => 'Servicing permissions'],
            ['code' => 'CLAIMS_ROUTING', 'label' => 'Claims routing'],
            ['code' => 'PREMIUM_COLLECTION', 'label' => 'Premium collection'],
            ['code' => 'INSURER_SETTLEMENT', 'label' => 'Per-insurer settlement'],
            ['code' => 'STICKER_CHAIN', 'label' => 'Sticker chain (carrier → broker → branch → agent)', 'na' => true],
            ['code' => 'BROKER_DOCUMENTS', 'label' => 'Broker documents', 'evidence' => true],
            ['code' => 'ACCOUNTING', 'label' => 'Accounting setup'],
            ['code' => 'TARGETS', 'label' => 'Targets', 'na' => true],
        ];
    }

    private function gate(PartnerSetup $setup, ?array $codes): GuardResult
    {
        $unmet = SetupChecklist::unmet($this->evaluate($setup), $codes);

        return $unmet === [] ? GuardResult::pass() : GuardResult::fail('Broker activation checklist incomplete: '.implode(', ', $unmet).'.');
    }
}
