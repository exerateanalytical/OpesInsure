<?php

declare(strict_types=1);

namespace App\Domain\Rating;

use DomainException;

/**
 * REQ-RAT-001 / REQ-RAT-003 / REQ-RAT-005 — rating v2 (PRE §26–33). Pure and deterministic:
 * base (13 methods) + selected coverages + loadings − capped discounts, minimum premium, rounding,
 * then fees and taxes/levies from versioned charge tables, then per-CIMA-branch allocation.
 * All money is integer minor units (IntegerMoney). rate() keeps the v1 contract (RatingResult).
 *
 * Tariff rules (all keys optional except a positive base):
 *   required_facts: [fact…]
 *   base_premium_minor: int                     (v1)  | base: {method…}  (v2, see amount())
 *   coverages: [{code, method…, optional?: bool}] — optional ones only when code ∈ facts.selected_coverages
 *   factors | loadings: [{code, type?, fact, operator EQUALS|IN|BETWEEN, value, basis_points | fixed_minor}]  (compounding, v1 order)
 *   discounts: [{code, type, fact, operator, value, basis_points}]  (on the pre-discount premium; MANUAL_AUTHORIZED needs context.authorized_discounts)
 *   discount_cap_basis_points: int
 *   minimum_premium_minor: int
 *   rounding: {unit_minor: int, mode: HALF_UP|UP|DOWN}
 *   branch_allocation: [{branch_code, basis_points}]  (sums to 10000; owner rule OQ-24)
 *
 * Charge tables (tax_levy_versions / fee_schedule_versions rules):
 *   {charges: [{code, kind TAX|LEVY|STATUTORY|FEE, basis PREMIUM|PREMIUM_AND_FEES|FIXED, basis_points | fixed_minor, classes?: [line_code…]}]}
 *   v1 blobs {basis_points} and {fixed_minor} are read as one TAX / FEE charge.
 */
final class DeterministicRatingEngine
{
    public const ENGINE_VERSION = '2';

    public const METHODS = ['FIXED', 'RATE_X_SUM_INSURED', 'RATE_X_LIMIT', 'TABLE', 'BAND', 'TIER', 'FORMULA', 'PER_PERSON', 'PER_VEHICLE', 'PER_EMPLOYEE', 'PER_DAY', 'PER_TRIP', 'PER_SHIPMENT'];

    public const DISCOUNT_TYPES = ['NO_CLAIMS', 'FLEET', 'GROUP', 'LOYALTY', 'CAMPAIGN', 'CORPORATE', 'SECURITY', 'MULTI_POLICY', 'MANUAL_AUTHORIZED'];

    public const LOADING_TYPES = ['HIGH_RISK_USE', 'HIGH_CLAIMS', 'OLDER_ASSET', 'HAZARDOUS_ACTIVITY', 'GEOGRAPHIC_RISK'];

    private const PER_UNIT_FACT = ['PER_PERSON' => 'insured_count', 'PER_VEHICLE' => 'vehicle_count', 'PER_EMPLOYEE' => 'employee_count', 'PER_DAY' => 'duration_days', 'PER_TRIP' => 'trip_count', 'PER_SHIPMENT' => 'shipment_count'];

    /** v1 contract kept for existing callers and tests. */
    public function rate(array $facts, array $rules, array $taxRules = [], array $feeRules = []): RatingResult
    {
        $p = $this->price($facts, $rules, $taxRules === [] ? [] : [['rules' => $taxRules]], $feeRules === [] ? [] : [['rules' => $feeRules]]);
        $breakdown = array_values(array_map(fn ($l) => array_intersect_key($l, array_flip(['code', 'fact', 'operator', 'amount_minor'])),
            array_filter($p->lines, fn ($l) => in_array($l['kind'], ['BASE', 'LOADING'], true))));

        return new RatingResult($p->netPremiumMinor, $p->taxMinor, $p->feeMinor, $p->totalMinor,
            [...$breakdown, ['code' => 'TAX', 'amount_minor' => $p->taxMinor], ['code' => 'FEE', 'amount_minor' => $p->feeMinor]]);
    }

