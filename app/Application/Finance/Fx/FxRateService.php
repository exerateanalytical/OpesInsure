<?php

declare(strict_types=1);

namespace App\Application\Finance\Fx;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-PAY-013 multi-currency & immutable FX rates (ICE gap 34).
 *
 * - fx_rates is append-only (DB trigger); a correction is a new row, never an edit.
 * - rateAsOf(): latest effective_at <= as-of; tenant rate wins over a platform rate of the same pair.
 * - Resolution order: DIRECT pair, INVERSE pair, CROSS through EUR (EUR peg: XAF/XOF = 655.957 per EUR).
 * - convert(): exact numeric math in Postgres, half-up rounding to the target minor unit; stores the
 *   fx_rate_id(s) used in fx_conversions (append-only) so a historical amount can always be re-derived.
 */
final class FxRateService
{
    public const BASE_CURRENCIES = ['XAF', 'XOF'];

    public const PEG_ANCHOR = 'EUR';

    public const SOURCES = ['EUR_PEG', 'BEAC', 'BCEAO', 'ECB', 'BANK', 'MANUAL'];

    /** ISO 4217 minor-unit exponent; unlisted currencies default to 2. */
    private const EXPONENTS = ['XAF' => 0, 'XOF' => 0, 'JPY' => 0, 'KMF' => 0, 'GNF' => 0, 'RWF' => 0, 'BIF' => 0, 'DJF' => 0];

    public function __construct(private AuditWriter $audit, private OutboxWriter $outbox) {}

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    /** True when both currencies are in the EUR/CFA peg zone (conversion is fixed parity). */
    public static function pegged(string $a, string $b): bool
    {
        $zone = [...self::BASE_CURRENCIES, self::PEG_ANCHOR];

        return in_array(strtoupper($a), $zone, true) && in_array(strtoupper($b), $zone, true);
    }

