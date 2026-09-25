<?php

declare(strict_types=1);

/** REQ-RAT-001 REQ-RAT-003 REQ-RAT-005 — rating v2 engine: 13 methods, build-up, integer money, charges, allocation. */

use App\Domain\Rating\DeterministicRatingEngine;
use App\Domain\Rating\IntegerMoney;

it('REQ-RAT-001 prices all 13 PRE §33 methods in integer minor units', function () {
    $e = new DeterministicRatingEngine;
    $f = ['sum_insured' => 6500000, 'limit_minor' => 1000000, 'zone' => 'DOUALA', 'usage' => 'TAXI', 'power' => 9, 'insured_count' => 3,
        'vehicle_count' => 2, 'employee_count' => 12, 'duration_days' => 10, 'trip_count' => 4, 'shipment_count' => 5];
    expect($e->amount(['method' => 'FIXED', 'amount_minor' => 5000], $f))->toBe(5000)
        ->and($e->amount(['method' => 'RATE_X_SUM_INSURED', 'rate_ppm' => 12345], $f))->toBe(80243) // 6500000 × 0.012345 = 80242.5 → 80243
        ->and($e->amount(['method' => 'RATE_X_LIMIT', 'rate_ppm' => 5000], $f))->toBe(5000)
        ->and($e->amount(['method' => 'TABLE', 'keys' => ['zone', 'usage'], 'rows' => [['match' => ['DOUALA', 'TAXI'], 'amount_minor' => 70000]]], $f))->toBe(70000)
        ->and($e->amount(['method' => 'BAND', 'fact' => 'power', 'bands' => [['min' => null, 'max' => 7, 'amount_minor' => 1], ['min' => 8, 'max' => 10, 'amount_minor' => 42000], ['min' => 11, 'max' => null, 'amount_minor' => 3]]], $f))->toBe(42000)
        ->and($e->amount(['method' => 'TIER', 'fact' => 'sum_insured', 'tiers' => [['up_to' => 1000000, 'rate_ppm' => 20000], ['up_to' => 5000000, 'rate_ppm' => 10000], ['up_to' => null, 'rate_ppm' => 5000]]], $f))->toBe(20000 + 40000 + 7500)
        ->and($e->amount(['method' => 'FORMULA', 'constant_minor' => 1000, 'terms' => [['fact' => 'sum_insured', 'multiplier_ppm' => 1000], ['fact' => 'power', 'multiplier_ppm' => 100000000]]], $f))->toBe(1000 + 6500 + 900)
        ->and($e->amount(['method' => 'PER_PERSON', 'unit_amount_minor' => 1000], $f))->toBe(3000)
        ->and($e->amount(['method' => 'PER_VEHICLE', 'unit_amount_minor' => 1000], $f))->toBe(2000)
        ->and($e->amount(['method' => 'PER_EMPLOYEE', 'unit_amount_minor' => 1000], $f))->toBe(12000)
        ->and($e->amount(['method' => 'PER_DAY', 'unit_amount_minor' => 1000], $f))->toBe(10000)
        ->and($e->amount(['method' => 'PER_TRIP', 'unit_amount_minor' => 1000], $f))->toBe(4000)
        ->and($e->amount(['method' => 'PER_SHIPMENT', 'unit_amount_minor' => 1000], $f))->toBe(5000);
    expect(count(DeterministicRatingEngine::METHODS))->toBe(13);
});

it('REQ-RAT-001 refuses fractional money facts and unknown methods', function () {
    expect(fn () => (new DeterministicRatingEngine)->amount(['method' => 'RATE_X_SUM_INSURED', 'rate_ppm' => 1], ['sum_insured' => 10.5]))->toThrow(DomainException::class)
        ->and(fn () => (new DeterministicRatingEngine)->amount(['method' => 'MAGIC'], []))->toThrow(DomainException::class);
});

it('REQ-RAT-001 builds base + coverages + loadings − capped discounts, minimum premium and rounding, explainably', function () {
    $rules = [
        'base' => ['method' => 'FIXED', 'amount_minor' => 100000],
        'coverages' => [['code' => 'THEFT', 'method' => 'FIXED', 'amount_minor' => 20000], ['code' => 'GLASS', 'method' => 'FIXED', 'amount_minor' => 5000, 'optional' => true], ['code' => 'ROADSIDE', 'method' => 'FIXED', 'amount_minor' => 7000, 'optional' => true]],
        'loadings' => [['code' => 'TAXI', 'type' => 'HIGH_RISK_USE', 'fact' => 'usage', 'operator' => 'EQUALS', 'value' => 'TAXI', 'basis_points' => 2000]],
        'discounts' => [['code' => 'NCD', 'type' => 'NO_CLAIMS', 'fact' => 'claims', 'operator' => 'EQUALS', 'value' => 0, 'basis_points' => 2000],
            ['code' => 'FLEET', 'type' => 'FLEET', 'basis_points' => 1500], ['code' => 'MGR', 'type' => 'MANUAL_AUTHORIZED', 'basis_points' => 1000]],
        'discount_cap_basis_points' => 3000,
        'rounding' => ['unit_minor' => 100, 'mode' => 'UP'],
    ];
    $p = (new DeterministicRatingEngine)->price(['usage' => 'TAXI', 'claims' => 0, 'selected_coverages' => ['GLASS']], $rules);
    // 125000 → +25000 loading = 150000; discounts 30000+22500=52500 capped to 45000 → 105000; MGR not authorized.
    expect($p->baseMinor)->toBe(100000)->and($p->coveragesMinor)->toBe(25000)->and($p->loadingsMinor)->toBe(25000)
        ->and($p->discountsMinor)->toBe(45000)->and($p->netPremiumMinor)->toBe(105000)
        ->and(collect($p->lines)->pluck('code')->all())->toBe(['BASE', 'THEFT', 'GLASS', 'TAXI', 'NCD', 'FLEET', 'DISCOUNT_CAP'])
        ->and($p->warnings)->toContain('rating.discount_capped');
    $sum = array_sum(array_map(fn ($l) => $l['kind'] === 'DISCOUNT' && $l['code'] === 'DISCOUNT_CAP' ? $l['amount_minor'] : $l['amount_minor'], $p->lines));
    expect($sum)->toBe($p->netPremiumMinor);

    $authorized = (new DeterministicRatingEngine)->price(['usage' => 'PRIVATE', 'claims' => 2], [...$rules, 'discount_cap_basis_points' => 10000, 'minimum_premium_minor' => 120000], [], [], ['authorized_discounts' => ['MGR']]);
    // 120000 − 18000 FLEET − 12000 MGR = 90000 → minimum 120000.
    expect($authorized->netPremiumMinor)->toBe(120000)->and(collect($authorized->lines)->firstWhere('code', 'MINIMUM_PREMIUM')['amount_minor'])->toBe(30000);
});

