<?php

declare(strict_types=1);

namespace App\Application\Claims\Assessment;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Claims\Assessment\Models\ClaimInvestigation;
use App\Application\Claims\Assessment\Models\ClaimInvestigationIndicator;
use App\Application\Events\OutboxWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-010 / WF-055 — claim investigation. Opened through the case engine (CLAIM_INVESTIGATION,
 * RESTRICTED by default); findings may be recorded while OPEN; fraud indicators produced by the
 * indicator engine are attached by reference with an immutable snapshot (append-only table);
 * concluding records the outcome and freezes the row (DB trigger).
 */
final class ClaimInvestigationService
{
    public const OUTCOMES = ['NO_FRAUD_FOUND', 'SUSPECTED_FRAUD', 'FRAUD_CONFIRMED', 'INCONCLUSIVE', 'REFERRED_EXTERNAL'];

    public function __construct(
        private readonly CaseService $cases,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function open(Claim $claim, string $reasonCode, string $reason, User $actor): ClaimInvestigation
    {
        $this->tenant($claim);

        return DB::transaction(function () use ($claim, $reasonCode, $reason, $actor) {
            Claim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if (ClaimInvestigation::where('claim_id', $claim->id)->where('status', 'OPEN')->exists()) {
                throw ValidationException::withMessages(['status' => 'An investigation is already open on this claim.']);
            }
            $case = $this->cases->open($claim->tenant_id, 'CLAIM_INVESTIGATION', [
                'title' => 'Investigation — claim '.$claim->claim_number,
                'subject_type' => 'claim', 'subject_id' => $claim->id,
                'source_type' => 'claim_investigation', 'source_id' => $claim->id,
                'domain_reference' => $claim->claim_number,
                'idempotency_key' => 'claim-investigation:'.$claim->id.':'.Str::uuid(),
            ], $actor);
            $inv = ClaimInvestigation::create([
                'tenant_id' => $claim->tenant_id, 'claim_id' => $claim->id, 'case_id' => $case->id, 'status' => 'OPEN',
                'reason_code' => $reasonCode, 'reason' => $reason, 'opened_by' => $actor->id,
            ]);
            $payload = ['claim_id' => $claim->id, 'investigation_id' => $inv->id, 'case_id' => $case->id, 'reason_code' => $reasonCode];
            $this->audit->record('claim.investigation.opened', 'claim_investigation', $inv->id, $payload, $reason);
            $this->outbox->record('claim.investigation.opened', 'claim', $claim->id, $payload);

            return $inv->refresh();
        });
    }

    /**
     * Attach fraud indicators by reference. Re-attaching an indicator already on the investigation is a no-op.
     *
     * @param  list<array{indicator_id: string, indicator_code?: ?string, snapshot?: array<string, mixed>}>  $indicators
     * @return list<ClaimInvestigationIndicator> the newly attached rows
     */
    public function attachIndicators(ClaimInvestigation $inv, array $indicators, User $actor): array
    {
        return DB::transaction(function () use ($inv, $indicators, $actor) {
            $inv = $this->lockOpen($inv);
            $existing = ClaimInvestigationIndicator::where('investigation_id', $inv->id)->pluck('indicator_id')->all();
            $added = [];
            foreach ($indicators as $i) {
                $ref = (string) $i['indicator_id'];
                if (in_array($ref, $existing, true)) {
                    continue;
                }
                $existing[] = $ref;
                $added[] = ClaimInvestigationIndicator::create([
                    'investigation_id' => $inv->id, 'indicator_id' => $ref, 'indicator_code' => $i['indicator_code'] ?? null,
                    'snapshot' => $i['snapshot'] ?? [], 'attached_by' => $actor->id, 'attached_at' => now(),
                ]);
            }
            if ($added !== []) {
                $payload = ['claim_id' => $inv->claim_id, 'investigation_id' => $inv->id, 'indicator_ids' => array_map(fn ($r) => $r->indicator_id, $added)];
                $this->audit->record('claim.investigation.indicators_attached', 'claim_investigation', $inv->id, $payload);
                $this->outbox->record('claim.investigation.indicators_attached', 'claim', $inv->claim_id, $payload);
            }

            return $added;
        });
    }

    public function recordFindings(ClaimInvestigation $inv, string $findings, User $actor): ClaimInvestigation
    {
        return DB::transaction(function () use ($inv, $findings, $actor) {
            $inv = $this->lockOpen($inv);
            $old = $inv->findings;
            $inv->update(['findings' => $findings]);
            $this->audit->recordChange('claim.investigation.findings_recorded', 'claim_investigation', $inv->id, ['findings' => $old], ['findings' => $findings], 'FINDINGS', ['actor_id' => $actor->id]);

            return $inv->refresh();
        });
    }

    public function conclude(ClaimInvestigation $inv, string $outcome, string $summary, User $actor): ClaimInvestigation
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw ValidationException::withMessages(['outcome' => 'Unknown investigation outcome.']);
        }

        return DB::transaction(function () use ($inv, $outcome, $summary, $actor) {
            $inv = $this->lockOpen($inv);
            if (trim((string) $inv->findings) === '') {
                throw ValidationException::withMessages(['findings' => 'Findings must be recorded before the investigation is concluded.']);
            }
            $inv->update(['status' => 'CONCLUDED', 'outcome' => $outcome, 'outcome_summary' => $summary, 'concluded_by' => $actor->id, 'concluded_at' => now()]);
            $payload = ['claim_id' => $inv->claim_id, 'investigation_id' => $inv->id, 'case_id' => $inv->case_id, 'outcome' => $outcome];
            $this->audit->record('claim.investigation.concluded', 'claim_investigation', $inv->id, $payload, $summary);
            $this->outbox->record('claim.investigation.concluded', 'claim', $inv->claim_id, $payload);

            return $inv->refresh();
        });
    }

    public function hasOpen(Claim $claim): bool
    {
        return ClaimInvestigation::where('claim_id', $claim->id)->where('status', 'OPEN')->exists();
    }

    private function lockOpen(ClaimInvestigation $inv): ClaimInvestigation
    {
        $inv = ClaimInvestigation::whereKey($inv->id)->lockForUpdate()->firstOrFail();
        $this->tenant(Claim::findOrFail($inv->claim_id));
        if ($inv->status !== 'OPEN') {
            throw ValidationException::withMessages(['status' => 'The investigation is concluded.']);
        }

        return $inv;
    }

    private function tenant(Claim $claim): void
    {
        if ($claim->tenant_id !== app(TenantContext::class)->id()) {
            abort(404);
        }
    }
}
