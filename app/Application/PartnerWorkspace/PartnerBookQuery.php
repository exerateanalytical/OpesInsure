<?php

declare(strict_types=1);

namespace App\Application\PartnerWorkspace;

use App\Application\Documents\Engine\{DocumentAccessPolicy, DocumentEngine, DocumentRegister};
use App\Application\Mobile\ListCursor;
use App\Models\{Claim, Document, Policy, Proposal, TenantCustomer};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Read models for the partner workspace book pages (agent and broker), shared so both controllers
 * scope identically. The book is always PartnerWorkspaceScope::bookPartyIds() (BookScope: company / team / own) — parties with an
 * ACTIVE customer_attribution to the caller's own partner — inside the current tenant.
 */
final class PartnerBookQuery
{
    public function __construct(private readonly DocumentRegister $register) {}

    /**
     * Phase-1 fix S: $page pages the list (?cursor=, meta.next_cursor); without it the first 100 rows, as before.
     *
     * @param  list<string>  $book
     * @return Collection<int, Proposal>
     */
    public function proposals(string $tenantId, array $book, ?ListCursor $page = null): Collection
    {
        return $this->page(Proposal::with(['party', 'offer.carrier.party', 'offer.quote'])->where('tenant_id', $tenantId)->whereIn('party_id', $book)
            ->orderByDesc('created_at')->orderBy('id'), $page);
    }

    /** Claims on policies held by the book (same rule as GET /mobile/partner/broker/claims). @param list<string> $book @return Collection<int, Claim> */
    public function claims(string $tenantId, array $book, ?ListCursor $page = null): Collection
    {
        return $this->page($this->claimQuery($tenantId, $book)->orderByDesc('submitted_at')->orderBy('id'), $page);
    }

    /** One claim on a book policy, or 404. @param list<string> $book */
    public function bookClaim(string $tenantId, array $book, string $claimId): Claim
    {
        abort_unless(\Illuminate\Support\Str::isUuid($claimId), 404);

        return $this->claimQuery($tenantId, $book)->whereKey($claimId)->firstOrFail();
    }

    /** @param list<string> $book */
    private function claimQuery(string $tenantId, array $book)
    {
        return Claim::with(['policy.party', 'policy.carrier.party', 'claimant'])->where('tenant_id', $tenantId)->whereHas('policy', fn ($p) => $p->whereIn('party_id', $book));
    }

    private function page($query, ?ListCursor $page): Collection
    {
        return $page ? $page->slice($page->apply($query)->get()) : $query->limit(100)->get();
    }

    /** The book client, or 404 (another partner's client and an unknown id look the same). @param list<string> $book */
    public function client(string $tenantId, array $book, string $customerId): TenantCustomer
    {
        $c = TenantCustomer::with('party')->where('tenant_id', $tenantId)->whereKey($customerId)->first();
        abort_unless($c !== null && in_array($c->party_id, $book, true), 404);

        return $c;
    }

    /**
     * Issued documents on the client's policies that an intermediary may see (DocumentAccessPolicy::intermediaryMay),
     * with a short-lived signed download link (same signed route the customer app uses).
     *
     * @return list<array<string, mixed>>
     */
    public function clientDocuments(TenantCustomer $client): array
    {
        $policies = Policy::where('tenant_id', $client->tenant_id)->where('party_id', $client->party_id)->pluck('policy_number', 'id');
        $ttl = (int) config('lifecycle.download_ttl_minutes', 30);

        return Document::whereIn('policy_id', $policies->keys()->all())->whereIn('document_origin', DocumentRegister::ISSUED_ORIGINS)->whereNotNull('document_type_code')
            ->orderByDesc('created_at')->limit(200)->get()
            ->filter(fn (Document $d) => DocumentAccessPolicy::intermediaryMay($d))
            ->map(function (Document $d) use ($policies, $ttl) {
                $t = $this->register->describe((string) $d->document_type_code);
                $status = DocumentEngine::effectiveStatus($d);
                $current = in_array($status, DocumentRegister::CURRENT_STATUSES, true);

                return [
                    'id' => $d->id, 'title' => $t['name_en'], 'title_fr' => $t['name_fr'], 'group' => $t['display_group'], 'document_number' => $d->document_number,
                    'status' => $status, 'is_current' => $current, 'policy_id' => $d->policy_id, 'policy_number' => $policies[$d->policy_id] ?? null,
                    'issued_at' => ($d->issued_at ?? $d->created_at)?->toIso8601String(),
                    'download_url' => $current ? URL::temporarySignedRoute('mobile.policy-documents.download', now()->addMinutes($ttl), ['document' => $d->id]) : null,
                ];
            })->values()->all();
    }
}
