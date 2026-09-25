<?php

declare(strict_types=1);

namespace App\Application\Underwriting;

use App\Application\Identity\PartyResolver;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * REQ-DUP-007 — thin mobile adapter over ProposalService (no business rules here): owner-scoped lookup, the
 * app-facing list/present projection (mobile 1.3.0 contract, unchanged) and the counter-offer answer, which
 * ProposalService::respondToCounterOffer performs through the proposal machine.
 */
final class MobileProposalService
{
    public function __construct(private PartyResolver $parties, private ProposalService $proposals) {}

    public function list(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->ownedQuery($user, $tenantId)->with(['offer.product', 'offer.carrier.party', 'offer.quote'])->orderByDesc('updated_at')->paginate($perPage);
    }

    /** @return array<string, mixed> */
    public function present(Proposal $p): array
    {
        $terms = $p->terms_snapshot ?? [];
        $counter = $p->status === 'COUNTEROFFERED' ? $this->proposals->counterTerms($p) : null;

        return [
            'id' => $p->id,
            'status' => $p->status,
            'line_code' => $p->offer?->quote?->line_code,
            'quote_id' => $p->offer?->quote_id,
            'product_name' => $p->offer?->product?->name,
            'carrier_name' => $p->offer?->carrier?->party?->display_name,
            'total_minor' => (int) ($terms['total_minor'] ?? $p->offer?->total_minor ?? 0),
            'currency' => $terms['currency'] ?? $p->offer?->currency ?? 'XAF',
            'counter_offer' => $counter,
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    public function respondToCounterOffer(string $proposalId, string $answer, User $user, string $tenantId): Proposal
    {
        return $this->proposals->respondToCounterOffer($this->owned($proposalId, $user, $tenantId), $answer === 'accept', $user, 'MOBILE');
    }

    public function owned(string $proposalId, User $user, string $tenantId): Proposal
    {
        $proposal = $this->ownedQuery($user, $tenantId)->find($proposalId);
        if (! $proposal) {
            // 404 for someone else's proposal, too: never confirm another customer's record exists.
            throw new ModelNotFoundException;
        }

        return $proposal;
    }

    private function ownedQuery(User $user, string $tenantId): Builder
    {
        $party = $this->parties->forUser($user);
        $q = Proposal::where('tenant_id', $tenantId);

        return $party ? $q->where('party_id', $party->id) : $q->whereRaw('1 = 0');
    }
}
