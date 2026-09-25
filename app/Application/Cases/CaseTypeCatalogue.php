<?php

declare(strict_types=1);

namespace App\Application\Cases;

use App\Application\Cases\Models\CaseType;
use App\Domain\Shared\StateMachine\StateMachineDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * REQ-CAS-001 case type catalogue and machine builder.
 *
 * OQ-6.4: the blueprint names "8 case types" but the owner has not listed them
 * anywhere in the spec set, so the ICE §6.2 codes are seeded as SUB-TYPES with
 * family_code = NULL (UNVERIFIED) until the owner maps each one to its family.
 * OQ-6.3: default SLA targets are an owner decision, so seeded sla_policies are
 * empty; admins add them by creating a new type version (maker-checker).
 */
final class CaseTypeCatalogue
{
    public const DECISION_GUARD = 'case.decision_recorded';

    /** SLA target labels (owner decisions: complaints / #32). REGULATORY_DEADLINE always needs a legal_basis. */
    public const LABELS = ['PLATFORM_SLA', 'REGULATORY_DEADLINE'];

    /**
     * Owner decision #13: reporting/navigation families (case_families, source RECONSTRUCTED_PENDING_OWNER)
     * and the case types filed under each. code => [name, [case type codes]].
     */
    public const FAMILIES = [
        'UNDERWRITING' => ['Underwriting', ['UW_REFERRAL', 'AUTHORITY_REFERRAL']],
        'QUOTATION' => ['Quotation', ['CARRIER_QUOTE_REQUEST']],
        'CLAIMS' => ['Claims', ['CLAIM_COVERAGE_REVIEW', 'CLAIM_INVESTIGATION', 'PROVIDER_DISPUTE']],
        'COMPLAINTS' => ['Complaints', ['COMPLAINT']],
        'RECOVERY_LEGAL' => ['Recovery and litigation', ['RECOVERY', 'LITIGATION']],
        'COMPLIANCE' => ['Compliance and KYC', ['AML_ALERT', 'STR', 'COMPLIANCE_INVESTIGATION', 'REG_IMPACT', 'KYC_REVIEW']],
        'DATA_QUALITY' => ['Data quality', ['CONFIG_GAP', 'DATA_STEWARD']],
        'OPERATIONS' => ['Operations', ['DOC_INTAKE_EXCEPTION']],
    ];

    /**
     * Workflow Data Master v1 (case_management.case_families): the owner's list REPLACES the reconstructed families
     * above (kept only because the 2026_10_08_700001 migration reads it). Existing case types are remapped, never
     * deleted; the reconstructed rows stay in case_families as inactive history (migration 2026_10_11_800001).
     */
    public const OWNER_FAMILIES = [
        'KYC' => ['KYC and AML', ['KYC_REVIEW', 'AML_ALERT', 'STR']],
        'UNDERWRITING' => ['Underwriting', ['UW_REFERRAL', 'AUTHORITY_REFERRAL', 'CARRIER_QUOTE_REQUEST']],
        'CLAIMS' => ['Claims', ['CLAIM_COVERAGE_REVIEW', 'RECOVERY', 'LITIGATION']],
        'FRAUD_REVIEW' => ['Fraud review', ['CLAIM_INVESTIGATION']],
        'COMPLAINT' => ['Complaint', ['COMPLAINT']],
        'FINANCE_EXCEPTION' => ['Finance exception', []],
        'PROVIDER' => ['Provider', ['PROVIDER_DISPUTE']],
        'REINSURANCE' => ['Reinsurance', []],
        'REGULATORY' => ['Regulatory and compliance', ['COMPLIANCE_INVESTIGATION', 'REG_IMPACT']],
        'OPERATIONS' => ['Operations', ['DOC_INTAKE_EXCEPTION', 'CONFIG_GAP', 'DATA_STEWARD']],
    ];

    /** Workflow Data Master v1 case_management.priorities (CRITICAL added to the original four). Ordered most urgent first. */
    public const PRIORITIES = ['CRITICAL', 'URGENT', 'HIGH', 'NORMAL', 'LOW'];

    /**
     * Workflow Data Master v1 complaints.severity_levels => case priority of the COMPLAINT case. Complaint categories
     * and regulatory deadlines are PENDING_SOURCE; the platform SLA is CONFIG_REQUIRED (no target is invented).
     */
    public const COMPLAINT_SEVERITY_MAP = ['LOW' => 'LOW', 'MEDIUM' => 'NORMAL', 'HIGH' => 'HIGH', 'CRITICAL' => 'CRITICAL'];

