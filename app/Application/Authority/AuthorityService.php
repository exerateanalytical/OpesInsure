<?php

declare(strict_types=1);

namespace App\Application\Authority;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Domain\CarrierOperations\AuthorityChecker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one authority engine (REQ-AUTH-001..003, REQ-DUP-023 authority part).
 *
 *  1. Intermediary authorization (REQ-AUTH-003): the holder partner's intermediary_authorizations must hold an
 *     AUTHORIZED/ACTIVE row covering the date. An expired/suspended-only record DENIES; no record at all REFERS
 *     (register not loaded for this partner — the carrier decides instead of a hard stop).
 *  2. Monetary limit (REQ-AUTH-001): authority_limits (POLICY_PREMIUM, holder PARTNER, line or all-lines, ACTIVE,
 *     in period) is canonical. When no row exists (agreement not backfilled yet) the legacy
 *     delegated_authority_agreements.max_policy_premium_minor is used.
 *  3. Agreement scope (status, period, line, territory) is evaluated by the legacy AuthorityChecker against the
 *     delegated_authority_agreements row, with the limit from step 2 substituted.
 *  4. Threshold exceeded → REFERRED: an AUTHORITY_REFERRAL case is opened on the case engine (REQ-AUTH-002:
 *     never a bare error). Any other scope failure → DENIED.
 *
 * Every decision is appended to authority_checks.
 */
final class AuthorityService
{
    public const LIMIT_SOURCE = 'AUTHORITY_LIMIT';

    public const LEGACY_SOURCE = 'LEGACY_AGREEMENT';

    private const INTERMEDIARY_OK = ['AUTHORIZED', 'ACTIVE'];

    public function __construct(
        private readonly AuthorityChecker $checker,
        private readonly AuthorityTypeCatalogue $types,
        private readonly CaseService $cases,
        private readonly AuditWriter $audit,
    ) {}

    /**
     * Delegated issuance/bind check against a legacy delegated_authority_agreements row (already scoped to the
     * tenant/carrier by the caller).
     *
     * @param  array{type: string, id: string, title?: string}  $subject
     */
    public function checkDelegatedIssuance(
        string $tenantId,
        object $agreement,
        string $lineCode,
        int $amountMinor,
        string $territory,
        \DateTimeImmutable $at,
        array $subject,
        ?User $actor,
        string $action = 'ISSUE',
        string $authorityType = 'POLICY_PREMIUM',
    ): AuthorityOutcome {
        $this->types->assertActive($authorityType);
        $day = $at->format('Y-m-d');

        $intermediary = $this->intermediaryAuthorization($agreement->partner_id, $day);
        $limit = $this->effectiveLimit('PARTNER', $agreement->partner_id, $agreement->carrier_id, $authorityType, $lineCode, $day, $agreement->id);
        $max = $limit ? (int) $limit->max_amount_minor : (int) $agreement->max_policy_premium_minor;

        $decision = $this->checker->check([
            'status' => $agreement->status,
            'effective_from' => $agreement->effective_from,
            'effective_until' => $agreement->effective_until,
            'permitted_lines' => $this->json($agreement->permitted_lines),
            'max_policy_premium_minor' => $max,
            'territories' => $this->json($agreement->territories),
        ], $lineCode, $amountMinor, $territory, $at);

        if ($intermediary['status'] === 'DENIED') {
            [$outcome, $reason] = [AuthorityOutcome::DENIED, 'INTERMEDIARY_NOT_AUTHORIZED'];
        } elseif (! $decision->allowed && $decision->reason !== 'PREMIUM_AUTHORITY_EXCEEDED') {
            [$outcome, $reason] = [AuthorityOutcome::DENIED, $decision->reason];
        } elseif (! $decision->allowed) {
            [$outcome, $reason] = [AuthorityOutcome::REFERRED, $decision->reason];
        } elseif ($intermediary['status'] === 'MISSING') {
            [$outcome, $reason] = [AuthorityOutcome::REFERRED, 'INTERMEDIARY_AUTHORIZATION_MISSING'];
        } else {
            [$outcome, $reason] = [AuthorityOutcome::ALLOWED, $decision->reason];
        }

        $caseId = null;
        if ($outcome === AuthorityOutcome::REFERRED) {
            $caseId = $this->cases->open($tenantId, 'AUTHORITY_REFERRAL', [
                'title' => ($subject['title'] ?? 'Authority referral').' — '.$reason,
                'subject_type' => $subject['type'], 'subject_id' => $subject['id'],
                'source_type' => 'authority_check', 'source_id' => $subject['id'],
                'carrier_id' => $agreement->carrier_id, 'priority' => 'HIGH',
                'idempotency_key' => 'authority:'.$action.':'.$subject['type'].':'.$subject['id'],
            ], $actor)->id;
        }

        $checkId = (string) Str::uuid();
        $row = [
            'id' => $checkId, 'tenant_id' => $tenantId, 'carrier_id' => $agreement->carrier_id,
            'holder_type' => 'PARTNER', 'holder_id' => $agreement->partner_id, 'authority_type' => $authorityType, 'action' => $action,
            'subject_type' => $subject['type'], 'subject_id' => $subject['id'], 'line_code' => $lineCode,
            'amount_minor' => $amountMinor, 'currency' => $limit->currency ?? 'XAF', 'outcome' => $outcome, 'reason' => $reason,
            'source' => $limit ? self::LIMIT_SOURCE : self::LEGACY_SOURCE, 'authority_limit_id' => $limit?->id,
            'delegated_authority_agreement_id' => $agreement->id, 'intermediary_authorization_id' => $intermediary['id'],
            'referral_case_id' => $caseId, 'checked_by' => $actor?->id, 'created_at' => now(),
        ];
        $result = new AuthorityOutcome($outcome, $reason, $limit ? self::LIMIT_SOURCE : self::LEGACY_SOURCE, $limit?->id, $max,
            $intermediary['id'], $caseId, $checkId, $row);

        // A DENIED caller throws and rolls its transaction back; it must call record() after the rollback so the
        // blocked attempt stays in the registry (owner decision on 7A). ALLOWED/REFERRED commit with the caller.
        if ($outcome !== AuthorityOutcome::DENIED) {
            $this->record($result);
        }

        return $result;
    }