    /** @param array{base_currency:string, quote_currency:string, rate:string|float|int, source:string, effective_at:string, source_reference?:?string} $d */
    public function record(?string $tenantId, array $d, ?User $actor): object
    {
        $base = strtoupper($d['base_currency']);
        $quote = strtoupper($d['quote_currency']);
        if ($base === $quote) {
            throw FinanceProblem::make('FX_SAME_CURRENCY', 422, 'Base and quote currency must differ.');
        }
        if (! in_array($d['source'], self::SOURCES, true)) {
            throw FinanceProblem::make('FX_UNKNOWN_SOURCE', 422, "Unknown FX source {$d['source']}.");
        }
        if (! is_numeric((string) $d['rate']) || (float) $d['rate'] <= 0) {
            throw FinanceProblem::make('FX_INVALID_RATE', 422, 'Rate must be a positive number.');
        }
        // Peg awareness: EUR<->XAF/XOF and XAF<->XOF are fixed parity; a market rate contradicting the peg is refused.
        if (self::pegged($base, $quote) && $d['source'] !== 'EUR_PEG') {
            throw FinanceProblem::make('FX_PEGGED_PAIR', 422, "{$base}/{$quote} is a fixed EUR peg parity and cannot take a market rate.");
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenantId, $d, $base, $quote, $actor) {
            DB::table('fx_rates')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'base_currency' => $base, 'quote_currency' => $quote, 'rate' => (string) $d['rate'],
                'source' => $d['source'], 'source_reference' => $d['source_reference'] ?? null, 'effective_at' => CarbonImmutable::parse($d['effective_at']),
                'recorded_by' => $actor?->id, 'recorded_at' => now(),
            ]);
            $payload = ['base_currency' => $base, 'quote_currency' => $quote, 'rate' => (string) $d['rate'], 'source' => $d['source'], 'effective_at' => $d['effective_at']];
            $this->audit->record('fx.rate.recorded', 'fx_rate', $id, $payload);
            $this->outbox->record('fx.rate.recorded', 'fx_rate', $id, $payload);
        });

        return DB::table('fx_rates')->find($id);
    }

    /** Latest rate for the exact pair effective at or before $asOf (tenant rate preferred), or null. */
    public function rateAsOf(?string $tenantId, string $base, string $quote, DateTimeInterface|string|null $asOf = null): ?object
    {
        $at = $asOf === null ? now() : CarbonImmutable::parse($asOf);

        return DB::table('fx_rates')
            ->where('base_currency', strtoupper($base))->where('quote_currency', strtoupper($quote))
            ->where('effective_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q) => $q->orWhere('tenant_id', $tenantId)))
            ->orderByRaw('tenant_id IS NULL')->orderByDesc('effective_at')->orderByDesc('recorded_at')
            ->first();
    }

    /**
     * Resolve how to go from $from to $to as of a time.
     *
     * @return array{method:string, legs:list<array{rate:object, inverse:bool}>}
     */
    public function resolve(?string $tenantId, string $from, string $to, DateTimeInterface|string|null $asOf = null): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($leg = $this->leg($tenantId, $from, $to, $asOf)) {
            return ['method' => $leg['inverse'] ? 'INVERSE' : 'DIRECT', 'legs' => [$leg]];
        }
        if ($from !== self::PEG_ANCHOR && $to !== self::PEG_ANCHOR) {
            $a = $this->leg($tenantId, $from, self::PEG_ANCHOR, $asOf);
            $b = $this->leg($tenantId, self::PEG_ANCHOR, $to, $asOf);
            if ($a && $b) {
                return ['method' => 'CROSS', 'legs' => [$a, $b]];
            }
        }
        throw FinanceProblem::make('FX_RATE_MISSING', 422, "No FX rate {$from}/{$to} effective at the requested time.");
    }

    /**
     * Convert and persist the conversion with the rate id(s) used.
     *
     * @return object fx_conversions row
     */
    public function convert(string $tenantId, int $amountMinor, string $from, string $to, DateTimeInterface|string|null $asOf = null, ?string $subjectType = null, ?string $subjectId = null): object
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            throw FinanceProblem::make('FX_SAME_CURRENCY', 422, 'No conversion needed between identical currencies.');
        }
        $at = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);
        $plan = $this->resolve($tenantId, $from, $to, $at);

        // Build the applied rate expression and compute exactly in numeric.
        $expr = '1::numeric';
        $bindings = [];
        foreach ($plan['legs'] as $leg) {
            $expr = $leg['inverse'] ? "({$expr} / ?::numeric)" : "({$expr} * ?::numeric)";
            $bindings[] = (string) $leg['rate']->rate;
        }
        $shift = self::exponent($to) - self::exponent($from);
        $row = DB::selectOne(
            "SELECT round({$expr}, 15) AS applied, round(?::numeric * {$expr} * power(10::numeric, ?::int)) AS converted",
            [...$bindings, $amountMinor, ...$bindings, $shift]
        );

        $id = (string) Str::uuid();
        DB::table('fx_conversions')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'fx_rate_id' => $plan['legs'][0]['rate']->id, 'second_fx_rate_id' => $plan['legs'][1]['rate']->id ?? null,
            'method' => $plan['method'], 'from_currency' => $from, 'to_currency' => $to, 'from_amount_minor' => $amountMinor,
            'to_amount_minor' => (int) $row->converted, 'applied_rate' => $row->applied, 'as_of' => $at,
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'created_at' => now(),
        ]);

        return DB::table('fx_conversions')->find($id);
    }

    /** @return array{rate:object, inverse:bool}|null */
    private function leg(?string $tenantId, string $from, string $to, DateTimeInterface|string|null $asOf): ?array
    {
        if ($r = $this->rateAsOf($tenantId, $from, $to, $asOf)) {
            return ['rate' => $r, 'inverse' => false];
        }
        if ($r = $this->rateAsOf($tenantId, $to, $from, $asOf)) {
            return ['rate' => $r, 'inverse' => true];
        }

        return null;
    }
}
