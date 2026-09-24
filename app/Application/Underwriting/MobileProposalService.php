<?php

declare(strict_types=1);

namespace App\Application\Underwriting;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Identity\PartyResolver;
use App\Models\Proposal;
use App\Models\UnderwritingDecision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing proposals: the caller's own list, and the customer's
 * answer to an underwriter counter-offer (UnderwritingService::decide with
 * decision COUNTEROFFERED). The counter terms are the decision's
 * `conditions`: revised_total_minor (or total_minor) and optionally
 * revised_premium_minor / revised_tax_minor / revised_fee_minor. Accepting
 * applies them to terms_snapshot and moves the proposal to PAYMENT_PENDING;
 * declining withdraws it (WITHDRAWN — the customer walked away; DECLINED is
 * reserved for the insurer's decision).
 */
final class MobileProposalService
{
    public function __construct(private PartyResolver $parties, private AuditWriter $audit, private OutboxWriter $outbox) {}

    public function list(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->ownedQuery($user, $tenantId)->with(['offer.product', 'offer.carrier.party', 'offer.quote'])->orderByDesc('updated_at')->paginate($perPage);
    }

    /** @return array<string, mixed> */
    public function present(Proposal $p): array
    {
        $terms = $p->terms_snapshot ?? [];
        $counter = $p->status === 'COUNTEROFFERED' ? $this->counterTerms($p) : null;

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
        return DB::transaction(function () use ($proposalId, $answer, $user, $tenantId): Proposal {
            $proposal = $this->owned($proposalId, $user, $tenantId);
            $proposal = Proposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($proposal->status !== 'COUNTEROFFERED') {
                throw ValidationException::withMessages(['status' => 'This proposal has no open counter-offer.']);
            }

            $from = $proposal->status;
            if ($answer === 'accept') {
                $counter = $this->counterTerms($proposal);
                $terms = $proposal->terms_snapshot ?? [];
                foreach (['premium_minor', 'tax_minor', 'fee_minor', 'total_minor'] as $k) {
                    if (isset($counter[$k])) {
                        $terms[$k] = (int) $counter[$k];
                    }
                }
                $terms['counter_offer_decision_id'] = $counter['decision_id'] ?? null;
                $to = 'PAYMENT_PENDING';
                $proposal->update(['status' => $to, 'terms_snapshot' => $terms, 'version' => $proposal->version + 1]);
                $reason = 'COUNTEROFFER_ACCEPTED';
            } else {
                $to = 'WITHDRAWN';
                $proposal->update(['status' => $to, 'version' => $proposal->version + 1]);
                $reason = 'COUNTEROFFER_DECLINED';
            }

            DB::table('proposal_status_history')->insert([
                'id' => (string) Str::uuid(), 'proposal_id' => $proposal->id, 'from_status' => $from, 'to_status' => $to,
                'reason_code' => $reason, 'actor_id' => $user->id, 'metadata' => json_encode(['channel' => 'MOBILE']), 'occurred_at' => now(),
            ]);
            $this->audit->record('proposal.counteroffer.'.($answer === 'accept' ? 'accepted' : 'declined'), 'proposal', $proposal->id, [], $reason);
            $this->outbox->record('proposal.counteroffer.'.($answer === 'accept' ? 'accepted' : 'declined'), 'proposal', $proposal->id, ['proposal_id' => $proposal->id, 'status' => $to]);

            return $proposal->refresh();
        });
    }

    /** @return array<string, int|string|null>|null */
    private function counterTerms(Proposal $proposal): ?array
    {
        $decision = UnderwritingDecision::whereHas('underwritingCase', fn ($q) => $q->where('proposal_id', $proposal->id))
            ->where('decision', 'COUNTEROFFERED')->latest('decided_at')->first();
        if (! $decision) {
            return null;
        }
        $c = $decision->conditions ?? [];
        $pick = fn (string $k) => isset($c["revised_{$k}"]) ? (int) $c["revised_{$k}"] : (isset($c[$k]) ? (int) $c[$k] : null);

        return [
            'decision_id' => $decision->id,
            'premium_minor' => $pick('premium_minor'),
            'tax_minor' => $pick('tax_minor'),
            'fee_minor' => $pick('fee_minor'),
            'total_minor' => $pick('total_minor'),
            'notes' => $decision->notes,
            'decided_at' => $decision->decided_at?->toIso8601String(),
        ];
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