    /** Append the decision to authority_checks (+ audit). Idempotent on the check id. */
    public function record(AuthorityOutcome $outcome): void
    {
        $row = $outcome->record;
        if (DB::table('authority_checks')->where('id', $row['id'])->exists()) {
            return;
        }
        DB::table('authority_checks')->insert($row);
        $this->audit->record('authority.checked', 'authority_check', $row['id'], [
            'outcome' => $row['outcome'], 'reason' => $row['reason'], 'action' => $row['action'],
            'amount_minor' => $row['amount_minor'], 'referral_case_id' => $row['referral_case_id'],
        ]);
    }

    /**
     * Canonical limit lookup (REQ-AUTH-001). A line-specific row wins over an all-lines (NULL) row; the lowest
     * amount wins among equals. $legacyAgreementId narrows to the rows backfilled from that agreement.
     */
    public function effectiveLimit(string $holderType, string $holderId, ?string $carrierId, string $authorityType, ?string $lineCode, string $day, ?string $legacyAgreementId = null): ?object
    {
        return DB::table('authority_limits')
            ->where('holder_type', $holderType)->where('holder_id', $holderId)
            ->where('authority_type', $authorityType)->where('status', 'ACTIVE')
            ->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))
            ->when($legacyAgreementId, fn ($q) => $q->where(fn ($w) => $w->where('legacy_delegated_authority_agreement_id', $legacyAgreementId)
                ->orWhereIn('carrier_broker_agreement_id', DB::table('carrier_broker_agreements')->select('id')->where('legacy_delegated_authority_agreement_id', $legacyAgreementId))))
            ->where(fn ($q) => $q->whereNull('line_code')->orWhere('line_code', $lineCode))
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day))
            ->orderByRaw('CASE WHEN line_code IS NULL THEN 1 ELSE 0 END')->orderBy('max_amount_minor')
            ->first();
    }

    /** @return array{status: 'OK'|'MISSING'|'DENIED', id: ?string} */
    public function intermediaryAuthorization(string $partnerId, string $day): array
    {
        $rows = DB::table('intermediary_authorizations')->where('partner_id', $partnerId)->get();
        if ($rows->isEmpty()) {
            return ['status' => 'MISSING', 'id' => null];
        }
        $current = $rows->first(fn ($r) => in_array($r->status, self::INTERMEDIARY_OK, true)
            && (string) $r->effective_from <= $day && ($r->effective_until === null || (string) $r->effective_until >= $day));

        return $current ? ['status' => 'OK', 'id' => $current->id] : ['status' => 'DENIED', 'id' => null];
    }

    /** @return list<string> */
    private function json(mixed $v): array
    {
        return is_array($v) ? $v : (array) json_decode((string) $v, true);
    }
}