    /**
     * Owner decision #32 — manual quote defaults (PLATFORM_SLA, not legal deadlines): acknowledgement
     * 4 business hours, standard decision 2 business days, complex/referred 5 business days.
     * Overridable per insurer / product / case type / branch / market through sla_policy_overrides.
     */
    public const MANUAL_QUOTE_SLA_DEFAULTS = [
        ['metric' => 'FIRST_RESPONSE', 'target_business_minutes' => 240, 'label' => 'PLATFORM_SLA'],
        ['metric' => 'RESOLUTION', 'target_business_days' => 2, 'label' => 'PLATFORM_SLA'],
        ['metric' => 'RESOLUTION', 'target_business_days' => 5, 'label' => 'PLATFORM_SLA', 'case_subtypes' => ['COMPLEX', 'REFERRED']],
    ];

    public static function familyFor(string $typeCode): ?string
    {
        foreach (self::OWNER_FAMILIES as $family => [, $types]) {
            if (in_array($typeCode, $types, true)) {
                return $family;
            }
        }

        return null;
    }

    /** Owner decision #32 lifecycle: the generic one with the owner's two pausing waits. */
    public static function manualQuoteLifecycle(): array
    {
        $def = self::genericLifecycle(false);
        $rename = ['WAITING_CUSTOMER' => 'WAITING_FOR_CUSTOMER', 'WAITING_THIRD_PARTY' => 'WAITING_FOR_EXTERNAL_EVIDENCE'];
        foreach ($def['states'] as &$s) {
            $s['code'] = $rename[$s['code']] ?? $s['code'];
        }
        unset($s);
        foreach ($def['transitions'] as &$t) {
            $t['to'] = $rename[$t['to']] ?? $t['to'];
            $t['from'] = array_map(fn ($f) => $rename[$f] ?? $f, (array) $t['from']);
        }
        unset($t);
        // Evidence can also be awaited after the customer answered.
        foreach ($def['transitions'] as &$t) {
            if ($t['event'] === 'await_third_party') {
                $t['from'] = ['IN_PROGRESS', 'PENDING_DECISION'];
            }
        }
        unset($t);

        return $def;
    }

    /** code => [name, default confidentiality, regulated, requires decision to resolve] */
    public const SEED = [
        'UW_REFERRAL' => ['Underwriting referral', 'NORMAL', false, true],
        'AUTHORITY_REFERRAL' => ['Authority referral', 'NORMAL', false, true],
        'CLAIM_COVERAGE_REVIEW' => ['Claim coverage review', 'NORMAL', false, true],
        'CLAIM_INVESTIGATION' => ['Claim investigation (suspicious claim)', 'RESTRICTED', false, true],
        'COMPLAINT' => ['Complaint', 'NORMAL', true, false],
        'RECOVERY' => ['Recovery', 'NORMAL', false, false],
        'LITIGATION' => ['Litigation', 'RESTRICTED', false, false],
        'PROVIDER_DISPUTE' => ['Provider dispute', 'NORMAL', false, true],
        'AML_ALERT' => ['AML/CFT alert', 'RESTRICTED', true, true],
        'STR' => ['Suspicious transaction report', 'STR_RESTRICTED', true, true],
        'COMPLIANCE_INVESTIGATION' => ['Compliance investigation', 'NORMAL', false, false],
        'REG_IMPACT' => ['Regulatory impact remediation', 'NORMAL', false, false],
        'CONFIG_GAP' => ['Configuration / data-quality gap', 'NORMAL', false, false],
        'DOC_INTAKE_EXCEPTION' => ['Document intake exception', 'NORMAL', false, false],
        'DATA_STEWARD' => ['Duplicate entity review (data steward)', 'NORMAL', false, true],
    ];