it('REQ-RAT-003 applies versioned charges by class and basis; v1 blobs still read as one TAX / FEE line', function () {
    $tax = ['source_table' => 'tax_levy_versions', 'source_id' => 't1', 'version' => 3, 'data_status' => 'DEMO_UNVERIFIED', 'rules' => ['charges' => [
        ['code' => 'VAT', 'kind' => 'TAX', 'basis' => 'PREMIUM_AND_FEES', 'basis_points' => 1000],
        ['code' => 'LIFE_ONLY', 'kind' => 'LEVY', 'basis' => 'PREMIUM', 'basis_points' => 500, 'classes' => ['LIFE']],
        ['code' => 'STAMP', 'kind' => 'STATUTORY', 'basis' => 'FIXED', 'fixed_minor' => 1500],
    ]]];
    $fee = ['rules' => ['charges' => [['code' => 'PLATFORM_FEE', 'kind' => 'FEE', 'basis' => 'FIXED', 'fixed_minor' => 1000]]]];
    $p = (new DeterministicRatingEngine)->price([], ['base_premium_minor' => 10000], [$tax], [$fee], ['line_code' => 'MOTOR']);
    expect($p->feeMinor)->toBe(1000)->and($p->taxMinor)->toBe(1100 + 1500)->and($p->totalMinor)->toBe(10000 + 1000 + 2600)
        ->and(collect($p->lines)->pluck('code')->all())->not->toContain('LIFE_ONLY')
        ->and(collect($p->lines)->firstWhere('code', 'VAT')['source_version'])->toBe(3)
        ->and($p->warnings)->toContain('rating.charge_rates_unverified');

    $legacy = (new DeterministicRatingEngine)->rate([], ['base_premium_minor' => 10000], ['basis_points' => 1925], ['fixed_minor' => 1000]);
    expect($legacy->taxMinor)->toBe(1925)->and($legacy->feeMinor)->toBe(1000)->and($legacy->totalMinor)->toBe(12925);
});

it('REQ-RAT-005 allocates the net premium per CIMA branch so the parts add back exactly; pending OQ-24 otherwise', function () {
    $p = (new DeterministicRatingEngine)->price([], ['base_premium_minor' => 100001, 'branch_allocation' => [['branch_code' => '07', 'basis_points' => 3333], ['branch_code' => '09', 'basis_points' => 3333], ['branch_code' => '15', 'basis_points' => 3334]]]);
    expect($p->allocationStatus)->toBe('ALLOCATED')->and(array_sum(array_column($p->branchAllocation, 'amount_minor')))->toBe(100001)
        ->and(array_column($p->branchAllocation, 'branch_code'))->toBe(['07', '09', '15']);
    $none = (new DeterministicRatingEngine)->price([], ['base_premium_minor' => 5000]);
    expect($none->allocationStatus)->toBe('PENDING_OQ_24')->and($none->branchAllocation)->toBe([])->and($none->warnings)->toContain('rating.branch_allocation_pending');
    expect(IntegerMoney::allocate(7, ['A' => 5000, 'B' => 5000]))->toBe(['A' => 4, 'B' => 3]);
});

it('REQ-RAT-001 is deterministic: equal inputs give identical output, integer rounding half away from zero', function () {
    $rules = ['base' => ['method' => 'RATE_X_SUM_INSURED', 'rate_ppm' => 33333], 'loadings' => [['code' => 'L', 'fact' => 'x', 'operator' => 'IN', 'value' => [1], 'basis_points' => 333]]];
    $a = (new DeterministicRatingEngine)->price(['sum_insured' => 999999, 'x' => 1], $rules)->toArray();
    $b = (new DeterministicRatingEngine)->price(['x' => 1, 'sum_insured' => 999999], $rules)->toArray();
    expect($a)->toBe($b)->and(IntegerMoney::divRound(5, 2))->toBe(3)->and(IntegerMoney::divRound(-5, 2))->toBe(-3)->and(IntegerMoney::toUnit(12350, 100))->toBe(12400)->and(IntegerMoney::toUnit(12349, 100, 'DOWN'))->toBe(12300);
});