    /**
     * @param  list<array{rules: array, source_table?: string, source_id?: string, version?: int|null, data_status?: string}>  $taxTables
     * @param  list<array{rules: array, source_table?: string, source_id?: string, version?: int|null, data_status?: string}>  $feeTables
     * @param  array{line_code?: string, authorized_discounts?: list<string>}  $context
     */
    public function price(array $facts, array $rules, array $taxTables = [], array $feeTables = [], array $context = []): PricingResult
    {
        foreach (($rules['required_facts'] ?? []) as $key) {
            if (! array_key_exists($key, $facts)) {
                throw new DomainException("Missing required risk fact: {$key}");
            }
        }
        $lines = [];
        $warnings = [];

        $base = isset($rules['base']) ? $this->amount($rules['base'], $facts) : (int) ($rules['base_premium_minor'] ?? 0);
        if ($base <= 0) {
            throw new DomainException('Approved positive base premium is required.');
        }
        $lines[] = ['code' => 'BASE', 'kind' => 'BASE', 'method' => $rules['base']['method'] ?? 'FIXED', 'amount_minor' => $base];

        $coverages = 0;
        $selected = (array) ($facts['selected_coverages'] ?? []);
        foreach ($rules['coverages'] ?? [] as $c) {
            if (($c['optional'] ?? false) && ! in_array($c['code'], $selected, true)) {
                continue;
            }
            $amt = $this->amount($c, $facts);
            if ($amt < 0) {
                throw new DomainException("Coverage {$c['code']} premium cannot be negative.");
            }
            $coverages += $amt;
            $lines[] = ['code' => $c['code'], 'kind' => 'COVERAGE', 'method' => $c['method'] ?? 'FIXED', 'optional' => (bool) ($c['optional'] ?? false), 'amount_minor' => $amt];
        }

        $premium = $base + $coverages;
        $loadings = 0;
        foreach ([...($rules['factors'] ?? []), ...($rules['loadings'] ?? [])] as $f) {
            [$matches, $operator] = $this->matches($f, $facts);
            if (! $matches) {
                continue;
            }
            if (isset($f['fixed_minor'])) {
                $delta = (int) $f['fixed_minor'];
            } else {
                $points = (int) $f['basis_points'];
                if ($points < -10000 || $points > 100000) {
                    throw new DomainException('Rating factor is outside the permitted range.');
                }
                $delta = IntegerMoney::bp($premium, $points);
            }
            $premium += $delta;
            $loadings += $delta;
            $lines[] = array_filter(['code' => $f['code'], 'kind' => 'LOADING', 'type' => $f['type'] ?? null, 'fact' => $f['fact'], 'operator' => $operator, 'amount_minor' => $delta], fn ($v) => $v !== null);
        }

        $beforeDiscount = $premium;
        $discounts = 0;
        $authorized = (array) ($context['authorized_discounts'] ?? []);
        foreach ($rules['discounts'] ?? [] as $d) {
            if (($d['type'] ?? null) === 'MANUAL_AUTHORIZED' && ! in_array($d['code'], $authorized, true)) {
                continue;
            }
            if (isset($d['fact']) && ! $this->matches($d, $facts)[0]) {
                continue;
            }
            $points = (int) ($d['basis_points'] ?? 0);
            if ($points < 0 || $points > 10000) {
                throw new DomainException('Discount is outside the permitted range.');
            }
            $amt = IntegerMoney::bp($beforeDiscount, $points);
            $discounts += $amt;
            $lines[] = ['code' => $d['code'], 'kind' => 'DISCOUNT', 'type' => $d['type'] ?? null, 'amount_minor' => -$amt];
        }
        if (isset($rules['discount_cap_basis_points'])) {
            $cap = IntegerMoney::bp($beforeDiscount, (int) $rules['discount_cap_basis_points']);
            if ($discounts > $cap) {
                $lines[] = ['code' => 'DISCOUNT_CAP', 'kind' => 'DISCOUNT', 'amount_minor' => $discounts - $cap];
                $warnings[] = 'rating.discount_capped';
                $discounts = $cap;
            }
        }
        $premium = $beforeDiscount - $discounts;

        $adjustments = 0;
        $minimum = (int) ($rules['minimum_premium_minor'] ?? 0);
        if ($minimum > 0 && $premium < $minimum) {
            $adjustments += $minimum - $premium;
            $lines[] = ['code' => 'MINIMUM_PREMIUM', 'kind' => 'ADJUSTMENT', 'amount_minor' => $minimum - $premium];
            $premium = $minimum;
        }
        if (isset($rules['rounding'])) {
            $rounded = IntegerMoney::toUnit($premium, (int) ($rules['rounding']['unit_minor'] ?? 1), (string) ($rules['rounding']['mode'] ?? 'HALF_UP'));
            if ($rounded !== $premium) {
                $adjustments += $rounded - $premium;
                $lines[] = ['code' => 'ROUNDING', 'kind' => 'ADJUSTMENT', 'amount_minor' => $rounded - $premium];
                $premium = $rounded;
            }
        }
        if ($premium <= 0) {
            throw new DomainException('Calculated premium must remain positive.');
        }

        $line = $context['line_code'] ?? null;
        $fee = 0;
        foreach ($feeTables as $table) {
            foreach ($this->charges($table['rules'], 'FEE') as $c) {
                $amt = $this->charge($c, $premium, 0, $line);
                if ($amt === null) {
                    continue;
                }
                $fee += $amt;
                $lines[] = $this->chargeLine($c, $table, $amt);
            }
        }
        $tax = 0;
        foreach ($taxTables as $table) {
            foreach ($this->charges($table['rules'], 'TAX') as $c) {
                $amt = $this->charge($c, $premium, $fee, $line);
                if ($amt === null) {
                    continue;
                }
                $tax += $amt;
                $lines[] = $this->chargeLine($c, $table, $amt);
            }
        }
        foreach ([...$taxTables, ...$feeTables] as $t) {
            if (($t['data_status'] ?? null) === 'DEMO_UNVERIFIED') {
                $warnings[] = 'rating.charge_rates_unverified';
                break;
            }
        }

        [$allocation, $allocationStatus] = $this->allocateBranches($premium, $rules['branch_allocation'] ?? [], $rules['branch_allocation_basis'] ?? null);
        if ($allocationStatus !== 'ALLOCATED') {
            $warnings[] = 'rating.branch_allocation_pending';
        }

        return new PricingResult($base, $coverages, $loadings, $discounts, $adjustments, $premium, $fee, $tax, $premium + $fee + $tax,
            $lines, $allocation, $allocationStatus, array_values(array_unique($warnings)));
    }

