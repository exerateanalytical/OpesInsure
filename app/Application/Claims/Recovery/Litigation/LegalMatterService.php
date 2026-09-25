<?php

declare(strict_types=1);

namespace App\Application\Claims\Recovery\Litigation;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Models\Claim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-REC-002 — litigation / legal management as a LITIGATION case on the case engine.
 * The case carries workflow, SLA, owner and journal; legal_matters holds the court, lawyer (party),
 * hearings, deadlines, legal costs (PAYABLE obligations to the payee) and the outcome.
 */
final class LegalMatterService
{
    public const ROLES = ['PLAINTIFF', 'DEFENDANT'];

    public const OUTCOMES = ['WON', 'LOST', 'SETTLED', 'WITHDRAWN', 'DISMISSED'];

    public const COST_TYPES = ['LAWYER_FEE', 'COURT_FEE', 'BAILIFF', 'EXPERT', 'OTHER'];

    public const HEARING_RESULTS = ['HELD', 'ADJOURNED', 'CANCELLED'];

    public function __construct(
        private readonly CaseService $cases,
        private readonly ObligationService $obligations,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param array{role:string, court:string, court_reference?:?string, jurisdiction?:?string, lawyer_party_id?:?string, opposing_party_name?:?string, claim_id?:?string, claim_recovery_id?:?string, claimed_amount_minor?:int, currency?:?string, title?:?string} $d */
    public function open(string $tenantId, array $d, User $actor): object
    {
        if (! in_array($d['role'], self::ROLES, true)) {
            $this->fail('role', 'ROLE_INVALID', 'Role must be PLAINTIFF or DEFENDANT.');
        }
        $claim = null;
        if (! empty($d['claim_id'])) {
            $claim = Claim::where(['id' => $d['claim_id'], 'tenant_id' => $tenantId])->first() ?? $this->fail('claim_id', 'CLAIM_NOT_FOUND', 'Claim not found.');
        }
        if (! empty($d['claim_recovery_id']) && ! DB::table('claim_recoveries')->where('id', $d['claim_recovery_id'])->when($claim, fn ($q) => $q->where('claim_id', $claim->id))
            ->whereIn('claim_id', Claim::where('tenant_id', $tenantId)->select('id'))->exists()) {
            $this->fail('claim_recovery_id', 'RECOVERY_NOT_FOUND', 'Recovery not found on this claim.');
        }

        return DB::transaction(function () use ($tenantId, $d, $actor, $claim): object {
            $case = $this->cases->open($tenantId, 'LITIGATION', [
                'title' => $d['title'] ?? ('Litigation — '.$d['court'].($claim ? ' — '.$claim->claim_number : '')),
                'subject_type' => $claim ? 'claim' : null, 'subject_id' => $claim?->id, 'jurisdiction' => $d['jurisdiction'] ?? 'CM',
                'source_type' => $claim ? 'claim' : null, 'source_id' => $claim?->id,
            ], $actor);
            $id = (string) Str::uuid();
            DB::table('legal_matters')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'case_id' => $case->id, 'claim_id' => $claim?->id, 'claim_recovery_id' => $d['claim_recovery_id'] ?? null,
                'role' => $d['role'], 'court' => $d['court'], 'court_reference' => $d['court_reference'] ?? null, 'jurisdiction' => $d['jurisdiction'] ?? 'CM',
                'lawyer_party_id' => $d['lawyer_party_id'] ?? null, 'opposing_party_name' => $d['opposing_party_name'] ?? null,
                'claimed_amount_minor' => (int) ($d['claimed_amount_minor'] ?? 0), 'currency' => $d['currency'] ?? $claim?->currency ?? 'XAF',
                'status' => 'ACTIVE', 'opened_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('legal.matter.opened', 'legal_matter', $id, ['case_id' => $case->id, 'claim_id' => $claim?->id, 'court' => $d['court']]);
            $this->outbox->record('legal.matter.opened', 'legal_matter', $id, ['legal_matter_id' => $id, 'case_id' => $case->id, 'claim_id' => $claim?->id, 'role' => $d['role']]);

            return $this->find($tenantId, $id);
        });
    }

    public function find(string $tenantId, string $id): object
    {
        $m = DB::table('legal_matters')->where(['tenant_id' => $tenantId, 'id' => $id])->first() ?? abort(404);
        $m->hearings = DB::table('legal_hearings')->where('legal_matter_id', $id)->orderBy('scheduled_at')->get();
        $m->deadlines = DB::table('legal_deadlines')->where('legal_matter_id', $id)->orderBy('due_at')->get();
        $m->costs = DB::table('legal_costs')->where('legal_matter_id', $id)->orderBy('incurred_on')->get();
        $m->total_costs_minor = (int) $m->costs->sum('amount_minor');

        return $m;
    }

    public function scheduleHearing(string $tenantId, string $matterId, array $d, User $actor): object
    {
        $m = $this->active($tenantId, $matterId);
        $id = (string) Str::uuid();
        DB::table('legal_hearings')->insert(['id' => $id, 'legal_matter_id' => $m->id, 'scheduled_at' => $d['scheduled_at'], 'location' => $d['location'] ?? null,
            'purpose' => $d['purpose'] ?? null, 'status' => 'SCHEDULED', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('legal.hearing.scheduled', 'legal_matter', $m->id, ['hearing_id' => $id, 'scheduled_at' => $d['scheduled_at'], 'actor_id' => $actor->id]);
        $this->outbox->record('legal.hearing.scheduled', 'legal_matter', $m->id, ['legal_matter_id' => $m->id, 'hearing_id' => $id, 'scheduled_at' => (string) $d['scheduled_at']]);

        return DB::table('legal_hearings')->where('id', $id)->first();
    }

    public function recordHearing(string $tenantId, string $hearingId, string $status, ?string $result, User $actor): object
    {
        if (! in_array($status, self::HEARING_RESULTS, true)) {
            $this->fail('status', 'HEARING_STATUS_INVALID', 'Hearing status must be HELD, ADJOURNED or CANCELLED.');
        }
        $h = DB::table('legal_hearings')->where('id', $hearingId)->first() ?? abort(404);
        $this->active($tenantId, $h->legal_matter_id);
        DB::table('legal_hearings')->where('id', $h->id)->update(['status' => $status, 'result' => $result, 'updated_at' => now()]);
        $this->audit->record('legal.hearing.recorded', 'legal_matter', $h->legal_matter_id, ['hearing_id' => $h->id, 'status' => $status, 'actor_id' => $actor->id]);

        return DB::table('legal_hearings')->where('id', $h->id)->first();
    }

    public function addDeadline(string $tenantId, string $matterId, string $description, mixed $dueAt, User $actor): object
    {
        $m = $this->active($tenantId, $matterId);
        $id = (string) Str::uuid();
        DB::table('legal_deadlines')->insert(['id' => $id, 'legal_matter_id' => $m->id, 'description' => $description, 'due_at' => $dueAt, 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('legal.deadline.added', 'legal_matter', $m->id, ['deadline_id' => $id, 'due_at' => (string) $dueAt, 'actor_id' => $actor->id]);

        return DB::table('legal_deadlines')->where('id', $id)->first();
    }

    public function completeDeadline(string $tenantId, string $deadlineId, User $actor): object
    {
        $dl = DB::table('legal_deadlines')->where('id', $deadlineId)->first() ?? abort(404);
        $this->active($tenantId, $dl->legal_matter_id);
        if ($dl->status !== 'OPEN') {
            $this->fail('status', 'DEADLINE_CLOSED', "Deadline is {$dl->status}.");
        }
        DB::table('legal_deadlines')->where('id', $dl->id)->update(['status' => 'MET', 'completed_at' => now(), 'updated_at' => now()]);
        $this->audit->record('legal.deadline.met', 'legal_matter', $dl->legal_matter_id, ['deadline_id' => $dl->id, 'actor_id' => $actor->id]);

        return DB::table('legal_deadlines')->where('id', $dl->id)->first();
    }

    /** OPEN deadlines past due become MISSED (daily sweep). Returns the count. */
    public function sweepDeadlines(): int
    {
        $ids = DB::table('legal_deadlines')->where('status', 'OPEN')->where('due_at', '<', now())->pluck('id');
        foreach ($ids as $id) {
            DB::table('legal_deadlines')->where('id', $id)->where('status', 'OPEN')->update(['status' => 'MISSED', 'updated_at' => now()]);
            $matter = DB::table('legal_deadlines')->where('id', $id)->value('legal_matter_id');
            $this->outbox->record('legal.deadline.missed', 'legal_matter', $matter, ['legal_matter_id' => $matter, 'deadline_id' => $id]);
        }

        return $ids->count();
    }

    /** A legal cost is a PAYABLE obligation to the payee (lawyer, court, bailiff, expert). */
    public function addCost(string $tenantId, string $matterId, array $d, User $actor): object
    {
        if (! in_array($d['cost_type'], self::COST_TYPES, true)) {
            $this->fail('cost_type', 'COST_TYPE_INVALID', "Unknown legal cost type {$d['cost_type']}.");
        }
        $m = $this->active($tenantId, $matterId);

        return DB::transaction(function () use ($m, $d, $actor, $tenantId): object {
            $id = (string) Str::uuid();
            $currency = $d['currency'] ?? $m->currency;
            $o = $this->obligations->create([
                'tenant_id' => $tenantId, 'kind' => 'PAYABLE', 'type' => 'OTHER', 'source_type' => 'legal_cost', 'source_id' => $id, 'currency' => $currency,
                'amount_minor' => (int) $d['amount_minor'], 'due_at' => $d['due_at'] ?? now()->addDays(30),
                'creditor_type' => ! empty($d['payee_party_id']) ? 'party' : null, 'creditor_id' => $d['payee_party_id'] ?? null,
                'description' => "Legal cost {$d['cost_type']} (matter {$m->id})", 'metadata' => ['legal_matter_id' => $m->id, 'claim_id' => $m->claim_id],
            ], $actor->id);
            DB::table('legal_costs')->insert(['id' => $id, 'legal_matter_id' => $m->id, 'cost_type' => $d['cost_type'], 'amount_minor' => (int) $d['amount_minor'], 'currency' => $currency,
                'payee_party_id' => $d['payee_party_id'] ?? null, 'financial_obligation_id' => $o->id, 'description' => $d['description'] ?? null,
                'incurred_on' => $d['incurred_on'] ?? now()->toDateString(), 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('legal.cost.recorded', 'legal_matter', $m->id, ['cost_id' => $id, 'amount_minor' => (int) $d['amount_minor'], 'obligation_id' => $o->id]);

            return DB::table('legal_costs')->where('id', $id)->first();
        });
    }

    /** Record the outcome: the matter concludes and the LITIGATION case is resolved. */
    public function recordOutcome(string $tenantId, string $matterId, string $outcome, ?int $amountMinor, ?string $notes, User $actor): object
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            $this->fail('outcome', 'OUTCOME_INVALID', "Unknown outcome {$outcome}.");
        }
        $m = $this->active($tenantId, $matterId);

        return DB::transaction(function () use ($m, $outcome, $amountMinor, $notes, $actor, $tenantId): object {
            DB::table('legal_matters')->where('id', $m->id)->update(['status' => 'CONCLUDED', 'outcome' => $outcome, 'outcome_amount_minor' => $amountMinor,
                'outcome_notes' => $notes, 'concluded_at' => now(), 'updated_at' => now()]);
            $case = WorkCase::withoutGlobalScopes()->findOrFail($m->case_id);
            if ($case->status === 'OPEN') {
                $case = $this->cases->transition($case, 'start', $actor);
            }
            if (in_array($case->status, ['IN_PROGRESS', 'PENDING_DECISION'], true)) {
                $this->cases->transition($case, 'resolve', $actor, "Litigation outcome: {$outcome}");
            }
            $this->audit->record('legal.outcome.recorded', 'legal_matter', $m->id, ['outcome' => $outcome, 'amount_minor' => $amountMinor, 'actor_id' => $actor->id], $notes);
            $this->outbox->record('legal.outcome.recorded', 'legal_matter', $m->id, ['legal_matter_id' => $m->id, 'case_id' => $m->case_id, 'claim_id' => $m->claim_id,
                'outcome' => $outcome, 'amount_minor' => $amountMinor, 'currency' => $m->currency]);

            return $this->find($tenantId, $m->id);
        });
    }

    private function active(string $tenantId, string $id): object
    {
        $m = DB::table('legal_matters')->where(['tenant_id' => $tenantId, 'id' => $id])->first() ?? abort(404);
        if ($m->status !== 'ACTIVE') {
            $this->fail('status', 'MATTER_CONCLUDED', 'The legal matter is concluded.');
        }

        return $m;
    }

    private function fail(string $field, string $code, string $message): never
    {
        throw ValidationException::withMessages([$field => "{$code}: {$message}"]);
    }
}
