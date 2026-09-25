<?php

declare(strict_types=1);

namespace App\Application\Claims\Reserves;

use App\Application\Authority\AuthorityOutcome;
use App\Application\Authority\AuthorityService;
use App\Application\Authority\AuthorityTypeCatalogue;
use App\Application\Cases\CaseService;
use App\Models\Claim;
use App\Models\ClaimReserveChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RESERVE_APPROVE authority for a reserve checker, on the one authority engine: limits come from
 * AuthorityService::effectiveLimit (authority_limits, holder USER, carrier of the policy, line or all-lines) and
 * every decision is appended through AuthorityService::record (authority_checks + audit).
 *
 *  - no limit configured for the approver → ALLOWED (source PERMISSION: the claims.reserve.approve permission is
 *    the only control, as before REQ-CLM-008);
 *  - reserve amount within the limit → ALLOWED;
 *  - over the limit → REFERRED with an AUTHORITY_REFERRAL case (REQ-AUTH-002: never a bare error).
 */
final class ReserveAuthority
{
    public const TYPE = 'RESERVE_APPROVE';

    public function __construct(
        private readonly AuthorityService $authority,
        private readonly AuthorityTypeCatalogue $types,
        private readonly CaseService $cases,
    ) {}

    public function check(Claim $claim, ClaimReserveChange $change, User $actor): AuthorityOutcome
    {
        $policy = DB::table('policies')->where('id', $claim->policy_id)->first(['carrier_id', 'terms_snapshot']);
        $carrierId = $policy?->carrier_id;
        $line = $policy ? (json_decode((string) $policy->terms_snapshot, true)['line_code'] ?? null) : null;
        $amount = (int) $change->requested_amount_minor;
        $limit = $this->types->isActive(self::TYPE)
            ? $this->authority->effectiveLimit('USER', $actor->id, $carrierId, self::TYPE, $line, now()->toDateString())
            : null;

        if (! $limit) {
            [$outcome, $reason, $source] = [AuthorityOutcome::ALLOWED, 'NO_LIMIT_CONFIGURED', 'PERMISSION'];
        } elseif ($amount <= (int) $limit->max_amount_minor) {
            [$outcome, $reason, $source] = [AuthorityOutcome::ALLOWED, 'WITHIN_LIMIT', AuthorityService::LIMIT_SOURCE];
        } else {
            [$outcome, $reason, $source] = [AuthorityOutcome::REFERRED, 'RESERVE_AUTHORITY_EXCEEDED', AuthorityService::LIMIT_SOURCE];
        }

        $caseId = null;
        if ($outcome === AuthorityOutcome::REFERRED) {
            $caseId = $this->cases->open($claim->tenant_id, 'AUTHORITY_REFERRAL', [
                'title' => 'Reserve approval referral '.$claim->claim_number.' — '.$reason,
                'subject_type' => 'claim_reserve_change', 'subject_id' => $change->id,
                'source_type' => 'authority_check', 'source_id' => $change->id,
                'carrier_id' => $carrierId, 'priority' => 'HIGH',
                'idempotency_key' => 'reserve-approve:'.$change->id.':'.substr(sha1($actor->id), 0, 12),
            ], $actor)->id;
        }

        $checkId = (string) Str::uuid();
        $row = [
            'id' => $checkId, 'tenant_id' => $claim->tenant_id, 'carrier_id' => $carrierId,
            'holder_type' => 'USER', 'holder_id' => $actor->id, 'authority_type' => self::TYPE, 'action' => 'RESERVE_APPROVE',
            'subject_type' => 'claim_reserve_change', 'subject_id' => $change->id, 'line_code' => $line,
            'amount_minor' => $amount, 'currency' => $change->currency, 'outcome' => $outcome, 'reason' => $reason,
            'source' => $source, 'authority_limit_id' => $limit?->id, 'delegated_authority_agreement_id' => null,
            'intermediary_authorization_id' => null, 'referral_case_id' => $caseId, 'checked_by' => $actor->id, 'created_at' => now(),
        ];
        $result = new AuthorityOutcome($outcome, $reason, $source, $limit?->id, $limit ? (int) $limit->max_amount_minor : null, null, $caseId, $checkId, $row);
        $this->authority->record($result);

        return $result;
    }
}
