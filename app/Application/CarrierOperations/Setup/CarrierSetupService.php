<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\Setup;

use App\Application\Approvals\ApprovalService;
use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseProblem;
use App\Application\Capabilities\CapabilityResolver;
use App\Application\CarrierOperations\Agreements\CarrierBrokerAgreementService;
use App\Application\CarrierOperations\Setup\Models\CarrierSetup;
use App\Models\ApprovalRequest;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\StateMachine\Contracts\PermissionChecker;
use App\Domain\Shared\StateMachine\Contracts\TransitionEventPublisher;
use App\Domain\Shared\StateMachine\Contracts\TransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\GuardResult;
use App\Domain\Shared\StateMachine\StateMachineDefinition;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionDenied;
use App\Models\Carrier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-SET-002 — insurer setup lifecycle (SETUP_CONFIGURATION_FRAMEWORK_V1 "Lifecycles"):
 * DRAFT → REGULATORY_REVIEW → CONFIGURATION → TESTING → READY_FOR_APPROVAL → ACTIVE;
 * SUSPENDED, INACTIVE, TERMINATED. Runs on the generic StateMachineEngine (REQ-WFL-001):
 * workflow_transition_history + outbox event + audit_log.
 *
 * The 23-item activation checklist follows the framework's 23 ordered setup steps
 * (create … approval → activation). The owner's verbatim checklist text is not in the repo
 * (see OWNER_OPEN_QUESTIONS) — item codes map 1:1 to those steps.
 */
final class CarrierSetupService
{
    public const SETUP_TYPE = 'CARRIER';

    public const MACHINE = 'insurer_setup';

    /** Items required before TESTING (steps 1–20), before READY_FOR_APPROVAL (1–21). */
    public const BEFORE_TESTING = ['ORGANIZATION_CREATED', 'REGULATORY_VERIFICATION', 'COMPANY_PROFILE', 'LEGAL_INFORMATION', 'BRANCHES', 'ORGANIZATION_STRUCTURE',
        'USERS_AND_ROLES', 'PRODUCTS', 'TARIFFS', 'UNDERWRITING_RULES', 'POLICY_RULES', 'CLAIMS_RULES', 'DOCUMENTS', 'BROKER_AGREEMENTS', 'COMMISSION',
        'PAYMENT_AND_SETTLEMENT', 'ACCOUNTING', 'INTEGRATIONS', 'NOTIFICATIONS', 'COMPLIANCE'];

    private readonly StateMachineEngine $engine;

    private static ?StateMachineDefinition $machine = null;

    public function __construct(
        TransitionHistoryRecorder $history,
        TransitionEventPublisher $events,
        private readonly SetupChecklist $checklist,
        private readonly CapabilityResolver $capabilities,
        private readonly AuditWriter $audit,
        private readonly Clock $clock,
        private readonly ApprovalService $approvals,
        private readonly CarrierBrokerAgreementService $agreements,
    ) {
        $this->engine = new StateMachineEngine($history, $events, self::permissionChecker());
        $this->engine->registerGuard('regulatory_verified', fn ($t, TransitionContext $c) => $this->gate($c->subject, ['ORGANIZATION_CREATED', 'REGULATORY_VERIFICATION']));
        $this->engine->registerGuard('configuration_complete', fn ($t, TransitionContext $c) => $this->gate($c->subject, self::BEFORE_TESTING));
        $this->engine->registerGuard('testing_complete', fn ($t, TransitionContext $c) => $this->gate($c->subject, [...self::BEFORE_TESTING, 'TESTING']));
        $this->engine->registerGuard('approval_granted', function ($t, TransitionContext $c) {
            $req = $c->subject->approval_request_id ? ApprovalRequest::find($c->subject->approval_request_id) : null;

            return $req !== null && $this->approvals->isApproved($req)
                ? GuardResult::pass()
                : GuardResult::fail('Activation needs an APPROVED insurer_setup.activate request in the approval inbox.');
        });
        $this->engine->registerGuard('maker_checker', function ($t, TransitionContext $c) {
            $maker = $c->subject->submitted_for_approval_by;

            return $maker !== null && $maker === $c->actorId()
                ? GuardResult::fail('Maker-checker: the user who submitted the insurer for approval cannot approve its activation.')
                : GuardResult::pass();
        });
    }

    public static function permissionChecker(): PermissionChecker
    {
        return new class implements PermissionChecker {
            public function allows(string $permission, TransitionContext $context): bool
            {
                $a = $context->actor;

                return is_object($a) && method_exists($a, 'hasPermission') && $a->hasPermission($permission);
            }
        };
    }

