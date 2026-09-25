<?php

declare(strict_types=1);

namespace App\Application\Quotes;

use App\Application\Events\OutboxWriter;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\SavedComparison;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * REQ-DST-003 — quote comparison on normalized dimensions (limits, deductibles/excess, exclusions, premium, tax, fees,
 * validity), computed server-side from each offer's coverage snapshot so every client shows the same comparison.
 * Stored in saved_comparisons (the existing comparison store; quote_request_id = quote id) — no parallel table.
 */
final class QuoteComparisonService
{
    public const MIN = 2;

    public const MAX = 5;

    public function __construct(private readonly OutboxWriter $outbox) {}

    /** @param list<string> $offerIds  empty = every live offer of the quote */
    public function save(Quote $quote, array $offerIds, User $user): SavedComparison
    {
        $offers = $quote->offers()->with(['carrier.party', 'product'])->when($offerIds !== [], fn ($q) => $q->whereIn('id', $offerIds))
            ->whereIn('status', ['OFFERED', 'ACCEPTED'])->orderBy('comparison_rank')->get();
        if ($offerIds !== [] && $offers->count() !== count(array_unique($offerIds))) {
            throw ValidationException::withMessages(['offer_ids' => __('quotes.comparison_offers_invalid')]);
        }
        if ($offers->count() < self::MIN || $offers->count() > self::MAX) {
            throw ValidationException::withMessages(['offer_ids' => __('quotes.comparison_size', ['min' => self::MIN, 'max' => self::MAX])]);
        }
        $cmp = SavedComparison::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'quote_request_id' => $quote->id],
            ['tenant_id' => $quote->tenant_id, 'selected_offer_ids' => $offers->pluck('id')->values()->all(), 'expires_at' => $offers->min('valid_until') ?? $quote->expires_at ?? now()],
        );
        $cmp->forceFill(['dimensions' => json_encode($this->dimensions($quote, $offers))])->save();
        $this->outbox->record('quote.comparison.saved', 'quote', $quote->id, ['comparison_id' => $cmp->id, 'offer_count' => $offers->count()]);

        return $cmp->refresh();
    }

    /** Stored dimensions plus each offer's live status (an offer may since have expired or been accepted). */
    public function present(SavedComparison $cmp): array
    {
        $dims = is_array($cmp->dimensions) ? $cmp->dimensions : (array) json_decode((string) $cmp->dimensions, true);
        $live = QuoteOffer::whereIn('id', (array) $cmp->selected_offer_ids)->pluck('status', 'id');
        foreach ($dims['offers'] ?? [] as $i => $o) {
            $dims['offers'][$i]['current_status'] = $live[$o['offer_id']] ?? 'UNKNOWN';
        }

        return ['id' => $cmp->id, 'quote_id' => $cmp->quote_request_id, 'selected_offer_ids' => $cmp->selected_offer_ids,
            'expires_at' => $cmp->expires_at?->toIso8601String(), 'is_expired' => (bool) $cmp->expires_at?->isPast(), 'created_at' => $cmp->created_at?->toIso8601String()] + $dims;
    }

    /** @param Collection<int,QuoteOffer> $offers */
    public function dimensions(Quote $quote, Collection $offers): array
    {
        $coverageCodes = [];
        $exclusionCodes = [];
        $rows = [];
        foreach ($offers as $o) {
            $snap = (array) $o->coverage_snapshot;
            $cov = collect($snap['coverages'] ?? [])->keyBy('code');
            $exc = collect($snap['exclusions'] ?? [])->keyBy('code');
            foreach ($cov as $code => $c) {
                $coverageCodes[$code] ??= $c['name'] ?? $code;
            }
            foreach ($exc as $code => $e) {
                $exclusionCodes[$code] ??= $e['name'] ?? $code;
            }
            $rows[] = ['offer' => $o, 'cov' => $cov, 'exc' => $exc];
        }
        ksort($coverageCodes);
        ksort($exclusionCodes);
        $lowest = $offers->min('total_minor');

        return [
            'currency' => $quote->currency,
            'dimension_keys' => ['total_minor', 'premium_minor', 'tax_minor', 'fee_minor', 'coverages.limit_minor', 'coverages.deductible_minor', 'exclusions', 'valid_until'],
            'offers' => array_map(fn ($r) => [
                'offer_id' => $r['offer']->id, 'rank' => $r['offer']->comparison_rank, 'carrier' => $r['offer']->carrier?->party?->display_name, 'carrier_id' => $r['offer']->carrier_id,
                'product' => $r['offer']->product?->name, 'product_id' => $r['offer']->product_id,
                'premium_minor' => (int) $r['offer']->premium_minor, 'tax_minor' => (int) $r['offer']->tax_minor, 'fee_minor' => (int) $r['offer']->fee_minor, 'total_minor' => (int) $r['offer']->total_minor,
                'difference_to_lowest_minor' => (int) $r['offer']->total_minor - (int) $lowest, 'premium_overridden' => $r['offer']->original_premium_minor !== null,
                'valid_until' => $r['offer']->valid_until?->toIso8601String(), 'coverage_count' => $r['cov']->count(), 'exclusion_count' => $r['exc']->count(),
            ], $rows),
            'coverages' => array_map(fn ($code, $name) => ['code' => $code, 'name' => $name, 'by_offer' => array_map(fn ($r) => [
                'offer_id' => $r['offer']->id, 'included' => $r['cov']->has($code), 'optional' => (bool) ($r['cov'][$code]['optional'] ?? false),
                'limit_minor' => $r['cov'][$code]['limit_minor'] ?? null, 'deductible_minor' => $r['cov'][$code]['deductible_minor'] ?? null,
            ], $rows)], array_keys($coverageCodes), $coverageCodes),
            'exclusions' => array_map(fn ($code, $name) => ['code' => $code, 'name' => $name, 'by_offer' => array_map(fn ($r) => ['offer_id' => $r['offer']->id, 'applies' => $r['exc']->has($code)], $rows)],
                array_keys($exclusionCodes), $exclusionCodes),
            'lowest_total_offer_id' => $offers->firstWhere('total_minor', $lowest)?->id,
        ];
    }
}