    /** One of the 13 PRE §33 methods → integer minor units. */
    public function amount(array $m, array $facts): int
    {
        $method = $m['method'] ?? 'FIXED';

        return match ($method) {
            'FIXED' => (int) ($m['amount_minor'] ?? throw new DomainException('FIXED needs amount_minor.')),
            'RATE_X_SUM_INSURED' => IntegerMoney::ppm(IntegerMoney::wholeFact($facts, $m['fact'] ?? 'sum_insured'), (int) $m['rate_ppm']),
            'RATE_X_LIMIT' => IntegerMoney::ppm(IntegerMoney::wholeFact($facts, $m['fact'] ?? 'limit_minor'), (int) $m['rate_ppm']),
            'TABLE' => $this->table($m, $facts),
            'BAND' => $this->band($m, $facts),
            'TIER' => $this->tier($m, $facts),
            'FORMULA' => $this->formula($m, $facts),
            'PER_PERSON', 'PER_VEHICLE', 'PER_EMPLOYEE', 'PER_DAY', 'PER_TRIP', 'PER_SHIPMENT' => $this->perUnit($method, $m, $facts),
            default => throw new DomainException("Unsupported rating method: {$method}"),
        };
    }

    /** @return array{0: bool, 1: string} */
    private function matches(array $f, array $facts): array
    {
        $actual = data_get($facts, $f['fact']);
        $operator = $f['operator'] ?? (array_key_exists('equals', $f) ? 'EQUALS' : null);
        $expected = $f['value'] ?? ($f['equals'] ?? null);
        $ok = match ($operator) {
            'EQUALS' => $actual === $expected,
            'IN' => in_array($actual, (array) $expected, true),
            'BETWEEN' => is_numeric($actual) && isset($expected[0], $expected[1]) && $actual >= $expected[0] && $actual <= $expected[1],
            default => throw new DomainException('Unsupported rating factor operator.'),
        };

        return [$ok, $operator];
    }

    private function table(array $m, array $facts): int
    {
        $keys = (array) ($m['keys'] ?? []);
        $probe = array_map(fn ($k) => data_get($facts, $k), $keys);
        foreach ($m['rows'] ?? [] as $row) {
            if (array_values((array) $row['match']) === $probe) {
                return (int) $row['amount_minor'];
            }
        }
        if (isset($m['default_minor'])) {
            return (int) $m['default_minor'];
        }
        throw new DomainException('No tariff table row matches the risk facts.');
    }

    private function band(array $m, array $facts): int
    {
        $v = IntegerMoney::wholeFact($facts, $m['fact']);
        foreach ($m['bands'] ?? [] as $b) {
            if (($b['min'] === null || $v >= $b['min']) && ($b['max'] === null || $v <= $b['max'])) {
                return isset($b['rate_ppm']) ? IntegerMoney::ppm($v, (int) $b['rate_ppm']) : (int) $b['amount_minor'];
            }
        }
        throw new DomainException("No tariff band covers {$m['fact']}.");
    }

