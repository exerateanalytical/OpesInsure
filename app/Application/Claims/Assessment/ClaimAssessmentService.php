<?php

declare(strict_types=1);

namespace App\Application\Claims\Assessment;

use App\Application\Audit\AuditWriter;
use App\Application\Claims\Assessment\Models\ClaimAssessment;
use App\Application\Events\OutboxWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-010 / WF-054 — claim assessment. An assessment is a RECOMMENDATION: a recommended amount per
 * head, a rationale, the assessor and (optionally) the adjuster report it rests on. It never changes the
 * claim's status or approved amount; the decision is taken elsewhere (ClaimLifecycleService).
 * Accepting an assessment (four-eyes: not the assessor) makes it the reference recommendation and
 * supersedes any earlier accepted one; ClaimAssessmentTransitionGuard requires one before DECISION_PENDING.
 */
final class ClaimAssessmentService
{
    /** Claim statuses in which no new assessment may be recorded. */
    public const CLOSED_STATUSES = ['DRAFT', 'CLOSED', 'SETTLED', 'CANCELLED', 'WITHDRAWN'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /**
     * @param  array{heads: list<array{head_code: string, recommended_minor: int, claimed_minor?: ?int, note?: ?string}>, rationale: string, adjuster_report_document_id?: ?string}  $d
     */
    public function record(Claim $claim, array $d, User $assessor): ClaimAssessment
    {
        $this->tenant($claim);
        if (in_array($claim->status, self::CLOSED_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'An assessment cannot be recorded on a claim in status '.$claim->status.'.']);
        }
        $heads = [];
        foreach ($d['heads'] as $h) {
            $code = strtoupper(trim((string) $h['head_code']));
            if (isset($heads[$code])) {
                throw ValidationException::withMessages(['heads' => "Head {$code} appears more than once."]);
            }
            $heads[$code] = ['head_code' => $code, 'claimed_minor' => isset($h['claimed_minor']) ? (int) $h['claimed_minor'] : null,
                'recommended_minor' => (int) $h['recommended_minor'], 'note' => $h['note'] ?? null];
        }
        if ($heads === []) {
            throw ValidationException::withMessages(['heads' => 'At least one head is required.']);
        }
        $reportId = $d['adjuster_report_document_id'] ?? null;
        if ($reportId !== null && ! DB::table('documents')->where('id', $reportId)->where('tenant_id', $claim->tenant_id)->exists()) {
            throw ValidationException::withMessages(['adjuster_report_document_id' => 'Unknown adjuster report document.']);
        }

        return DB::transaction(function () use ($claim, $heads, $d, $reportId, $assessor) {
            $a = ClaimAssessment::create([
                'tenant_id' => $claim->tenant_id, 'claim_id' => $claim->id,
                'assessment_number' => 'ASM-'.now()->format('Ym').'-'.strtoupper(Str::random(10)),
                'status' => 'SUBMITTED', 'heads' => array_values($heads),
                'recommended_total_minor' => array_sum(array_column($heads, 'recommended_minor')),
                'currency' => $claim->currency ?? 'XAF', 'rationale' => $d['rationale'],
                'assessor_user_id' => $assessor->id, 'adjuster_report_document_id' => $reportId,
            ]);
            $payload = ['claim_id' => $claim->id, 'assessment_id' => $a->id, 'recommended_total_minor' => $a->recommended_total_minor, 'currency' => $a->currency];
            $this->audit->record('claim.assessment.recorded', 'claim_assessment', $a->id, $payload);
            $this->outbox->record('claim.assessment.recorded', 'claim', $claim->id, $payload);

            return $a->refresh();
        });
    }

    public function accept(ClaimAssessment $a, User $actor, ?string $note = null): ClaimAssessment
    {
        return $this->review($a, $actor, 'ACCEPTED', $note);
    }

    public function reject(ClaimAssessment $a, User $actor, string $reason): ClaimAssessment
    {
        return $this->review($a, $actor, 'REJECTED', $reason);
    }

    public function hasAccepted(Claim $claim): bool
    {
        return ClaimAssessment::where('claim_id', $claim->id)->where('status', 'ACCEPTED')->exists();
    }

    private function review(ClaimAssessment $a, User $actor, string $to, ?string $note): ClaimAssessment
    {
        return DB::transaction(function () use ($a, $actor, $to, $note) {
            $a = ClaimAssessment::whereKey($a->id)->lockForUpdate()->firstOrFail();
            $claim = Claim::whereKey($a->claim_id)->lockForUpdate()->firstOrFail();
            $this->tenant($claim);
            if ($a->status !== 'SUBMITTED') {
                throw ValidationException::withMessages(['status' => 'Only a submitted assessment can be reviewed.']);
            }
            if ($a->assessor_user_id === $actor->id) {
                throw ValidationException::withMessages(['status' => 'The assessor cannot review their own assessment.']);
            }
            $superseded = [];
            if ($to === 'ACCEPTED') {
                $superseded = ClaimAssessment::where('claim_id', $claim->id)->where('status', 'ACCEPTED')->pluck('id')->all();
                ClaimAssessment::whereIn('id', $superseded)->update(['status' => 'SUPERSEDED', 'updated_at' => now()]);
            }
            $a->update(['status' => $to, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $event = $to === 'ACCEPTED' ? 'claim.assessment.accepted' : 'claim.assessment.rejected';
            $payload = ['claim_id' => $claim->id, 'assessment_id' => $a->id, 'superseded' => $superseded];
            $this->audit->record($event, 'claim_assessment', $a->id, $payload, $note);
            $this->outbox->record($event, 'claim', $claim->id, $payload);

            return $a->refresh();
        });
    }

    private function tenant(Claim $claim): void
    {
        if ($claim->tenant_id !== app(TenantContext::class)->id()) {
            abort(404);
        }
    }
}
