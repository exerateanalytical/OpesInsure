<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Identity\PartyResolver;
use App\Models\Document;
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
    public function __construct(private PartyResolver $parties, private PolicyDocumentService $documents)
    {
    }

    public function wallet(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->ownedQuery($user, $tenantId)->with(['carrier.party', 'proposal.offer.product', 'proposal.offer.quote'])->orderByDesc('issued_at')->paginate($perPage);
    }

    public function policy(string $policyId, User $user, string $tenantId): Policy
    {
        return $this->owned($policyId, $user, $tenantId)->load(['carrier.party', 'certificates', 'fulfilmentOrder', 'proposal.offer.product', 'proposal.offer.quote']);
    }

    /**
     * Wallet detail: the policy row (unchanged keys, for older builds) plus
     * carrier_name, product_name, line_code, documents[] (signed PDF links),
     * certificate and delivery. Missing issuance documents are generated on
     * first view (PolicyDocumentService::ensure is idempotent).
     *
     * @return array<string, mixed>
     */
    public function policyDetail(string $policyId, User $user, string $tenantId): array
    {
        $policy = $this->policy($policyId, $user, $tenantId);
        $this->ensureDocuments($policy);
        $policy->load('certificates');

        return array_merge($policy->toArray(), $this->summary($policy), [
            'documents' => $this->documents->documentsPayload($policy),
            'certificate' => $this->certificatePayload($policy),
            'delivery' => $policy->fulfilmentOrder ? [
                'id' => $policy->fulfilmentOrder->id,
                'status' => $policy->fulfilmentOrder->status,
                'delivery_address' => $policy->fulfilmentOrder->delivery_address,
                'sla_due_at' => $policy->fulfilmentOrder->sla_due_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /** @return array<string, mixed> list-row fields shared by the list and the detail */
    public function summary(Policy $policy): array
    {
        $product = $policy->proposal?->offer?->product;

        return [
            'carrier_name' => $policy->carrier?->party?->display_name,
            'carrier_phone' => $policy->carrier?->party?->contacts()->where('type', 'PHONE')->value('normalized_value'),
            'product_name' => $product?->name,
            'line_code' => $product?->line_code ?? $policy->proposal?->offer?->quote?->line_code,
            'days_to_expiry' => $policy->coverage_ends_at ? (int) floor(now()->diffInDays($policy->coverage_ends_at, false)) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function certificate(string $policyId, User $user, string $tenantId): array
    {
        $policy = $this->owned($policyId, $user, $tenantId);
        $this->ensureDocuments($policy);
        $payload = $this->certificatePayload($policy);

        if (! $payload) {
            throw new ModelNotFoundException;
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function certificatePayload(Policy $policy): ?array
    {
        $certificate = $policy->certificates()->where('status', 'VALID')->latest('issued_at')->first();
        if (! $certificate) {
            return null;
        }
        $pdf = Document::where('policy_id', $policy->id)->where('category', PolicyDocumentService::CERTIFICATE)->latest('created_at')->first();

        return [
            'id' => $certificate->id,
            'serial_number' => $certificate->serial_number,
            'status' => $certificate->status,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'verification_url' => PolicyDocumentService::verifyUrl($certificate),
            'download_url' => $pdf ? $this->documents->downloadUrl($pdf) : null,
        ];
    }

    /** Only in-force/issued policies get documents; never throws into a read. */
    private function ensureDocuments(Policy $policy): void
    {
        if (! $policy->policy_number || ! in_array($policy->status, ['ACTIVE', 'EXPIRING', 'EXPIRED', 'ENDORSEMENT_PENDING', 'SUSPENDED', 'CANCELLATION_PENDING'], true)) {
            return;
        }
        try {
            $this->documents->ensure($policy);
        } catch (\Throwable $e) {
            report($e);
        }
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
