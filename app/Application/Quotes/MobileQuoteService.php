<?php

declare(strict_types=1);

namespace App\Application\Quotes;

use App\Application\Identity\PartyResolver;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

final class MobileQuoteService
{
    private const RESUMABLE_STATUSES = ['SUBMITTED', 'REFERRED', 'OFFERED'];

    public function __construct(
        private PartyResolver $parties,
        private QuoteService $quotes,
    ) {}

    public function list(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->ownedQuery($user, $tenantId)->orderByDesc('created_at')->paginate($perPage);
    }

    public function show(string $quoteId, User $user, string $tenantId): array
    {
        return $this->envelope($this->owned($quoteId, $user, $tenantId));
    }

    public function resume(string $quoteId, User $user, string $tenantId): array
    {
        $quote = $this->owned($quoteId, $user, $tenantId);

        if (! in_array($quote->status, self::RESUMABLE_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => __('wave12.quote_not_resumable')]);
        }

        if ($quote->expires_at && $quote->expires_at->isPast()) {
            throw ValidationException::withMessages(['status' => __('wave12.quote_expired')]);
        }

        return $this->envelope($quote);
    }

    public function cancel(string $quoteId, User $user, string $tenantId): Quote
    {
        return $this->quotes->cancel($this->owned($quoteId, $user, $tenantId), $user);
    }

    private function envelope(Quote $quote): array
    {
        return [
            'quote' => $quote,
            'offers' => $quote->offers()->with(['carrier.party', 'product'])->orderBy('comparison_rank')->get(),
        ];
    }

    private function owned(string $quoteId, User $user, string $tenantId): Quote
    {
        $quote = $this->ownedQuery($user, $tenantId)->find($quoteId);
        if (! $quote) {
            $exists = Quote::where('tenant_id', $tenantId)->where('id', $quoteId)->exists();
            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $quote;
    }

    private function ownedQuery(User $user, string $tenantId)
    {
        $party = $this->parties->forUser($user);
        $query = Quote::where('tenant_id', $tenantId);
        if (! $party) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('party_id', $party->id);
    }
}
