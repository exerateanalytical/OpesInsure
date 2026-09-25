<?php

declare(strict_types=1);

namespace App\Application\Policies\Endorsements;

use App\Application\Rating\RatingService;
use App\Application\Temporal\ReferenceInstant;
use App\Models\Policy;
use App\Models\TariffVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * REQ-END-001 rerate (WF-036): prices the risk facts before and after the endorsement against the tariff
 * version the policy was sold on (so only the changed facts move the price), then pro-rates the annual
 * difference over the remaining term. Positive = additional premium, negative = refund.
 * Returns null when the policy has no rateable facts/tariff on record (caller falls back to a manual delta).
 */
final class EndorsementRerater
{
    public function __construct(private readonly RatingService $rating) {}

    /** @return array{premium_delta_minor: int, basis: array}|null */
    public function rerate(Policy $policy, array $termsBefore, array $termsAfter, CarbonImmutable $effectiveAt): ?array
    {
        $proposal = $policy->proposal_id ? DB::table('proposals')->where('id', $policy->proposal_id)->first() : null;
        $offer = $proposal?->quote_offer_id ? DB::table('quote_offers')->where('id', $proposal->quote_offer_id)->first() : null;
        $quote = $offer ? DB::table('quotes')->where('id', $offer->quote_id)->first() : null;
        $tariff = $offer?->tariff_version_id ? TariffVersion::find($offer->tariff_version_id) : null;
        $baseFacts = $quote ? (json_decode((string) $quote->risk_facts, true) ?: []) : [];
        $factsBefore = array_replace_recursive($baseFacts, (array) ($termsBefore['risk_facts'] ?? []));
        $factsAfter = array_replace_recursive($factsBefore, (array) ($termsAfter['risk_facts'] ?? []));
        if (! $tariff || ! $quote || $factsBefore === []) {
            return null;
        }

        $at = ReferenceInstant::at($policy->issued_at ?? $policy->coverage_starts_at ?? now());
        try {
            $before = $this->rating->price($tariff, $factsBefore, $quote->line_code, $policy->tenant_id, $at)['pricing'];
            $after = $this->rating->price($tariff, $factsAfter, $quote->line_code, $policy->tenant_id, $at)['pricing'];
        } catch (\Throwable) {
            return null;
        }

        $start = CarbonImmutable::instance($policy->coverage_starts_at);
        $end = CarbonImmutable::instance($policy->coverage_ends_at);
        $termDays = max(1, (int) $start->diffInDays($end));
        $remainingDays = max(0, min($termDays, (int) $effectiveAt->startOfDay()->diffInDays($end)));
        $annualDelta = $after->totalMinor - $before->totalMinor;
        $delta = self::proRata($annualDelta, $remainingDays, $termDays);

        return ['premium_delta_minor' => $delta, 'basis' => [
            'method' => 'RERATE_PRO_RATA', 'tariff_version_id' => $tariff->id, 'reference_date' => $at->businessDate(),
            'total_before_minor' => $before->totalMinor, 'total_after_minor' => $after->totalMinor,
            'annual_delta_minor' => $annualDelta, 'remaining_days' => $remainingDays, 'term_days' => $termDays,
        ]];
    }

    /** Integer pro-rata, half away from zero. */
    public static function proRata(int $amount, int $numerator, int $denominator): int
    {
        $sign = $amount < 0 ? -1 : 1;
        $abs = abs($amount) * $numerator;

        return $sign * intdiv($abs + intdiv($denominator, 2), $denominator);
    }
}
