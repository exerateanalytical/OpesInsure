<?php

declare(strict_types=1);

namespace App\Application\Claims\Coverage;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Shared\CanonicalJson;
use App\Models\Claim;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-003 — runs the coverage-at-loss engine for a claim and stores the result on the claim as an immutable
 * snapshot (claim_coverage_checks). A non-confirmed outcome is never a rejection: it waits for a human
 * resolution (maker-checker: the resolver is not the user who ran the check), and while the latest check is
 * unresolved the claim cannot be approved (ClaimCoverageTransitionGuard).
 */
final class ClaimCoverageCheckService
{
    public const RESOLUTIONS = ['COVERED', 'NOT_COVERED', 'PARTIALLY_COVERED'];

    /** Approval events / target states under both the stored and the blueprint vocabularies. */
    public const APPROVAL_EVENTS = ['approve', 'partially_approve']; // ClaimMachine events

    /** Blueprint target states that count as an approval (ClaimTransitions context 'to'). */
    public const APPROVAL_STATES = ['APPROVED', 'PARTIALLY_APPROVED'];

    public const BLOCK_REASON = 'COVERAGE_REVIEW_UNRESOLVED';

    public function __construct(
        private readonly CoverageAtLossEngine $engine,
        private readonly CanonicalJson $json,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param array<string, mixed> $facts */
    public function checkClaim(Claim $claim, ?string $coverageCode, array $facts, ?User $actor): object
    {
        $policy = Policy::findOrFail($claim->policy_id);
        $coverageCode ??= $claim->loss_details['coverage_code'] ?? null;
        $reportedAt = $claim->submitted_at ?? $claim->created_at ?? now();
        $result = $this->engine->evaluate($policy, $claim->loss_occurred_at, $reportedAt, $coverageCode, $facts);
        $result['claim_id'] = $claim->id;
        $result['facts'] = $facts;

        return DB::transaction(function () use ($claim, $result, $actor): object {
            $id = (string) Str::uuid();
            $now = now();
            DB::table('claim_coverage_checks')->insert([
                'id' => $id, 'tenant_id' => $claim->tenant_id, 'claim_id' => $claim->id, 'policy_id' => $claim->policy_id,
                'policy_version_id' => $result['policy_version']['id'] ?? null, 'reported_policy_version_id' => $result['policy_version_known_at_report']['id'] ?? null,
                'coverage_code' => $result['coverage_code'], 'loss_occurred_at' => $result['loss_occurred_at'], 'reported_at' => $result['reported_at'],
                'outcome' => $result['outcome'], 'reasons' => json_encode($result['reasons']), 'snapshot' => json_encode($result),
                'snapshot_hash' => $this->json->hash($result), 'engine_version' => CoverageAtLossEngine::ENGINE_VERSION,
                'checked_by' => $actor?->id, 'checked_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $payload = ['check_id' => $id, 'outcome' => $result['outcome'], 'requires_review' => $result['requires_review'], 'reason_codes' => array_column($result['reasons'], 'code')];
            $this->audit->record('claim.coverage.checked', 'claim', $claim->id, $payload);
            $this->outbox->record('claim.coverage.checked', 'claim', $claim->id, $payload);

            return $this->find($id);
        });
    }

    public function resolve(object $check, string $resolution, string $note, User $actor): object
    {
        if (! in_array($resolution, self::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['resolution' => 'Unknown resolution.']);
        }

        return DB::transaction(function () use ($check, $resolution, $note, $actor): object {
            $row = DB::table('claim_coverage_checks')->where('id', $check->id)->lockForUpdate()->first();
            if ($row->outcome === 'COVERAGE_CONFIRMED' || $row->resolution !== null) {
                throw ValidationException::withMessages(['status' => 'COVERAGE_CHECK_NOT_RESOLVABLE']);
            }
            if ($row->checked_by !== null && $row->checked_by === $actor->id) {
                throw ValidationException::withMessages(['status' => 'COVERAGE_RESOLVER_MUST_DIFFER']);
            }
            DB::table('claim_coverage_checks')->where('id', $row->id)->update([
                'resolution' => $resolution, 'resolution_note' => $note, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'updated_at' => now(),
            ]);
            $payload = ['check_id' => $row->id, 'outcome' => $row->outcome, 'resolution' => $resolution];
            $this->audit->record('claim.coverage.resolved', 'claim', $row->claim_id, $payload, $note);
            $this->outbox->record('claim.coverage.resolved', 'claim', $row->claim_id, $payload);

            return $this->find($row->id);
        });
    }

    /** The latest check governs: null when approval may proceed, else the blocking reason code. */
    public function approvalBlocker(Claim $claim): ?string
    {
        $latest = DB::table('claim_coverage_checks')->where('claim_id', $claim->id)->orderByDesc('checked_at')->orderByDesc('created_at')->first();
        if ($latest === null || $latest->outcome === 'COVERAGE_CONFIRMED' || $latest->resolution !== null) {
            return null;
        }

        return self::BLOCK_REASON;
    }

    /** @return list<object> */
    public function forClaim(Claim $claim): array
    {
        return DB::table('claim_coverage_checks')->where('claim_id', $claim->id)->orderByDesc('checked_at')->get()->map(fn ($r) => $this->present($r))->all();
    }

    public function find(string $id): ?object
    {
        $r = DB::table('claim_coverage_checks')->where('id', $id)->first();

        return $r ? $this->present($r) : null;
    }

    private function present(object $r): object
    {
        $r->reasons = json_decode((string) $r->reasons, true);
        $r->snapshot = json_decode((string) $r->snapshot, true);
        $r->requires_review = $r->outcome !== 'COVERAGE_CONFIRMED';
        $r->review_open = $r->requires_review && $r->resolution === null;

        return $r;
    }
}
