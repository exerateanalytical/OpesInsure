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
                'id' => (string) Str::uuid(), 'code' => $code, 'version' => 1, 'family_code' => null, 'name' => $name,
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
            if ((int) ($p['target_business_minutes'] ?? 0) <= 0) {
                throw new InvalidArgumentException("SLA {$metric} needs a positive target_business_minutes.");
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