    /** @return array{states: list<array<string, mixed>>, transitions: list<array<string, mixed>>} */
    public static function genericLifecycle(bool $requiresDecision): array
    {
        return [
            'states' => [
                ['code' => 'OPEN', 'initial' => true],
                ['code' => 'IN_PROGRESS'],
                ['code' => 'WAITING_CUSTOMER', 'pauses_sla' => true],
                ['code' => 'WAITING_THIRD_PARTY', 'pauses_sla' => true],
                ['code' => 'PENDING_DECISION'],
                ['code' => 'RESOLVED'],
                ['code' => 'CLOSED', 'terminal' => true],
                ['code' => 'CANCELLED', 'terminal' => true],
            ],
            'transitions' => [
                ['event' => 'start', 'from' => ['OPEN'], 'to' => 'IN_PROGRESS'],
                ['event' => 'request_info', 'from' => ['IN_PROGRESS', 'PENDING_DECISION'], 'to' => 'WAITING_CUSTOMER'],
                ['event' => 'await_third_party', 'from' => ['IN_PROGRESS'], 'to' => 'WAITING_THIRD_PARTY'],
                ['event' => 'info_received', 'from' => ['WAITING_CUSTOMER', 'WAITING_THIRD_PARTY'], 'to' => 'IN_PROGRESS'],
                ['event' => 'submit_for_decision', 'from' => ['IN_PROGRESS'], 'to' => 'PENDING_DECISION'],
                ['event' => 'resolve', 'from' => $requiresDecision ? ['PENDING_DECISION'] : ['IN_PROGRESS', 'PENDING_DECISION'], 'to' => 'RESOLVED', 'requires_decision' => $requiresDecision],
                ['event' => 'reopen', 'from' => ['RESOLVED'], 'to' => 'IN_PROGRESS', 'requires_reason' => true],
                ['event' => 'close', 'from' => ['RESOLVED'], 'to' => 'CLOSED'],
                ['event' => 'cancel', 'from' => ['OPEN', 'IN_PROGRESS', 'WAITING_CUSTOMER', 'WAITING_THIRD_PARTY'], 'to' => 'CANCELLED', 'requires_reason' => true],
            ],
        ];
    }

    /** ICE §6.4 complaint states. Legal deadlines / escalation path: OQ-6.1 (not modelled as SLA targets). */
    public static function complaintLifecycle(): array
    {
        return [
            'states' => [
                ['code' => 'RECEIVED', 'initial' => true],
                ['code' => 'ACKNOWLEDGED'],
                ['code' => 'INVESTIGATING'],
                ['code' => 'WAITING_CUSTOMER', 'pauses_sla' => true],
                ['code' => 'RESPONDED'],
                ['code' => 'ESCALATED_NATIONAL'],
                ['code' => 'ESCALATED_CIMA'],
                ['code' => 'CLOSED', 'terminal' => true],
            ],
            'transitions' => [
                ['event' => 'acknowledge', 'from' => ['RECEIVED'], 'to' => 'ACKNOWLEDGED'],
                ['event' => 'investigate', 'from' => ['ACKNOWLEDGED'], 'to' => 'INVESTIGATING'],
                ['event' => 'request_info', 'from' => ['INVESTIGATING'], 'to' => 'WAITING_CUSTOMER'],
                ['event' => 'info_received', 'from' => ['WAITING_CUSTOMER'], 'to' => 'INVESTIGATING'],
                ['event' => 'respond', 'from' => ['INVESTIGATING'], 'to' => 'RESPONDED'],
                ['event' => 'escalate_national', 'from' => ['RESPONDED'], 'to' => 'ESCALATED_NATIONAL', 'requires_reason' => true],
                ['event' => 'escalate_cima', 'from' => ['ESCALATED_NATIONAL'], 'to' => 'ESCALATED_CIMA', 'requires_reason' => true],
                ['event' => 'close', 'from' => ['RESPONDED', 'ESCALATED_NATIONAL', 'ESCALATED_CIMA'], 'to' => 'CLOSED'],
            ],
        ];
    }