    /** Progressive tiers: each slice of the fact up to `up_to` is charged at its own rate. */
    private function tier(array $m, array $facts): int
    {
        $v = IntegerMoney::wholeFact($facts, $m['fact']);
        $total = 0;
        $floor = 0;
        foreach ($m['tiers'] ?? [] as $t) {
            if ($v <= $floor) {
                break;
            }
            $ceiling = $t['up_to'] === null ? $v : min($v, (int) $t['up_to']);
            $total += IntegerMoney::ppm($ceiling - $floor, (int) $t['rate_ppm']);
            $floor = $ceiling;
        }

        return $total;
    }

    /** Restricted linear formula (no eval): constant_minor + Σ fact × multiplier_ppm. */
    private function formula(array $m, array $facts): int
    {
        $total = (int) ($m['constant_minor'] ?? 0);
        foreach ($m['terms'] ?? [] as $term) {
            $total += IntegerMoney::ppm(IntegerMoney::wholeFact($facts, $term['fact']), (int) $term['multiplier_ppm']);
        }

        return $total;
    }

    private function perUnit(string $method, array $m, array $facts): int
    {
        $count = IntegerMoney::wholeFact($facts, $m['count_fact'] ?? self::PER_UNIT_FACT[$method]);
        if ($count < 0) {
            throw new DomainException("{$method} count cannot be negative.");
        }

        return IntegerMoney::mul($count, (int) $m['unit_amount_minor']);
    }

    /** @return list<array> */
    private function charges(array $rules, string $legacyKind): array
    {
        if (isset($rules['charges'])) {
            return $rules['charges'];
        }
        if ($legacyKind === 'TAX' && isset($rules['basis_points'])) {
            return [['code' => 'TAX', 'kind' => 'TAX', 'basis' => 'PREMIUM', 'basis_points' => (int) $rules['basis_points']]];
        }
        if ($legacyKind === 'FEE' && isset($rules['fixed_minor'])) {
            return [['code' => 'FEE', 'kind' => 'FEE', 'basis' => 'FIXED', 'fixed_minor' => (int) $rules['fixed_minor']]];
        }

        return [];
    }

    private function charge(array $c, int $premium, int $fees, ?string $line): ?int
    {
        if (! empty($c['classes']) && ($line === null || ! in_array($line, $c['classes'], true))) {
            return null;
        }

        return match ($c['basis'] ?? 'PREMIUM') {
            'PREMIUM' => IntegerMoney::bp($premium, (int) $c['basis_points']),
            'PREMIUM_AND_FEES' => IntegerMoney::bp($premium + $fees, (int) $c['basis_points']),
            'FIXED' => (int) $c['fixed_minor'],
            default => throw new DomainException('Unsupported charge basis.'),
        };
    }

    private function chargeLine(array $c, array $table, int $amount): array
    {
        return array_filter([
            'code' => $c['code'], 'kind' => $c['kind'] ?? 'TAX', 'basis' => $c['basis'] ?? 'PREMIUM',
            'basis_points' => $c['basis_points'] ?? null, 'fixed_minor' => $c['fixed_minor'] ?? null,
            'source_table' => $table['source_table'] ?? null, 'source_id' => $table['source_id'] ?? null, 'source_version' => $table['version'] ?? null,
            'data_status' => $table['data_status'] ?? null, 'amount_minor' => $amount,
        ], fn ($v) => $v !== null);
    }

    /** @return array{0: list<array>, 1: string} */
    /**
     * Owner decision item 9: branch allocations are never invented. No carrier split -> PENDING_CARRIER_ALLOCATION;
     * an internal estimate (branch_allocation_basis = ESTIMATED_NON_REGULATORY) is kept but excluded from reporting.
     */
    private function allocateBranches(int $premium, array $spec, ?string $basis = null): array
    {
        if ($spec === []) {
            return [[], 'PENDING_CARRIER_ALLOCATION'];
        }
        $shares = [];
        foreach ($spec as $s) {
            $shares[(string) $s['branch_code']] = (int) $s['basis_points'];
        }
        $parts = IntegerMoney::allocate($premium, $shares);
        $out = [];
        foreach ($shares as $code => $bp) {
            $out[] = ['branch_code' => (string) $code, 'basis_points' => $bp, 'amount_minor' => $parts[$code]];
        }

        return [$out, $basis === 'ESTIMATED_NON_REGULATORY' ? 'ESTIMATED_NON_REGULATORY' : 'ALLOCATED'];
    }
}
