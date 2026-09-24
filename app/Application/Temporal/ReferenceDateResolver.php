<?php

declare(strict_types=1);

namespace App\Application\Temporal;

use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\Clock\SystemClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * REQ-TMP-001 — ICE E1 §1.2 / INV-1.2: each operation has exactly one
 * reference-date rule per artifact (or artifact category). The rule in force
 * is the EFFECTIVE, non-superseded reference_date_rules row valid at clock now.
 */
final class ReferenceDateResolver
{
    /** anchor => subject attributes tried in order. REQUEST_AT is the clock. */
    private const ANCHOR_ATTRIBUTES = [
        'REQUESTED_AT' => ['requested_at', 'created_at'],
        'OFFER_LOCK' => ['locked_at', 'offer_locked_at', 'created_at'],
        'INCEPTION' => ['coverage_starts_at', 'inception_at', 'starts_at'],
        'EFFECTIVE_AT' => ['effective_at'],
        'LOSS_OCCURRED_AT' => ['loss_occurred_at', 'occurred_at'],
        'DECISION_AT' => ['decided_at', 'decision_at'],
        'VALUE_DATE' => ['value_date', 'value_at'],
        'PERIOD_END' => ['period_end', 'period_ends_at'],
    ];

    private readonly Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock((string) config('app.timezone', 'Africa/Douala'));
    }

    /** The rule row (operation + specific artifact, else its category). */
    public function rule(string $operation, string $artifactType, ?string $category = null): object
    {
        $now = $this->clock->now()->toIso8601String();
        $candidates = array_values(array_unique(array_filter([$artifactType, $category])));
        foreach ($candidates as $candidate) {
            $rows = DB::table('reference_date_rules')
                ->where('operation', $operation)->where('artifact_type', $candidate)
                ->where('status', 'EFFECTIVE')->whereNull('superseded_at')
                ->where('valid_from', '<=', $now)
                ->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>', $now))
                ->limit(2)->get();
            if ($rows->count() > 1) {
                throw new TemporalResolutionException(TemporalResolutionException::AMBIGUOUS, 'reference_date_rule', ['operation' => $operation, 'artifact_type' => $candidate]);
            }
            if ($rows->count() === 1) {
                return $rows->first();
            }
        }

        throw new TemporalResolutionException(TemporalResolutionException::NO_RULE, 'reference_date_rule', ['operation' => $operation, 'artifact_type' => $artifactType]);
    }

    /**
     * @param  object|array<string, mixed>  $subject  model, DTO or array carrying the anchor attribute
     */
    public function for(string $operation, object|array $subject, string $artifactType = 'TERMS', ?string $category = null, ?string $timezone = null): ReferenceInstant
    {
        $rule = $this->rule($operation, $artifactType, $category);
        $timezone ??= (string) (data_get($subject, 'timezone') ?: config('app.timezone', 'Africa/Douala'));

        if ($rule->anchor === 'REQUEST_AT') {
            return new ReferenceInstant($this->clock->now()->setTimezone($timezone), 'REQUEST_AT', (int) $rule->version, $timezone);
        }
        foreach (self::ANCHOR_ATTRIBUTES[$rule->anchor] ?? [] as $attribute) {
            $value = data_get($subject, $attribute);
            if ($value !== null && $value !== '') {
                return new ReferenceInstant(BusinessTime::parse($value, $timezone), $rule->anchor, (int) $rule->version, $timezone);
            }
        }

        throw new TemporalResolutionException(TemporalResolutionException::NO_ANCHOR, $artifactType, ['operation' => $operation, 'anchor' => $rule->anchor]);
    }
}
