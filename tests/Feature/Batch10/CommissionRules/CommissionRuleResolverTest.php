<?php

declare(strict_types=1);

use App\Application\Commissions\Rules\CommissionRuleResolver;
use App\Application\FinancialDistribution\CommissionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

/** REQ-COM-002 — Batch 10-2 commission rules: scope, tiers, volume, hybrid, 100 % splits. */
function crFixture(): array
{
    $fx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $tenant = $fx['tenant'];
    app(TenantContext::class)->set($tenant->id);
    $maker = makeAuthTestUser($tenant, ['commission.manage', 'commission.approve'], 'MAKER');
    $checker = makeAuthTestUser($tenant, ['commission.manage', 'commission.approve'], 'CHECKER');
    $party = (string) Str::uuid();
    DB::table('parties')->insert(['id' => $party, 'type' => 'ORGANIZATION', 'display_name' => 'Broker '.Str::random(4), 'status' => 'ACTIVE', 'legal_identity' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $partner = (string) Str::uuid();
    DB::table('partners')->insert(['id' => $partner, 'tenant_id' => $tenant->id, 'party_id' => $party, 'type' => 'BROKER', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $agreement = (string) Str::uuid();
    DB::table('carrier_broker_agreements')->insert(['id' => $agreement, 'carrier_id' => $fx['carrier']->id, 'partner_id' => $partner, 'agreement_number' => 'CBA-'.Str::random(6), 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

    return [...$fx, 'maker' => $maker, 'checker' => $checker, 'partner' => $partner, 'agreement' => $agreement];
}

function crRule(array $fx, array $d): App\Models\CommissionRuleVersion
{
    $svc = app(CommissionService::class);
    $rule = $svc->createRule([
        'tenant_id' => $fx['tenant']->id, 'carrier_id' => $fx['carrier']->id, 'basis_points' => 1000, 'holdback_basis_points' => 0,
        'vesting_days' => 0, 'effective_from' => '2026-01-01', ...$d,
    ], $fx['maker']);

    return $svc->approveRule($rule, $fx['checker']);
}

it('resolves the most specific approved rule by agreement, product/line and transaction type', function () {
    $fx = crFixture();
    crRule($fx, ['basis_points' => 1000]);
    crRule($fx, ['basis_points' => 1200, 'agreement_id' => $fx['agreement'], 'line_code' => 'AUTO']);
    crRule($fx, ['basis_points' => 500, 'agreement_id' => $fx['agreement'], 'line_code' => 'AUTO', 'transaction_type' => 'RENEWAL']);
    $r = app(CommissionRuleResolver::class);
    $c = $fx['carrier']->id;

    expect($r->resolve($c, null, 'AUTO', 'NEW_BUSINESS', '2026-09-01', 100000)->amountMinor)->toBe(10000)
        ->and($r->resolve($c, $fx['agreement'], 'AUTO', 'NEW_BUSINESS', '2026-09-01', 100000)->amountMinor)->toBe(12000)
        ->and($r->resolve($c, $fx['agreement'], $fx['product']->id, 'renewal', '2026-09-01', 100000)->amountMinor)->toBe(5000)
        ->and($r->resolve($c, $fx['agreement'], 'HOME', 'RENEWAL', '2026-09-01', 100000)->basisPoints)->toBe(1000)
        ->and($r->resolve($c, null, 'AUTO', null, '2025-06-01', 100000))->toBeNull();
});

it('applies premium tiers, volume tiers on period production and hybrid bonus', function () {
    $fx = crFixture();
    $tiered = crRule($fx, ['calculation_method' => 'TIERED', 'transaction_type' => 'NEW_BUSINESS', 'tiers' => [
        ['tier_basis' => 'PREMIUM', 'threshold_from_minor' => 0, 'threshold_to_minor' => 500000, 'basis_points' => 1000],
        ['tier_basis' => 'PREMIUM', 'threshold_from_minor' => 500000, 'basis_points' => 1500],
    ]]);
    $r = app(CommissionRuleResolver::class);
    expect($r->compute($tiered, 100000)->amountMinor)->toBe(10000)->and($r->compute($tiered, 600000)->amountMinor)->toBe(90000);

    $volume = crRule($fx, ['calculation_method' => 'VOLUME', 'transaction_type' => 'RENEWAL', 'tiers' => [
        ['tier_basis' => 'VOLUME', 'threshold_from_minor' => 0, 'threshold_to_minor' => 1000000, 'basis_points' => 800],
        ['tier_basis' => 'VOLUME', 'threshold_from_minor' => 1000000, 'basis_points' => 1300],
    ]]);
    $hybrid = crRule($fx, ['calculation_method' => 'HYBRID', 'transaction_type' => 'ENDORSEMENT', 'basis_points' => 1000, 'tiers' => [
        ['tier_basis' => 'VOLUME', 'threshold_from_minor' => 1000000, 'basis_points' => 250],
    ]]);
    $at = now();
    expect($r->compute($volume, 100000, $at)->basisPoints)->toBe(800)->and($r->compute($hybrid, 100000, $at)->basisPoints)->toBe(1000);

    makeMobileTestPolicy($fx['proposal'], $fx['tenant'], $fx['carrier']->id, $fx['party']->id, ['premium_minor' => 1200000, 'issued_at' => $at->copy()->subMinute()]);
    expect($r->compute($volume, 100000, $at)->periodProductionMinor)->toBe(1200000)
        ->and($r->compute($volume, 100000, $at)->basisPoints)->toBe(1300)
        ->and($r->compute($hybrid, 100000, $at)->amountMinor)->toBe(12500);
});

it('splits commission between intermediaries totalling 100% and rejects other totals', function () {
    $fx = crFixture();
    $branch = (string) Str::uuid();
    $rule = crRule($fx, ['basis_points' => 1000, 'splits' => [
        ['beneficiary_type' => 'BROKER', 'share_basis_points' => 5000],
        ['beneficiary_type' => 'BRANCH', 'beneficiary_id' => $branch, 'share_basis_points' => 3000],
        ['beneficiary_type' => 'AGENT', 'beneficiary_id' => (string) Str::uuid(), 'share_basis_points' => 2000],
    ]]);
    $res = app(CommissionRuleResolver::class)->compute($rule, 100003, null, $fx['partner']);
    expect(array_sum(array_column($res->splits, 'amount_minor')))->toBe($res->amountMinor)
        ->and($res->splits[0]['beneficiary_id'])->toBe($fx['partner'])
        ->and($res->splits[1])->toMatchArray(['beneficiary_id' => $branch, 'amount_minor' => 3000]);

    expect(fn () => crRule($fx, ['transaction_type' => 'RENEWAL', 'splits' => [
        ['beneficiary_type' => 'BROKER', 'share_basis_points' => 6000], ['beneficiary_type' => 'AGENT', 'share_basis_points' => 3000],
    ]]))->toThrow(ValidationException::class);
    expect(DB::table('commission_rule_versions')->where('transaction_type', 'RENEWAL')->exists())->toBeFalse();
});

it('keeps maker-checker and requires tiers for tiered rules at approval', function () {
    $fx = crFixture();
    $svc = app(CommissionService::class);
    $rule = $svc->createRule(['tenant_id' => $fx['tenant']->id, 'carrier_id' => $fx['carrier']->id, 'basis_points' => 1000, 'holdback_basis_points' => 0, 'vesting_days' => 0, 'effective_from' => '2026-01-01', 'calculation_method' => 'TIERED'], $fx['maker']);
    expect(fn () => $svc->approveRule($rule, $fx['maker']))->toThrow(ValidationException::class)
        ->and(fn () => $svc->approveRule($rule, $fx['checker']))->toThrow(ValidationException::class);
});

it('uses the rule calculation in the accrual path and records split lines', function () {
    $fx = crFixture();
    $rule = crRule($fx, ['calculation_method' => 'TIERED', 'holdback_basis_points' => 1000, 'tiers' => [
        ['tier_basis' => 'PREMIUM', 'threshold_from_minor' => 0, 'basis_points' => 2000],
    ], 'splits' => [['beneficiary_type' => 'BROKER', 'share_basis_points' => 7000], ['beneficiary_type' => 'AGENT', 'share_basis_points' => 3000]]]);
    $policy = makeMobileTestPolicy($fx['proposal'], $fx['tenant'], $fx['carrier']->id, $fx['party']->id, ['premium_minor' => 100000, 'currency' => 'XAF']);
    $a = app(CommissionService::class)->accrue($policy, $rule, $fx['partner'], 'k-'.Str::random(6));
    expect($a->amount_minor)->toBe(20000);
    $meta = json_decode(DB::table('commission_movements')->where('commission_accrual_id', $a->id)->value('metadata'), true);
    expect($meta['holdback_minor'])->toBe(2000)->and(array_column($meta['splits'], 'amount_minor'))->toBe([14000, 6000]);
});

it('exposes the resolver preview over the API', function () {
    $fx = crFixture();
    crRule($fx, ['basis_points' => 1100, 'agreement_id' => $fx['agreement']]);
    Passport::actingAs($fx['maker']);
    $this->getJson('/api/v1/financial-distribution/commission-rules/resolve?'.http_build_query(['carrier_id' => $fx['carrier']->id, 'agreement_id' => $fx['agreement'], 'product_or_line' => 'AUTO', 'transaction_type' => 'NEW_BUSINESS', 'premium_minor' => 50000]), tenantHeader($fx['tenant']))
        ->assertOk()->assertJsonPath('data.amount_minor', 5500)->assertJsonPath('data.splits.0.beneficiary_id', $fx['partner']);
});
