<?php

declare(strict_types=1);

use App\Application\FinancialDistribution\BordereauService;
use App\Application\FinancialDistribution\CarrierSettlementService;
use App\Models\SettlementBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/**
 * Covers the two capabilities the Wave6 domain absorbed from the legacy path:
 * explicit policy selection on a bordereau, and a per-stage settlement
 * approval record.
 */
function w6Fixture(): array
{
    $fx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $tenant = $fx['tenant'];

    $maker = makeAuthTestUser($tenant, ['bordereaux.prepare'], 'W6_MAKER');
    $checker = makeAuthTestUser($tenant, ['bordereaux.approve'], 'W6_CHECKER');

    return [$fx, $tenant, $maker, $checker];
}

function w6Policy(array $fx, $tenant, array $overrides = []): string
{
    return makeMobileTestPolicy($fx['proposal'], $tenant, $fx['carrier']->id, $fx['party']->id, $overrides)->id;
}

it('bills exactly the policies named when an explicit selection is given', function () {
    [$fx, $tenant, $maker] = w6Fixture();

    // Three policies inside the period; only two are named.
    $wanted = [
        w6Policy($fx, $tenant, ['issued_at' => now()->subDays(5)]),
        w6Policy($fx, $tenant, ['issued_at' => now()->subDays(4)]),
    ];
    $notWanted = w6Policy($fx, $tenant, ['issued_at' => now()->subDays(3)]);

    $bordereau = app(BordereauService::class)->prepare([
        'tenant_id' => $tenant->id,
        'carrier_id' => $fx['carrier']->id,
        'type' => 'PREMIUM',
        'currency' => 'XAF',
        'period_start' => now()->subMonth()->toDateString(),
        'period_end' => now()->toDateString(),
        'policy_ids' => $wanted,
        'idempotency_key' => (string) Str::uuid(),
    ], $maker);

    expect($bordereau->item_count)->toBe(2);

    $billed = DB::table('bordereau_items')->where('bordereau_id', $bordereau->id)->pluck('policy_id')->all();
    sort($billed);
    sort($wanted);

    expect($billed)->toBe($wanted);
    expect($billed)->not->toContain($notWanted);
});

it('still auto-selects by period when no explicit selection is given', function () {
    [$fx, $tenant, $maker] = w6Fixture();

    w6Policy($fx, $tenant, ['issued_at' => now()->subDays(5)]);
    w6Policy($fx, $tenant, ['issued_at' => now()->subDays(4)]);
    // Outside the window — must not be swept in.
    w6Policy($fx, $tenant, ['issued_at' => now()->subYears(2)]);

    $bordereau = app(BordereauService::class)->prepare([
        'tenant_id' => $tenant->id,
        'carrier_id' => $fx['carrier']->id,
        'type' => 'PREMIUM',
        'currency' => 'XAF',
        'period_start' => now()->subMonth()->toDateString(),
        'period_end' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ], $maker);

    expect($bordereau->item_count)->toBe(2);
});

it('refuses an explicit selection naming a policy from another tenant', function () {
    [$fx, $tenant, $maker] = w6Fixture();
    $mine = w6Policy($fx, $tenant, ['issued_at' => now()->subDays(5)]);

    $otherFx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $theirs = makeMobileTestPolicy($otherFx['proposal'], $otherFx['tenant'], $otherFx['carrier']->id, $otherFx['party']->id)->id;

    // Quietly billing a shorter list than asked for would be worse than failing.
    expect(fn () => app(BordereauService::class)->prepare([
        'tenant_id' => $tenant->id,
        'carrier_id' => $fx['carrier']->id,
        'type' => 'PREMIUM',
        'currency' => 'XAF',
        'period_start' => now()->subMonth()->toDateString(),
        'period_end' => now()->toDateString(),
        'policy_ids' => [$mine, $theirs],
        'idempotency_key' => (string) Str::uuid(),
    ], $maker))->toThrow(ValidationException::class);
});

it('writes a settlement_approvals record when the governed path approves', function () {
    [$fx, $tenant, $maker, $checker] = w6Fixture();

    $batch = SettlementBatch::create([
        'tenant_id' => $tenant->id,
        'carrier_id' => $fx['carrier']->id,
        'period_start' => now()->subMonth()->toDateString(),
        'period_end' => now()->toDateString(),
        'net_amount_minor' => 1500000,
        'currency' => 'XAF',
        'status' => 'DRAFT',
        'prepared_by' => $maker->id,
    ]);

    app(CarrierSettlementService::class)->approve($batch, $checker, 'Reconciled against the carrier statement for the period.');

    $approval = DB::table('settlement_approvals')->where('settlement_batch_id', $batch->id)->first();

    // Previously only the legacy controller wrote this table, so a settlement
    // approved through the governed path left no per-stage decision record.
    expect($approval)->not->toBeNull();
    expect($approval->stage)->toBe('FINANCE_APPROVAL');
    expect($approval->decision)->toBe('APPROVED');
    expect($approval->actor_id)->toBe($checker->id);
    expect($approval->notes)->toContain('Reconciled against the carrier statement');

    expect(SettlementBatch::find($batch->id)->status)->toBe('APPROVED');
});

it('still refuses self-approval of a settlement and writes no approval record', function () {
    [$fx, $tenant, $maker] = w6Fixture();

    $batch = SettlementBatch::create([
        'tenant_id' => $tenant->id,
        'carrier_id' => $fx['carrier']->id,
        'period_start' => now()->subMonth()->toDateString(),
        'period_end' => now()->toDateString(),
        'net_amount_minor' => 1500000,
        'currency' => 'XAF',
        'status' => 'DRAFT',
        'prepared_by' => $maker->id,
    ]);

    expect(fn () => app(CarrierSettlementService::class)->approve($batch, $maker))
        ->toThrow(ValidationException::class);

    expect(DB::table('settlement_approvals')->where('settlement_batch_id', $batch->id)->count())->toBe(0);
    expect(SettlementBatch::find($batch->id)->status)->toBe('DRAFT');
});
