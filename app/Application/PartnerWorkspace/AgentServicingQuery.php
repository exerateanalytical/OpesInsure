<?php

declare(strict_types=1);

namespace App\Application\PartnerWorkspace;

use App\Application\Documents\SubjectDocuments;
use App\Application\Identity\Rbac\RequestMemo;
use App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\PartnerWorkspaceShapes;
use App\Models\{Claim, PaymentIntentRecord, Policy, RiskAsset, StickerStock, TenantCustomer, User};
use Illuminate\Support\Facades\DB;

/**
 * Read models for the agent servicing screens (web /account agent pages AGT-037..056): one policy, its service
 * requests, cancellations and sticker; payments, vehicles and claims of the book. Every lookup is bounded by the
 * book (PartnerWorkspaceScope::bookPartyIds) inside the tenant — another partner's record and an unknown id are
 * the same 404.
 */
final class AgentServicingQuery
{
    public function __construct(private readonly SubjectDocuments $subjects) {}

    /** @param list<string> $book */
    public function policy(string $tenantId, array $book, string $policyId): Policy
    {
        $p = Policy::with(['party', 'carrier.party'])->where('tenant_id', $tenantId)->whereKey($policyId)->first();
        abort_unless($p !== null && in_array($p->party_id, $book, true), 404);

        return $p;
    }

    /** @param list<string> $book */
    public function claim(string $tenantId, array $book, string $claimId): Claim
    {
        $c = Claim::with(['policy.party', 'policy.carrier.party', 'claimant'])->where('tenant_id', $tenantId)->whereKey($claimId)->first();
        abort_unless($c !== null && in_array($c->policy?->party_id, $book, true), 404);

        return $c;
    }

    /** @param list<string> $book */
    public function asset(string $tenantId, array $book, string $assetId): RiskAsset
    {
        $a = RiskAsset::where('tenant_id', $tenantId)->whereKey($assetId)->first();
        abort_unless($a !== null && in_array($a->party_id, $book, true), 404);

        return $a;
    }

    public function customerId(string $tenantId, ?string $partyId): ?string
    {
        return $partyId ? RequestMemo::remember('tenant-customer:'.$tenantId.':'.$partyId, fn () => TenantCustomer::where(['tenant_id' => $tenantId, 'party_id' => $partyId])->value('id')) : null;
    }

    /** R4: one query for the customer ids a list's rows will ask customerId() for. @param iterable<?string> $partyIds */
    private function primeCustomerIds(string $tenantId, iterable $partyIds): void
    {
        $ids = array_values(array_unique(array_filter(is_array($partyIds) ? $partyIds : iterator_to_array($partyIds))));
        if ($ids === []) {
            return;
        }
        $found = TenantCustomer::where('tenant_id', $tenantId)->whereIn('party_id', $ids)->pluck('id', 'party_id')->all();
        RequestMemo::prime(array_combine(array_map(fn ($p) => 'tenant-customer:'.$tenantId.':'.$p, $ids), array_map(fn ($p) => $found[$p] ?? null, $ids)));
    }

    /** Policy detail: list shape + client, service requests / endorsements, cancellations, sticker. */
    public function policyDetail(Policy $p): array
    {
        return PartnerWorkspaceShapes::policy($p) + [
            'party_id' => $p->party_id, 'customer_id' => $this->customerId($p->tenant_id, $p->party_id),
            'currency' => $p->currency, 'terms' => array_intersect_key((array) $p->terms_snapshot, array_flip(['line_code', 'product_name', 'coverage', 'insured_value_minor', 'territory'])),
            'transactions' => $this->transactions($p->tenant_id, [$p->id]),
            'cancellations' => DB::table('policy_cancellations')->where('policy_id', $p->id)->orderByDesc('created_at')->limit(20)
                ->get(['id', 'status', 'initiated_by', 'reason_code', 'effective_at', 'refund_minor', 'currency', 'created_at'])->map(fn ($c) => (array) $c)->all(),
            'sticker' => optional(StickerStock::where('assigned_policy_id', $p->id)->first())->only(['serial_number', 'status', 'assigned_at']),
        ];
    }