    /** Idempotent: inserts v1 of each seeded type if the code has no version yet. Never overwrites. */
    public static function seed(): int
    {
        $n = 0;
        foreach (self::SEED as $code => [$name, $confidentiality, $regulated, $decision]) {
            if (DB::table('case_types')->where('code', $code)->exists()) {
                continue;
            }
            $def = $code === 'COMPLAINT' ? self::complaintLifecycle() : self::genericLifecycle($decision);
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => $code, 'version' => 1, 'family_code' => self::familyFor($code), 'name' => $name,
                'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                'sla_policies' => '[]', 'auto_tasks' => '[]', 'default_confidentiality' => $confidentiality, 'regulated' => $regulated,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * Validates a type definition; a version that fails cannot be approved (ICE §6.7).
     *
     * @param  array<string, mixed>  $d  states, transitions, sla_policies, auto_tasks
     */
    public static function validate(array $d): void
    {
        $machine = self::machineFromArray('validation', 1, $d['states'] ?? [], $d['transitions'] ?? []);
        if ($machine->initialState() === null) {
            throw new InvalidArgumentException('A case type needs exactly one initial state.');
        }
        foreach ($d['sla_policies'] ?? [] as $p) {
            $metric = (string) ($p['metric'] ?? '');
            if (! in_array($metric, ['FIRST_RESPONSE', 'RESOLUTION'], true) && ! (str_starts_with($metric, 'STAGE:') && $machine->hasState(substr($metric, 6)))) {
                throw new InvalidArgumentException("Unknown SLA metric {$metric}.");
            }
            $minutes = (int) ($p['target_business_minutes'] ?? 0);
            $days = (int) ($p['target_business_days'] ?? 0);
            if (($minutes > 0) === ($days > 0)) {
                throw new InvalidArgumentException("SLA {$metric} needs exactly one positive target_business_minutes or target_business_days.");
            }
            $label = (string) ($p['label'] ?? 'PLATFORM_SLA');
            if (! in_array($label, self::LABELS, true)) {
                throw new InvalidArgumentException("SLA {$metric} label must be one of ".implode(', ', self::LABELS).'.');
            }
            if ($label === 'REGULATORY_DEADLINE' && trim((string) ($p['legal_basis'] ?? '')) === '') {
                throw new InvalidArgumentException("SLA {$metric} is labelled REGULATORY_DEADLINE without a legal_basis; label it PLATFORM_SLA.");
            }
            if (isset($p['case_subtypes']) && (! is_array($p['case_subtypes']) || $p['case_subtypes'] === [])) {
                throw new InvalidArgumentException("SLA {$metric} case_subtypes must be a non-empty list when present.");
            }
            $warn = (int) ($p['warn_at_pct'] ?? 80);
            if ($warn < 1 || $warn > 99) {
                throw new InvalidArgumentException("SLA {$metric} warn_at_pct must be 1..99.");
            }
        }
        foreach ($d['auto_tasks'] ?? [] as $t) {
            if (empty($t['on']) || empty($t['title'])) {
                throw new InvalidArgumentException('auto_tasks entries need `on` (state:<CODE> or event:<name>) and `title`.');
            }
            if (str_starts_with((string) $t['on'], 'state:') && ! $machine->hasState(substr((string) $t['on'], 6))) {
                throw new InvalidArgumentException("auto_task references unknown state {$t['on']}.");
            }
        }
    }

    public static function machine(CaseType $type): StateMachineDefinition
    {
        return self::machineFromArray('case.'.$type->code, (int) $type->version, $type->states, $type->transitions);
    }

    /** @return array<string, mixed>|null raw stored transition row for $event from $from */
    public static function rawTransition(CaseType $type, string $from, string $event): ?array
    {
        foreach ($type->transitions as $t) {
            if (($t['event'] ?? null) === $event && in_array($from, (array) ($t['from'] ?? []), true)) {
                return $t;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @param  list<array<string, mixed>>  $transitions
     */
    private static function machineFromArray(string $name, int $version, array $states, array $transitions): StateMachineDefinition
    {
        $s = [];
        foreach ($states as $state) {
            if (! is_array($state) || empty($state['code'])) {
                throw new InvalidArgumentException('Every state needs a code.');
            }
            $s[$state['code']] = ['initial' => (bool) ($state['initial'] ?? false), 'terminal' => (bool) ($state['terminal'] ?? false), 'label' => $state['label'] ?? null];
        }
        $t = [];
        foreach ($transitions as $tr) {
            if (! is_array($tr) || empty($tr['event']) || empty($tr['to']) || empty($tr['from'])) {
                throw new InvalidArgumentException('Every transition needs event, from and to.');
            }
            $t[] = [
                'event' => $tr['event'], 'from' => (array) $tr['from'], 'to' => $tr['to'],
                'actors' => $tr['actors'] ?? [], 'permission' => $tr['permission'] ?? null,
                'guards' => ! empty($tr['requires_decision']) ? [self::DECISION_GUARD] : [],
                'domain_event' => 'case.transitioned',
                'failure_path' => ! empty($tr['requires_decision']) ? 'stay; record a decision first' : null,
            ];
        }

        return StateMachineDefinition::fromArray(['name' => $name, 'version' => $version, 'subject_type' => 'case', 'states' => $s, 'transitions' => $t]);
    }
}
