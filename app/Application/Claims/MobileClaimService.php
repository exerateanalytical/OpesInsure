<?php

declare(strict_types=1);

namespace App\Application\Claims;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Identity\PartyResolver;
use App\Domain\Claims\ClaimMachine;
use App\Models\Claim;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing "my claims" — the mobile front door onto the real claims
 * machinery in FnolService/ClaimLifecycleService/ClaimStateMachine, not a parallel
 * implementation. Ownership mirrors MobileWalletService/MobileDocumentService
 * exactly, scoped through Claim.claimant_party_id instead of a direct
 * party_id column.
 *
 * fnol() deliberately does not re-validate policy ownership or coverage
 * dates itself — ClaimLifecycleService::fnol() already does both (see its
 * 'claimant_invalid'/'loss_outside_cover' checks) and this method supplies
 * the authenticated customer's own resolved party_id as claimant_party_id,
 * so a client can never submit a claim as someone else's claimant no matter
 * what it puts in the request body.
 */
final class MobileClaimService
{
    public function __construct(
        private PartyResolver $parties,
        private Fnol\FnolService $fnol,
        private ClaimTransitions $transitions,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
    ) {
    }

    public function list(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->ownedQuery($user, $tenantId)->with('policy')->orderByDesc('submitted_at')->paginate($perPage);
    }

    public function show(string $claimId, User $user, string $tenantId): Claim
    {
        $claim = $this->owned($claimId, $user, $tenantId)->load('policy');

        return $claim->setAttribute('can_withdraw', self::canWithdraw($claim));
    }

    public static function canWithdraw(Claim $claim): bool
    {
        return in_array($claim->status, ClaimMachine::WITHDRAWABLE, true);
    }

    /**
     * Claimant withdrawal: only the claim's own claimant, only before assessment/decision/payment
     * (ClaimMachine::WITHDRAWABLE → event `withdraw` → CLOSED). Goes through ClaimTransitions (guards +
     * machine history) and writes claim_events, audit and outbox in one transaction like every claim action.
     */
    public function withdraw(string $claimId, string $reason, User $user, string $tenantId): Claim
    {
        $this->owned($claimId, $user, $tenantId);

        return DB::transaction(function () use ($claimId, $reason, $user) {
            $claim = Claim::whereKey($claimId)->lockForUpdate()->firstOrFail();
            if (! self::canWithdraw($claim)) {
                throw ValidationException::withMessages(['status' => __('wave12.claim_withdraw_not_allowed')]);
            }
            $from = $claim->status;
            $to = $this->transitions->apply($claim, 'CLOSED', $user, 'WITHDRAWN_BY_CLAIMANT', ['withdrawal_reason' => $reason])->to;
            $claim->update([
                'status' => $to, 'closed_at' => now(), 'withdrawn_at' => now(), 'withdrawal_reason' => $reason,
                'closure_summary' => 'Withdrawn by claimant: '.$reason, 'version' => (int) $claim->version + 1,
            ]);
            DB::table('claim_events')->insert([
                'id' => (string) Str::uuid(), 'claim_id' => $claim->id, 'type' => 'CLAIM_WITHDRAWN', 'from_status' => $from, 'to_status' => $to,
                'reason_code' => 'WITHDRAWN_BY_CLAIMANT', 'actor_id' => $user->id,
                'details' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR), 'occurred_at' => now(),
            ]);
            $payload = ['from' => $from, 'to' => $to, 'claim_number' => $claim->claim_number];
            $this->audit->record('claim.withdrawn', 'claim', $claim->id, $payload + ['reason' => $reason], 'WITHDRAWN_BY_CLAIMANT');
            $this->outbox->record('claim.withdrawn', 'claim', $claim->id, $payload);

            return $claim->refresh()->load('policy')->setAttribute('can_withdraw', false);
        });
    }

    /** @return LengthAwarePaginator Chronological (oldest first) status-change history for one owned claim. */
    public function timeline(string $claimId, User $user, string $tenantId, int $perPage = 50): LengthAwarePaginator
    {
        $claim = $this->owned($claimId, $user, $tenantId);

        return DB::table('claim_events')
            ->where('claim_id', $claim->id)
            ->orderBy('occurred_at')
            ->select(['id', 'type', 'from_status', 'to_status', 'occurred_at'])
            ->paginate($perPage);
    }

    /**
     * @param  array{policy_id: string, incident_at: string, incident_location?: ?string, description: string, incident_type?: ?string, injuries_reported?: bool, police_report_filed?: bool, police_reference?: ?string, estimated_loss_minor?: ?int, idempotency_key: string}  $data
     */
    public function fnol(array $data, User $user, string $tenantId): Claim
    {
        $party = $this->parties->forUser($user);

        if (! $party) {
            throw ValidationException::withMessages(['party' => __('wave12.claim_no_party')]);
        }

        $lossDetails = array_filter([
            'description' => $data['description'],
            'incident_type' => $data['incident_type'] ?? null,
            'injuries_reported' => $data['injuries_reported'] ?? false,
            'police_report_filed' => $data['police_report_filed'] ?? false,
            'police_reference' => ($data['police_report_filed'] ?? false) ? ($data['police_reference'] ?? null) : null,
        ], static fn ($value) => $value !== null);

        // REQ-DUP-007: one FNOL path — FnolService (→ ClaimLifecycleService::fnol) also pins the
        // capability and writes the immutable FNOL snapshot for the MOBILE channel.
        return $this->fnol->submit($tenantId, [
            'policy_id' => $data['policy_id'],
            'claimant_party_id' => $party->id,
            'loss_occurred_at' => $data['incident_at'],
            'loss_details' => $lossDetails,
            'loss_location' => $data['incident_location'] ?? null,
            'estimated_loss_minor' => $data['estimated_loss_minor'] ?? null,
            'idempotency_key' => $data['idempotency_key'],
        ], $user, ['channel' => 'MOBILE', 'role' => 'CUSTOMER']);
    }

    public function owned(string $claimId, User $user, string $tenantId): Claim
    {
        $claim = $this->ownedQuery($user, $tenantId)->find($claimId);

        if (! $claim) {
            $exists = Claim::where('tenant_id', $tenantId)->where('id', $claimId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $claim;
    }

    private function ownedQuery(User $user, string $tenantId)
    {
        $party = $this->parties->forUser($user);
        $query = Claim::where('tenant_id', $tenantId);

        if (! $party) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('claimant_party_id', $party->id);
    }
}
