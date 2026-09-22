<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Identity\PartyResolver;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Customer-facing "my policies" — distinct from the existing staff-facing
 * PolicyController::index/show, which have no ownership check at all (only
 * tenant scoping). Unlike PaymentIntentRecord, Policy already has a direct
 * party_id, so no join through proposal is needed.
 */
final class MobileWalletService
{
    public function __construct(private PartyResolver $parties)
    {
    }

    public function wallet(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->ownedQuery($user, $tenantId)->with('carrier')->orderByDesc('issued_at')->paginate($perPage);
    }

    public function policy(string $policyId, User $user, string $tenantId): Policy
    {
        return $this->owned($policyId, $user, $tenantId)->load(['carrier', 'certificates', 'fulfilmentOrder']);
    }

    /** @return array<string, mixed> Certificate metadata only — no signed document URL exists anywhere in this app yet; see the batch report. */
    public function certificate(string $policyId, User $user, string $tenantId): array
    {
        $policy = $this->owned($policyId, $user, $tenantId);
        $certificate = $policy->certificates()->where('status', 'VALID')->latest('issued_at')->first();

        if (! $certificate) {
            throw new ModelNotFoundException;
        }

        return [
            'id' => $certificate->id,
            'serial_number' => $certificate->serial_number,
            'status' => $certificate->status,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
        ];
    }

    private function owned(string $policyId, User $user, string $tenantId): Policy
    {
        $policy = $this->ownedQuery($user, $tenantId)->find($policyId);

        if (! $policy) {
            $exists = Policy::where('tenant_id', $tenantId)->where('id', $policyId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $policy;
    }

    private function ownedQuery(User $user, string $tenantId)
    {
        $party = $this->parties->forUser($user);
        $query = Policy::where('tenant_id', $tenantId);

        if (! $party) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('party_id', $party->id);
    }
}