    public static function machine(): StateMachineDefinition
    {
        $manage = 'carrier_setup.manage';
        $approve = 'carrier_setup.approve';

        return self::$machine ??= StateMachineDefinition::fromArray([
            'name' => self::MACHINE, 'version' => 1, 'subject_type' => 'carrier_setup',
            'states' => ['DRAFT' => ['initial' => true], 'REGULATORY_REVIEW' => [], 'CONFIGURATION' => [], 'TESTING' => [], 'READY_FOR_APPROVAL' => [],
                'ACTIVE' => [], 'SUSPENDED' => [], 'INACTIVE' => [], 'TERMINATED' => ['terminal' => true]],
            'transitions' => [
                ['event' => 'submit_regulatory_review', 'from' => ['DRAFT'], 'to' => 'REGULATORY_REVIEW', 'permission' => $manage, 'domain_event' => 'insurer.setup.regulatory_review_requested'],
                ['event' => 'regulatory_verified', 'from' => ['REGULATORY_REVIEW'], 'to' => 'CONFIGURATION', 'permission' => $approve, 'guards' => ['regulatory_verified'], 'domain_event' => 'insurer.setup.regulatory_verified'],
                ['event' => 'regulatory_rejected', 'from' => ['REGULATORY_REVIEW'], 'to' => 'DRAFT', 'permission' => $approve, 'domain_event' => 'insurer.setup.regulatory_rejected'],
                ['event' => 'start_testing', 'from' => ['CONFIGURATION'], 'to' => 'TESTING', 'permission' => $manage, 'guards' => ['configuration_complete'], 'domain_event' => 'insurer.setup.testing_started'],
                ['event' => 'back_to_configuration', 'from' => ['TESTING'], 'to' => 'CONFIGURATION', 'permission' => $manage, 'domain_event' => 'insurer.setup.returned_to_configuration'],
                ['event' => 'submit_for_approval', 'from' => ['TESTING'], 'to' => 'READY_FOR_APPROVAL', 'permission' => $manage, 'guards' => ['testing_complete'], 'domain_event' => 'insurer.setup.submitted_for_approval'],
                ['event' => 'reject_approval', 'from' => ['READY_FOR_APPROVAL'], 'to' => 'CONFIGURATION', 'permission' => $approve, 'domain_event' => 'insurer.setup.approval_rejected'],
                ['event' => 'activate', 'from' => ['READY_FOR_APPROVAL'], 'to' => 'ACTIVE', 'permission' => $approve, 'guards' => ['testing_complete', 'maker_checker', 'approval_granted'], 'domain_event' => 'insurer.setup.activated'],
                ['event' => 'suspend', 'from' => ['ACTIVE'], 'to' => 'SUSPENDED', 'permission' => $approve, 'domain_event' => 'insurer.setup.suspended'],
                ['event' => 'reinstate', 'from' => ['SUSPENDED'], 'to' => 'ACTIVE', 'permission' => $approve, 'guards' => ['testing_complete'], 'domain_event' => 'insurer.setup.reinstated'],
                ['event' => 'deactivate', 'from' => ['ACTIVE', 'SUSPENDED'], 'to' => 'INACTIVE', 'permission' => $approve, 'domain_event' => 'insurer.setup.deactivated'],
                ['event' => 'reopen', 'from' => ['INACTIVE'], 'to' => 'CONFIGURATION', 'permission' => $approve, 'domain_event' => 'insurer.setup.reopened'],
                ['event' => 'terminate', 'from' => ['DRAFT', 'REGULATORY_REVIEW', 'CONFIGURATION', 'TESTING', 'READY_FOR_APPROVAL', 'ACTIVE', 'SUSPENDED', 'INACTIVE'], 'to' => 'TERMINATED', 'permission' => $approve, 'domain_event' => 'insurer.setup.terminated'],
            ],
        ]);
    }

    public function open(Carrier $carrier, User $actor, ?string $notes = null): CarrierSetup
    {
        if (CarrierSetup::where('carrier_id', $carrier->id)->exists()) {
            throw ValidationException::withMessages(['carrier' => 'This insurer already has a setup record.']);
        }
        $setup = CarrierSetup::create(['carrier_id' => $carrier->id, 'status' => 'DRAFT', 'created_by' => $actor->id, 'notes' => $notes]);
        $this->audit->record('insurer.setup.opened', 'carrier_setup', $setup->id, ['carrier_id' => $carrier->id]);

        return $setup;
    }