    /** Service requests and servicing transactions (endorsement, cancellation, change) on the given policies, newest first. */
    public function transactions(string $tenantId, array $policyIds, bool $timeline = false): array
    {
        $numbers = Policy::whereIn('id', $policyIds)->pluck('policy_number', 'id');

        return DB::table('policy_transactions')->where('tenant_id', $tenantId)->whereIn('policy_id', $policyIds)->orderByDesc('created_at')->limit(100)->get()
            ->map(fn ($t) => $this->transaction($t, $numbers[$t->policy_id] ?? null, $timeline))->values()->all();
    }

    /** @param list<string> $book */
    public function transactionFor(string $tenantId, array $book, string $id): array
    {
        $t = DB::table('policy_transactions')->where('tenant_id', $tenantId)->where('id', $id)->first();
        $p = $t ? Policy::where('tenant_id', $tenantId)->whereKey($t->policy_id)->first() : null;
        abort_unless($p !== null && in_array($p->party_id, $book, true), 404);

        return $this->transaction($t, $p->policy_number, true) + ['customer_name' => $p->party?->display_name, 'customer_id' => $this->customerId($tenantId, $p->party_id)];
    }

    /** @param list<string> $book */
    public function bookPolicyIds(string $tenantId, array $book): array
    {
        return Policy::where('tenant_id', $tenantId)->whereIn('party_id', $book)->pluck('id')->all();
    }

    private function transaction(object $t, ?string $policyNumber, bool $timeline): array
    {
        $changes = json_decode($t->requested_changes ?? '{}', true) ?: [];

        return [
            'id' => $t->id, 'transaction_number' => $t->transaction_number, 'policy_id' => $t->policy_id, 'policy_number' => $policyNumber,
            'type' => $t->type, 'status' => $t->status, 'reason' => $changes['reason'] ?? $t->notes ?? null, 'channel' => $t->channel ?? null,
            'premium_delta_minor' => $t->premium_delta_minor !== null ? (int) $t->premium_delta_minor : null, 'refund_minor' => isset($t->refund_minor) ? (int) $t->refund_minor : null,
            'currency' => $t->currency, 'effective_at' => $t->effective_at ? \Carbon\Carbon::parse($t->effective_at)->toIso8601String() : null,
            'created_at' => \Carbon\Carbon::parse($t->created_at)->toIso8601String(), 'updated_at' => \Carbon\Carbon::parse($t->updated_at)->toIso8601String(),
            'timeline' => $timeline ? DB::table('policy_transaction_events')->where('policy_transaction_id', $t->id)->orderBy('occurred_at')->get()->map(fn ($e) => [
                'from_status' => $e->from_status, 'to_status' => $e->to_status, 'reason_code' => $e->reason_code,
                'message' => json_decode($e->metadata ?? '{}', true)['message'] ?? null, 'occurred_at' => \Carbon\Carbon::parse($e->occurred_at)->toIso8601String(),
            ])->values()->all() : [],
        ];
    }

    /** Payment intents of the book (or one client of it), newest first. @param list<string> $parties */
    public function payments(string $tenantId, array $parties): array
    {
        $rows = PaymentIntentRecord::with('proposal.party')->where('tenant_id', $tenantId)->whereHas('proposal', fn ($q) => $q->whereIn('party_id', $parties))
            ->orderByDesc('created_at')->limit(200)->get();
        $this->primeCustomerIds($tenantId, $rows->map(fn ($p) => $p->proposal?->party_id)->all());

        return $rows->map(fn (PaymentIntentRecord $p) => [
                'id' => $p->id, 'proposal_id' => $p->proposal_id, 'proposal_number' => $p->proposal?->proposal_number, 'customer_name' => $p->proposal?->party?->display_name,
                'customer_id' => $this->customerId($tenantId, $p->proposal?->party_id), 'provider' => $p->provider, 'amount_minor' => (int) $p->amount_minor, 'currency' => $p->currency,
                'status' => $p->status, 'request_channel' => $p->request_channel, 'created_at' => $p->created_at?->toIso8601String(), 'expires_at' => $p->expires_at?->toIso8601String(),
            ])->values()->all();
    }

