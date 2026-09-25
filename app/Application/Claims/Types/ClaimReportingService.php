<?php

declare(strict_types=1);

namespace App\Application\Claims\Types;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseProblem;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Events\OutboxWriter;
use App\Models\Claim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-007 — reporting-deadline check at FNOL and the late-claim approval workflow.
 *  atFnol()     resolves the claim type (claim_reporting_checks) and compares reported_at with loss + deadline days.
 *               A late claim is FLAGGED, never refused: a CLAIM_COVERAGE_REVIEW case (sub-type LATE_REPORTING) is opened.
 *  recommend()  maker: a handler recommends ACCEPT / REJECT of the late report with a rationale.
 *  decide()     checker (≠ maker): APPROVE / REJECT; an append-only case decision is recorded.
 * LateClaimTransitionGuard blocks `start_assessment` until the late report is APPROVED.
 */
final class ClaimReportingService
{
    public const CASE_TYPE = 'CLAIM_COVERAGE_REVIEW';

    public const CASE_SUBTYPE = 'LATE_REPORTING';

    public function __construct(
        private readonly ClaimTypeCatalogue $types,
        private readonly CaseService $cases,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function atFnol(Claim $claim, ?string $claimTypeCode, ?User $actor): ?object
    {
        if ($existing = $this->check($claim->id)) {
            return $existing;
        }
        $type = $this->types->resolve($claim, $claimTypeCode);
        $loss = CarbonImmutable::parse($claim->loss_occurred_at);
        $reported = CarbonImmutable::parse($claim->submitted_at ?? now());
        $days = $type['reporting_deadline_days'] ?? null;
        $deadline = $days === null ? null : $loss->addDays($days);
        $late = $deadline !== null && $reported->gt($deadline);
        // whole calendar days past the deadline date (sub-day remainders never add a day)
        $daysLate = $late ? max(1, (int) $deadline->copy()->startOfDay()->diffInDays($reported->copy()->startOfDay())) : 0;

        $id = (string) Str::uuid();
        DB::table('claim_reporting_checks')->insert([
            'id' => $id, 'tenant_id' => $claim->tenant_id, 'claim_id' => $claim->id, 'claim_type_version_id' => $type['id'] ?? null,
            'claim_type_code' => $type['code'] ?? null, 'line_code' => $type['line_code'] ?? $this->types->lineFor($claim)['line_code'],
            'loss_occurred_at' => $loss, 'reported_at' => $reported, 'deadline_days' => $days, 'deadline_at' => $deadline,
            'days_late' => $daysLate, 'late' => $late, 'approval_status' => $late ? 'PENDING' : 'NOT_REQUIRED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = ['claim_id' => $claim->id, 'claim_type' => $type['code'] ?? null, 'claim_type_version' => $type['version'] ?? null,
            'deadline_days' => $days, 'late' => $late, 'days_late' => $daysLate];
        $this->audit->record('claim.reporting.checked', 'claim', $claim->id, $payload);
        if ($late) {
            $caseId = null;
            try {
                $caseId = $this->cases->open($claim->tenant_id, self::CASE_TYPE, [
                    'title' => "Late claim report {$claim->claim_number} ({$daysLate} day(s) after the configured reporting period)",
                    'case_subtype' => self::CASE_SUBTYPE, 'subject_type' => 'claim', 'subject_id' => $claim->id,
                    'source_type' => 'claim_reporting_check', 'source_id' => $id, 'idempotency_key' => 'late-claim-'.$claim->id,
                ], $actor)->id;
            } catch (CaseProblem) {
                // no effective case type: the approval still runs on claim_reporting_checks
            }
            DB::table('claim_reporting_checks')->where('id', $id)->update(['case_id' => $caseId]);
            $this->outbox->record('claim.reported_late', 'claim', $claim->id, $payload + ['case_id' => $caseId]);
        }

        return $this->check($claim->id);
    }

    /** Maker: recommendation on a PENDING late report. */
    public function recommend(Claim $claim, string $recommendation, string $rationale, User $maker): object
    {
        $recommendation = strtoupper($recommendation);
        if (! in_array($recommendation, ['ACCEPT', 'REJECT'], true)) {
            throw ValidationException::withMessages(['recommendation' => 'Recommendation must be ACCEPT or REJECT.']);
        }
        $this->rationale($rationale);

        return DB::transaction(function () use ($claim, $recommendation, $rationale, $maker) {
            $c = $this->locked($claim->id);
            if (! in_array($c->approval_status, ['PENDING', 'RECOMMENDED'], true)) {
                throw ValidationException::withMessages(['status' => 'This claim has no late report awaiting approval.']);
            }
            DB::table('claim_reporting_checks')->where('id', $c->id)->update(['approval_status' => 'RECOMMENDED', 'recommendation' => $recommendation,
                'recommended_by' => $maker->id, 'recommended_at' => now(), 'recommendation_rationale' => $rationale, 'updated_at' => now()]);
            $payload = ['claim_id' => $claim->id, 'recommendation' => $recommendation, 'recommended_by' => $maker->id, 'case_id' => $c->case_id];
            $this->audit->record('claim.late_report.recommended', 'claim', $claim->id, $payload, $rationale);
            $this->outbox->record('claim.late_report.recommended', 'claim', $claim->id, $payload);

            return $this->check($claim->id);
        });
    }

    /** Checker: APPROVE / REJECT the late report; the checker cannot be the maker. */
    public function decide(Claim $claim, string $decision, string $rationale, User $checker): object
    {
        $decision = strtoupper($decision);
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw ValidationException::withMessages(['decision' => 'Decision must be APPROVE or REJECT.']);
        }
        $this->rationale($rationale);

        return DB::transaction(function () use ($claim, $decision, $rationale, $checker) {
            $c = $this->locked($claim->id);
            if ($c->approval_status !== 'RECOMMENDED') {
                throw ValidationException::withMessages(['status' => 'A late report needs a handler recommendation before a decision.']);
            }
            if ($c->recommended_by === $checker->id) {
                throw ValidationException::withMessages(['decided_by' => 'Maker-checker: the recommending handler cannot decide the late report.']);
            }
            $status = $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED';
            if ($c->case_id && ($case = WorkCase::withoutGlobalScopes()->find($c->case_id))) {
                $this->cases->decide($case, ['decision_type' => 'LATE_CLAIM_REPORT', 'outcome' => $status, 'rationale' => $rationale,
                    'conditions' => ['recommended_by' => $c->recommended_by, 'recommendation' => $c->recommendation]], $checker);
            }
            DB::table('claim_reporting_checks')->where('id', $c->id)->update(['approval_status' => $status, 'decided_by' => $checker->id,
                'decided_at' => now(), 'decision_rationale' => $rationale, 'updated_at' => now()]);
            $payload = ['claim_id' => $claim->id, 'outcome' => $status, 'decided_by' => $checker->id, 'recommended_by' => $c->recommended_by, 'case_id' => $c->case_id];
            $this->audit->record('claim.late_report.decided', 'claim', $claim->id, $payload, $rationale);
            $this->outbox->record('claim.late_report.decided', 'claim', $claim->id, $payload);

            return $this->check($claim->id);
        });
    }

    /** Guard: null when the claim may start assessment, else the blocking reason code. */
    public function assessmentBlocker(Claim $claim): ?string
    {
        $c = $this->check($claim->id);
        if (! $c || ! $c->late) {
            return null;
        }

        return match ($c->approval_status) {
            'APPROVED' => null,
            'REJECTED' => 'LATE_CLAIM_REJECTED',
            default => 'LATE_CLAIM_APPROVAL_REQUIRED',
        };
    }

    public function check(string $claimId): ?object
    {
        return DB::table('claim_reporting_checks')->where('claim_id', $claimId)->first();
    }

    private function locked(string $claimId): object
    {
        return DB::table('claim_reporting_checks')->where('claim_id', $claimId)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['status' => 'This claim has no reporting check.']);
    }

    private function rationale(string $r): void
    {
        if (mb_strlen(trim($r)) < 10) {
            throw ValidationException::withMessages(['rationale' => 'A rationale of at least 10 characters is required.']);
        }
    }
}