    public function transition(CarrierSetup $setup, string $event, User $actor, ?string $reason = null): CarrierSetup
    {
        return DB::transaction(function () use ($setup, $event, $actor, $reason) {
            $setup = CarrierSetup::whereKey($setup->id)->lockForUpdate()->firstOrFail();
            $ctx = new TransitionContext('carrier_setup', $setup->id, $setup->status, $actor, null, ['carrier_id' => $setup->carrier_id], $reason, $setup);
            try {
                $result = $this->engine->apply(self::machine(), $event, $ctx);
            } catch (TransitionDenied $e) {
                throw CaseProblem::fromDenied($e); // shared REQ-API-003 envelope for state-machine denials
            }
            $now = $this->clock->now();
            $attrs = ['status' => $result->to];
            if ($event === 'submit_for_approval') {
                $req = $this->approvals->open($actor, ['action_code' => 'insurer_setup.activate', 'subject_type' => 'carrier_setup', 'subject_id' => $setup->id,
                    'reason' => $reason ?? 'Insurer setup ready for approval', 'payload' => ['carrier_id' => $setup->carrier_id], 'excluded_user_ids' => [$actor->id]]);
                $attrs += ['submitted_for_approval_by' => $actor->id, 'submitted_for_approval_at' => $now, 'approval_request_id' => $req->id];
            }
            if (in_array($event, ['reject_approval', 'back_to_configuration', 'reopen'], true)) {
                $attrs += ['submitted_for_approval_by' => null, 'submitted_for_approval_at' => null, 'approved_by' => null, 'approved_at' => null, 'approval_request_id' => null];
            }
            if ($event === 'activate') {
                $attrs += ['approved_by' => $actor->id, 'approved_at' => $now, 'activated_at' => $now];
            }
            $setup->forceFill($attrs)->save();
            if ($event === 'activate') {
                $setup->forceFill(['activation_snapshot' => ['checklist' => $this->evaluate($setup->refresh()), 'capabilities' => $this->capabilities->all($setup->carrier_id), 'at' => $now->toIso8601String()]])->save();
            }
            $this->audit->record('insurer.setup.'.$event, 'carrier_setup', $setup->id, ['from' => $result->from, 'to' => $result->to, 'carrier_id' => $setup->carrier_id], $reason);

            return $setup->refresh();
        });
    }

    /** @return list<string> */
    public function available(CarrierSetup $setup, User $actor): array
    {
        $ctx = new TransitionContext('carrier_setup', $setup->id, $setup->status, $actor, null, [], null, $setup);

        return array_map(fn ($t) => $t->event, $this->engine->available(self::machine(), $ctx));
    }

    public function evaluate(CarrierSetup $setup): array
    {
        return $this->checklist->evaluate(self::SETUP_TYPE, $setup->id, $this->items($setup));
    }

    public function attest(CarrierSetup $setup, string $code, string $status, ?string $evidence, ?string $notes, User $actor): array
    {
        if (in_array($setup->status, ['ACTIVE', 'TERMINATED'], true)) {
            throw ValidationException::withMessages(['status' => "Checklist is frozen while the insurer setup is {$setup->status}; suspend or reopen first."]);
        }
        $this->checklist->attest(self::SETUP_TYPE, $setup->id, $this->items($setup), $code, $status, $evidence, $notes, $actor);

        return $this->evaluate($setup);
    }

    /** Operational only when the setup lifecycle is ACTIVE ("no organization is operational because a row exists"). */
    public function isOperational(string $carrierId): bool
    {
        return CarrierSetup::where('carrier_id', $carrierId)->where('status', 'ACTIVE')->exists();
    }

