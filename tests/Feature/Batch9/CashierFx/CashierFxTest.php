<?php

declare(strict_types=1);

use App\Application\Finance\Fx\FinanceProblem;
use App\Application\Finance\Fx\FxRateService;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

const B97_ALL = ['cashier.sessions.view', 'cashier.sessions.operate', 'cashier.sessions.approve', 'fx.rates.view', 'fx.rates.manage'];

function b97Branch(Tenant $t): string
{
    $id = (string) Str::uuid();
    DB::table('tenant_branches')->insert(['id' => $id, 'tenant_id' => $t->id, 'code' => 'BR-'.Str::random(5), 'name' => 'Douala', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function b97Outbox(string $event): int
{
    return DB::table('outbox_messages')->where('event_name', $event)->count();
}

it('REQ-PAY-013 seeds the EUR peg and converts XAF<->XOF and EUR->XAF with the rate ids stored', function () {
    $t = makeAuthTestTenant();
    $fx = app(FxRateService::class);

    $eur = $fx->convert($t->id, 10000, 'EUR', 'XAF'); // 100.00 EUR
    expect((int) $eur->to_amount_minor)->toBe(65596)->and($eur->method)->toBe('DIRECT');
    expect(DB::table('fx_rates')->find($eur->fx_rate_id)->source)->toBe('EUR_PEG');

    $back = $fx->convert($t->id, 655957, 'XAF', 'EUR');
    expect((int) $back->to_amount_minor)->toBe(100000)->and($back->method)->toBe('INVERSE');

    $cross = $fx->convert($t->id, 5000, 'XAF', 'XOF');
    expect((int) $cross->to_amount_minor)->toBe(5000)->and($cross->method)->toBe('CROSS')->and($cross->second_fx_rate_id)->not->toBeNull();

    // a market rate on a pegged pair is refused
    expect(fn () => $fx->record($t->id, ['base_currency' => 'EUR', 'quote_currency' => 'XAF', 'rate' => '650', 'source' => 'BANK', 'effective_at' => now()->toIso8601String()], null))
        ->toThrow(FinanceProblem::class);
});

it('REQ-PAY-013 rates are append-only and looked up as of a timestamp', function () {
    $t = makeAuthTestTenant();
    $u = makeAuthTestUser($t, B97_ALL);
    Passport::actingAs($u);

    $r1 = $this->postJson('/api/v1/finance/fx-rates', ['base_currency' => 'USD', 'quote_currency' => 'XAF', 'rate' => '600.5', 'source' => 'BEAC', 'effective_at' => '2026-01-01T00:00:00Z'], tenantHeader($t))
        ->assertCreated()->json('data');
    $r2 = $this->postJson('/api/v1/finance/fx-rates', ['base_currency' => 'USD', 'quote_currency' => 'XAF', 'rate' => '610', 'source' => 'BEAC', 'effective_at' => '2026-06-01T00:00:00Z'], tenantHeader($t))
        ->assertCreated()->json('data');
    expect(b97Outbox('fx.rate.recorded'))->toBe(2);

    $old = $this->getJson('/api/v1/finance/fx-rates/lookup?from=USD&to=XAF&as_of=2026-03-01T00:00:00Z', tenantHeader($t))->assertOk()->json('data');
    expect($old['legs'][0]['fx_rate_id'])->toBe($r1['id']);
    $new = $this->getJson('/api/v1/finance/fx-rates/lookup?from=USD&to=XAF&as_of=2026-07-01T00:00:00Z', tenantHeader($t))->assertOk()->json('data');
    expect($new['legs'][0]['fx_rate_id'])->toBe($r2['id']);
    $this->getJson('/api/v1/finance/fx-rates/lookup?from=USD&to=XAF&as_of=2025-01-01T00:00:00Z', tenantHeader($t))->assertStatus(422);

    $conv = app(FxRateService::class)->convert($t->id, 10000, 'USD', 'XAF', '2026-03-01T00:00:00Z'); // 100.00 USD
    expect((int) $conv->to_amount_minor)->toBe(60050)->and($conv->fx_rate_id)->toBe($r1['id']);

    expect(fn () => DB::table('fx_rates')->where('id', $r1['id'])->update(['rate' => 1]))->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::table('fx_rates')->where('id', $r1['id'])->delete())->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::table('fx_conversions')->where('id', $conv->id)->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

it('REQ-PAY-010 runs a cashier session: float, collections, close with variance, supervisor approval', function () {
    $t = makeAuthTestTenant();
    $cashier = makeAuthTestUser($t, B97_ALL);
    $supervisor = makeAuthTestUser($t, B97_ALL);
    $branch = b97Branch($t);
    Passport::actingAs($cashier);

    $s = $this->postJson('/api/v1/finance/cashier-sessions', ['branch_id' => $branch, 'opening_float_minor' => 50000], tenantHeader($t))->assertCreated()->json('data');
    expect($s['status'])->toBe('OPEN');
    // one open session per cashier per branch
    $this->postJson('/api/v1/finance/cashier-sessions', ['branch_id' => $branch, 'opening_float_minor' => 0], tenantHeader($t))->assertStatus(409);

    $base = "/api/v1/finance/cashier-sessions/{$s['id']}";
    $this->postJson("$base/collections", ['method' => 'CASH', 'amount_minor' => 100000], tenantHeader($t))->assertStatus(422); // anonymous
    $this->postJson("$base/collections", ['method' => 'CHEQUE', 'amount_minor' => 1000, 'payer_name' => 'Jean'], tenantHeader($t))->assertStatus(422); // no cheque no
    // a real receivable (Batch 9-1 ledger): the cash collection settles it
    $obligation = app(\App\Application\Finance\Obligations\ObligationService::class)->create([
        'tenant_id' => $t->id, 'kind' => 'RECEIVABLE', 'type' => 'PREMIUM', 'source_type' => 'test', 'source_id' => (string) Str::uuid(),
        'currency' => 'XAF', 'amount_minor' => 100000, 'due_at' => now(),
    ]);
    $this->postJson("$base/collections", ['method' => 'CASH', 'amount_minor' => 100000, 'payer_name' => 'Jean Mbarga', 'financial_obligation_id' => $obligation->id], tenantHeader($t))->assertCreated();
    expect(app(\App\Application\Finance\Obligations\ObligationService::class)->outstanding($obligation->id))->toBe(0);
    $this->postJson("$base/collections", ['method' => 'CHEQUE', 'amount_minor' => 250000, 'payer_name' => 'SARL Kribi', 'cheque_number' => 'CHQ-001', 'cheque_bank' => 'Afriland'], tenantHeader($t))->assertCreated();
    $eur = $this->postJson("$base/collections", ['method' => 'CASH', 'amount_minor' => 1000, 'currency' => 'EUR', 'payer_name' => 'Tourist'], tenantHeader($t))->assertCreated()->json('data');
    expect($eur['session_amount_minor'])->toBe(6560)->and($eur['fx_conversion_id'])->not->toBeNull();
    expect(b97Outbox('cashier.collection.recorded'))->toBe(3);

    // expected = 50000 + 100000 + 6560 = 156560; counted short by 560
    $this->postJson("$base/close", ['counted_cash_minor' => 156000], tenantHeader($t))->assertStatus(422); // variance needs notes
    $closed = $this->postJson("$base/close", ['counted_cash_minor' => 156000, 'notes' => 'Coins missing'], tenantHeader($t))->assertOk()->json('data');
    expect($closed['status'])->toBe('CLOSED')->and($closed['expected_cash_minor'])->toBe(156560)->and($closed['variance_minor'])->toBe(-560)
        ->and($closed['cheque_total_minor'])->toBe(250000)->and($closed['cheque_count'])->toBe(1);
    $this->postJson("$base/collections", ['method' => 'CASH', 'amount_minor' => 1, 'payer_name' => 'Late'], tenantHeader($t))->assertStatus(409);

    // maker-checker
    $this->postJson("$base/decide", ['decision' => 'APPROVE'], tenantHeader($t))->assertStatus(403);
    Passport::actingAs($supervisor);
    $done = $this->postJson("$base/decide", ['decision' => 'APPROVE', 'notes' => 'ok'], tenantHeader($t))->assertOk()->json('data');
    expect($done['status'])->toBe('APPROVED')->and($done['decided_by'])->toBe($supervisor->id);
    expect(b97Outbox('cashier.session.approved'))->toBe(1);

    // cashier can open a new session once the previous is no longer OPEN
    Passport::actingAs($cashier);
    $this->postJson('/api/v1/finance/cashier-sessions', ['branch_id' => $branch, 'opening_float_minor' => 0], tenantHeader($t))->assertCreated();
    $this->getJson("$base", tenantHeader($t))->assertOk()->assertJsonPath('data.totals.cash', 106560);
});

it('REQ-PAY-010 enforces permissions and tenant isolation', function () {
    $t = makeAuthTestTenant();
    $other = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, ['cashier.sessions.view']));
    $this->postJson('/api/v1/finance/cashier-sessions', ['branch_id' => b97Branch($t), 'opening_float_minor' => 0], tenantHeader($t))->assertForbidden();

    Passport::actingAs(makeAuthTestUser($t, B97_ALL));
    $this->postJson('/api/v1/finance/cashier-sessions', ['branch_id' => b97Branch($other), 'opening_float_minor' => 0], tenantHeader($t))->assertStatus(422);
});