    public function vehicle(RiskAsset $a): array
    {
        return [
            'id' => $a->id, 'party_id' => $a->party_id, 'customer_id' => $this->customerId($a->tenant_id, $a->party_id), 'type' => $a->type, 'display_name' => $a->display_name,
            'external_reference' => $a->external_reference, 'facts' => $a->facts, 'status' => $a->status, 'version' => $a->version, 'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /** Vehicles (and other insured assets) of one book client. */
    public function vehicles(string $tenantId, string $partyId): array
    {
        return RiskAsset::where('tenant_id', $tenantId)->where('party_id', $partyId)->orderByDesc('created_at')->limit(100)->get()->map(fn (RiskAsset $a) => $this->vehicle($a))->values()->all();
    }

    /** Claim detail: list shape + policy/customer ids, status timeline and the evidence linked to the claim. */
    public function claimDetail(Claim $c): array
    {
        return PartnerWorkspaceShapes::claim($c) + [
            'customer_id' => $this->customerId($c->tenant_id, $c->policy?->party_id), 'line_code' => $c->policy?->terms_snapshot['line_code'] ?? null,
            'loss_location' => $c->loss_location ?? null, 'description' => is_array($c->loss_details ?? null) ? ($c->loss_details['description'] ?? null) : null,
            'timeline' => DB::table('claim_events')->where('claim_id', $c->id)->orderBy('occurred_at')->limit(100)->get(['type', 'from_status', 'to_status', 'occurred_at'])
                ->map(fn ($e) => ['type' => $e->type, 'from_status' => $e->from_status, 'to_status' => $e->to_status, 'occurred_at' => \Carbon\Carbon::parse($e->occurred_at)->toIso8601String()])->values()->all(),
            'evidence' => $this->subjects->forSubject('CLAIM', $c->id)->map(fn ($d) => [
                'id' => $d->id, 'role' => $d->role, 'status' => $d->status, 'category' => $d->category, 'mime_type' => $d->mime_type, 'scan_status' => $d->scan_status,
                'submitted_at' => $d->submitted_at ? \Carbon\Carbon::parse($d->submitted_at)->toIso8601String() : null, 'verified_at' => $d->verified_at ? \Carbon\Carbon::parse($d->verified_at)->toIso8601String() : null,
            ])->values()->all(),
            // S4 (additive): uploads still in the security check / quarantined, never downloadable.
            'pending_evidence' => app(\App\Application\Documents\Scanning\PendingDocuments::class)->forClaim($c->id),
        ];
    }

    /** Stickers the agent holds in stock, and handovers to or from the agent. */
    public function stickers(User $agent, string $tenantId): array
    {
        $stock = StickerStock::where(['custody_level' => 'AGENT', 'custodian_user_id' => $agent->id, 'custodian_tenant_id' => $tenantId])->orderBy('serial_number')->limit(500)->get();
        $carriers = DB::table('carriers')->join('parties', 'parties.id', '=', 'carriers.party_id')->whereIn('carriers.id', $stock->pluck('carrier_id')->unique()->all())->pluck('parties.display_name', 'carriers.id');
        $handovers = DB::table('sticker_handovers')->where(fn ($q) => $q->where('to_user_id', $agent->id)->orWhere('from_user_id', $agent->id))
            ->where(fn ($q) => $q->where('to_tenant_id', $tenantId)->orWhere('from_tenant_id', $tenantId))->orderByDesc('created_at')->limit(100)->get();

        return [
            'stock' => $stock->map(fn (StickerStock $s) => ['serial_number' => $s->serial_number, 'carrier_id' => $s->carrier_id, 'carrier_name' => $carriers[$s->carrier_id] ?? null, 'batch_number' => $s->batch_number, 'status' => $s->status])->values()->all(),
            'handovers' => $handovers->map(fn ($h) => [
                'id' => $h->id, 'direction' => $h->direction, 'status' => $h->status, 'quantity' => (int) $h->quantity, 'from_level' => $h->from_level, 'to_level' => $h->to_level,
                'incoming' => $h->to_user_id === $agent->id, 'can_decide' => $h->status === 'PENDING' && $h->to_user_id === $agent->id && $h->initiated_by !== $agent->id,
                'notes' => $h->notes, 'decision_reason' => $h->decision_reason, 'created_at' => \Carbon\Carbon::parse($h->created_at)->toIso8601String(),
                'decided_at' => $h->decided_at ? \Carbon\Carbon::parse($h->decided_at)->toIso8601String() : null,
            ])->values()->all(),
        ];
    }
}