    /** @return list<array<string,mixed>> the 23 ordered items */
    public function items(CarrierSetup $setup): array
    {
        $cid = $setup->carrier_id;
        $exec = fn (string $cap) => $this->capabilities->mode($cid, $cap)['execution_mode'];
        $anyRemote = fn () => collect($this->capabilities->all($cid))->contains(fn ($r) => $r['execution_mode'] === 'REMOTE_API');

        return [
            ['code' => 'ORGANIZATION_CREATED', 'label' => 'Insurance company created', 'check' => fn () => DB::table('carriers')->where('id', $cid)->exists()],
            ['code' => 'REGULATORY_VERIFICATION', 'label' => 'Regulatory verification (active CIMA authorization)',
                'check' => fn () => DB::table('insurer_regulatory_authorizations')->where('carrier_id', $cid)->where('status', 'ACTIVE')->exists()],
            ['code' => 'COMPANY_PROFILE', 'label' => 'Company profile incl. approved capability profile (REQ-AOM-001)',
                'check' => fn () => DB::table('carrier_capability_profiles')->where('carrier_id', $cid)->where('status', 'ACTIVE')->exists()],
            ['code' => 'LEGAL_INFORMATION', 'label' => 'Legal information', 'evidence' => true],
            ['code' => 'BRANCHES', 'label' => 'Authorized CIMA branches',
                'check' => fn () => DB::table('insurer_authorized_branches as b')->join('insurer_regulatory_authorizations as a', 'a.id', '=', 'b.authorization_id')
                    ->where('a.carrier_id', $cid)->where('a.status', 'ACTIVE')->where('b.status', 'ACTIVE')->exists()],
            ['code' => 'ORGANIZATION_STRUCTURE', 'label' => 'Organization structure (branches, departments)'],
            ['code' => 'USERS_AND_ROLES', 'label' => 'Users and roles'],
            ['code' => 'PRODUCTS', 'label' => 'Products', 'check' => fn () => DB::table('insurance_products')->where('carrier_id', $cid)->where('status', 'ACTIVE')->exists()],
            ['code' => 'TARIFFS', 'label' => 'Tariffs (configured pricing only)', 'applies' => fn () => $exec('RATING') !== 'MANUAL',
                'check' => fn () => DB::table('tariff_versions as t')->join('insurance_products as p', 'p.id', '=', 't.insurance_product_id')->where('p.carrier_id', $cid)->where('t.status', 'APPROVED')->exists()],
            ['code' => 'UNDERWRITING_RULES', 'label' => 'Underwriting rules (configured underwriting only)', 'applies' => fn () => in_array($exec('UNDERWRITING'), ['CONFIGURED', 'HYBRID'], true), 'evidence' => true],
            ['code' => 'POLICY_RULES', 'label' => 'Policy rules'],
            ['code' => 'CLAIMS_RULES', 'label' => 'Claims rules'],
            ['code' => 'DOCUMENTS', 'label' => 'Documents (document issuance profile)', 'check' => fn () => DB::table('document_issuance_profiles')->where('carrier_id', $cid)->where('status', 'ACTIVE')->exists()],
            ['code' => 'BROKER_AGREEMENTS', 'label' => 'Broker agreements (carrier_broker_agreements)', 'na' => true, 'check' => fn () => $this->authorizedAgreementLines(carrierId: $cid) > 0],
            ['code' => 'COMMISSION', 'label' => 'Commission'],
            ['code' => 'PAYMENT_AND_SETTLEMENT', 'label' => 'Payment and settlement'],
            ['code' => 'ACCOUNTING', 'label' => 'Accounting'],
            ['code' => 'INTEGRATIONS', 'label' => 'Integrations (API modes only)', 'applies' => $anyRemote, 'evidence' => true],
            ['code' => 'NOTIFICATIONS', 'label' => 'Notifications'],
            ['code' => 'COMPLIANCE', 'label' => 'Compliance'],
            ['code' => 'TESTING', 'label' => 'Configuration sandbox testing', 'evidence' => true],
            ['code' => 'APPROVAL', 'label' => 'Approval (maker-checker)', 'system' => true, 'check' => fn () => $setup->approved_by !== null],
            ['code' => 'ACTIVATION', 'label' => 'Activation', 'system' => true, 'check' => fn () => $setup->activated_at !== null && in_array($setup->status, ['ACTIVE', 'SUSPENDED'], true)],
        ];
    }

    /**
     * Counts agreement product lines that the canonical agreement service (REQ-DUP-023,
     * CarrierBrokerAgreementService::permits) authorizes today for quoting. Shared with broker setup.
     */
    public function authorizedAgreementLines(?string $carrierId = null, ?string $partnerId = null): int
    {
        $today = $this->clock->today()->toDateString();
        $lines = DB::table('carrier_broker_agreements as a')->join('carrier_broker_agreement_products as p', 'p.agreement_id', '=', 'a.id')
            ->where('a.status', 'ACTIVE')->where('p.status', 'ACTIVE')
            ->when($carrierId, fn ($q) => $q->where('a.carrier_id', $carrierId))
            ->when($partnerId, fn ($q) => $q->where('a.partner_id', $partnerId))
            ->get(['a.carrier_id', 'a.partner_id', 'p.line_code', 'p.insurance_product_id']);

        return $lines->filter(fn ($l) => $this->agreements->permits($l->partner_id, $l->carrier_id, $l->line_code, $l->insurance_product_id, 'quote', $today)['allowed'])->count();
    }

    private function gate(CarrierSetup $setup, array $codes): GuardResult
    {
        $unmet = SetupChecklist::unmet($this->evaluate($setup), $codes);

        return $unmet === [] ? GuardResult::pass() : GuardResult::fail('Activation checklist incomplete: '.implode(', ', $unmet).'.');
    }
}
