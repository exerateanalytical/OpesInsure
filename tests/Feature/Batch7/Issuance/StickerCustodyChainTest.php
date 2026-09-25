<?php

declare(strict_types=1);

use App\Application\Certificates\CertificateService;
use App\Models\StickerStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

/** Carrier stock (5 stickers), a broker tenant with a branch, a broker admin, a branch clerk and two agents, an in-force motor policy. */
function b7dStickerChain(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $all = ['stickers.view', 'stickers.handover', 'stickers.allocate', 'stickers.reconcile', 'stickers.assign'];
    $f['carrierAdmin'] = makeAuthTestUser($f['tenant'], [...$all, 'stickers.allocate.carrier'], 'CARRIER_ADMIN');
    $f['admin'] = makeAuthTestUser($f['tenant'], $all, 'BROKER_ADMIN');
    $f['clerk'] = makeAuthTestUser($f['tenant'], $all, 'BRANCH_MANAGER');
    $f['agent'] = makeAuthTestUser($f['tenant'], ['stickers.view', 'stickers.handover', 'stickers.assign'], 'AGENT');
    $f['agent2'] = makeAuthTestUser($f['tenant'], ['stickers.view', 'stickers.handover', 'stickers.assign'], 'AGENT');
    $f['branch'] = (string) Str::uuid();
    DB::table('tenant_branches')->insert(['id' => $f['branch'], 'tenant_id' => $f['tenant']->id, 'code' => 'DLA', 'name' => 'Douala', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    app(CertificateService::class)->receiveBatch(['carrier_id' => $f['carrier']->id, 'batch_number' => 'B-'.Str::random(6),
        'stickers' => array_map(fn ($i) => ['serial_number' => "STK-{$i}", 'security_code' => Str::random(24)], range(1, 5))], $f['carrierAdmin']);
    $f['policy'] = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);

    return $f;
}

function b7dHandover(array $f, array $from, array $to, array $serials): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/sticker-handovers', ['carrier_id' => $f['carrier']->id, 'from' => $from, 'to' => $to, 'serial_numbers' => $serials], tenantHeaderFor($f['tenant']));
}

it('moves stickers carrier → broker → branch → agent → policy with acknowledged handovers and custody events', function () {
    $f = b7dStickerChain();
    $h = tenantHeaderFor($f['tenant']);
    expect(StickerStock::where('custody_level', 'CARRIER')->count())->toBe(5);

    // Releasing carrier stock needs the carrier-side permission.
    Passport::actingAs($f['admin']);
    b7dHandover($f, ['level' => 'CARRIER'], ['level' => 'BROKER'], ['STK-1', 'STK-2', 'STK-3'])->assertForbidden();

    Passport::actingAs($f['carrierAdmin']);
    $ho = b7dHandover($f, ['level' => 'CARRIER'], ['level' => 'BROKER'], ['STK-1', 'STK-2', 'STK-3'])->assertCreated()
        ->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.direction', 'ALLOCATE')->json('data.id');
    expect(StickerStock::where('status', 'IN_TRANSIT')->count())->toBe(3);
    $this->postJson("/api/v1/sticker-handovers/{$ho}/accept", [], $h)->assertStatus(422); // initiator cannot acknowledge

    Passport::actingAs($f['admin']);
    $this->postJson("/api/v1/sticker-handovers/{$ho}/accept", [], $h)->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
    expect(StickerStock::where(['custody_level' => 'BROKER', 'custodian_tenant_id' => $f['tenant']->id, 'status' => 'IN_STOCK'])->count())->toBe(3);

    // Skipping a level is refused.
    b7dHandover($f, ['level' => 'BROKER'], ['level' => 'AGENT', 'user_id' => $f['agent']->id], ['STK-1'])->assertStatus(422);

    $toBranch = b7dHandover($f, ['level' => 'BROKER'], ['level' => 'BRANCH', 'branch_id' => $f['branch']], ['STK-1', 'STK-2'])->assertCreated()->json('data.id');
    Passport::actingAs($f['clerk']);
    $this->postJson("/api/v1/sticker-handovers/{$toBranch}/accept", [], $h)->assertOk();

    $toAgent = b7dHandover($f, ['level' => 'BRANCH', 'branch_id' => $f['branch']], ['level' => 'AGENT', 'user_id' => $f['agent']->id], ['STK-1', 'STK-2'])->assertCreated()->json('data.id');
    Passport::actingAs($f['agent2']);
    $this->postJson("/api/v1/sticker-handovers/{$toAgent}/accept", [], $h)->assertStatus(422); // only the receiving agent
    Passport::actingAs($f['agent']);
    $this->postJson("/api/v1/sticker-handovers/{$toAgent}/accept", [], $h)->assertOk();
    expect(StickerStock::where(['custody_level' => 'AGENT', 'custodian_user_id' => $f['agent']->id])->count())->toBe(2);

    // Another agent cannot use this agent's sticker; the holder assigns it to the motor policy.
    Passport::actingAs($f['agent2']);
    $this->postJson("/api/v1/policies/{$f['policy']->id}/sticker", ['serial_number' => 'STK-1'], $h)->assertStatus(422);
    Passport::actingAs($f['agent']);
    $this->postJson("/api/v1/policies/{$f['policy']->id}/sticker", ['serial_number' => 'STK-1'], $h)->assertCreated()
        ->assertJsonPath('data.status', 'ASSIGNED')->assertJsonPath('data.custody_level', 'POLICY')->assertJsonPath('data.assigned_policy_id', $f['policy']->id);
    $this->postJson("/api/v1/policies/{$f['policy']->id}/sticker", ['serial_number' => 'STK-2'], $h)->assertStatus(422); // one sticker per policy

    $trail = $this->getJson('/api/v1/stickers/STK-1/custody', $h)->assertOk()->json('data.events');
    expect(array_column($trail, 'event_type'))->toBe(['RECEIVED', 'HANDOVER_INITIATED', 'HANDOVER_ACCEPTED', 'HANDOVER_INITIATED', 'HANDOVER_ACCEPTED',
        'HANDOVER_INITIATED', 'HANDOVER_ACCEPTED', 'ASSIGNED_TO_POLICY'])
        ->and(array_column($trail, 'to_level'))->toBe(['CARRIER', 'BROKER', 'BROKER', 'BRANCH', 'BRANCH', 'AGENT', 'AGENT', 'POLICY']);

    $inventory = collect($this->getJson('/api/v1/stickers/inventory?carrier_id='.$f['carrier']->id, $h)->assertOk()->json('data'));
    expect($inventory->where('custody_level', 'CARRIER')->sum('quantity'))->toBe(2)->and($inventory->where('custody_level', 'POLICY')->sum('quantity'))->toBe(1);
});

it('lets an agent return stickers up the chain, and rejects or cancels pending handovers back to the giver', function () {
    $f = b7dStickerChain();
    $h = tenantHeaderFor($f['tenant']);
    DB::table('sticker_stock')->whereIn('serial_number', ['STK-1', 'STK-2'])->update(['custody_level' => 'AGENT', 'custodian_tenant_id' => $f['tenant']->id, 'custodian_user_id' => $f['agent']->id]);

    Passport::actingAs($f['agent2']);
    b7dHandover($f, ['level' => 'AGENT', 'user_id' => $f['agent']->id], ['level' => 'BRANCH', 'branch_id' => $f['branch']], ['STK-1'])->assertForbidden();

    Passport::actingAs($f['agent']);
    $ret = b7dHandover($f, ['level' => 'AGENT', 'user_id' => $f['agent']->id], ['level' => 'BRANCH', 'branch_id' => $f['branch']], ['STK-1'])->assertCreated()
        ->assertJsonPath('data.direction', 'RETURN')->json('data.id');
    Passport::actingAs($f['clerk']);
    $this->postJson("/api/v1/sticker-handovers/{$ret}/reject", ['reason' => 'Count mismatch'], $h)->assertOk()->assertJsonPath('data.status', 'REJECTED');
    expect(StickerStock::where('serial_number', 'STK-1')->value('status'))->toBe('IN_STOCK')
        ->and(StickerStock::where('serial_number', 'STK-1')->value('custodian_user_id'))->toBe($f['agent']->id);

    Passport::actingAs($f['agent']);
    $again = b7dHandover($f, ['level' => 'AGENT', 'user_id' => $f['agent']->id], ['level' => 'BRANCH', 'branch_id' => $f['branch']], ['STK-1'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/sticker-handovers/{$again}/cancel", ['reason' => 'Wrong branch'], $h)->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    $this->postJson("/api/v1/sticker-handovers/{$again}/accept", [], $h)->assertStatus(422);
});

it('reconciles a physical count against the ledger and flags missing and damaged stickers', function () {
    $f = b7dStickerChain();
    $h = tenantHeaderFor($f['tenant']);
    DB::table('sticker_stock')->whereIn('serial_number', ['STK-1', 'STK-2', 'STK-3'])->update(['custody_level' => 'BRANCH', 'custodian_tenant_id' => $f['tenant']->id, 'custodian_branch_id' => $f['branch']]);

    Passport::actingAs($f['agent']);
    $this->postJson('/api/v1/sticker-reconciliations', ['carrier_id' => $f['carrier']->id, 'holder' => ['level' => 'BRANCH', 'branch_id' => $f['branch']], 'counted_serials' => []], $h)->assertForbidden();

    Passport::actingAs($f['clerk']);
    $this->postJson('/api/v1/sticker-reconciliations', ['carrier_id' => $f['carrier']->id, 'holder' => ['level' => 'BRANCH', 'branch_id' => $f['branch']],
        'counted_serials' => ['STK-1', 'STK-2', 'FOREIGN-9'], 'damaged_serials' => ['STK-2']], $h)->assertCreated()
        ->assertJsonPath('data.status', 'DISCREPANCY')->assertJsonPath('data.expected_count', 3)
        ->assertJsonPath('data.missing_serials', ['STK-3'])->assertJsonPath('data.unexpected_serials', ['FOREIGN-9'])->assertJsonPath('data.damaged_serials', ['STK-2']);
    expect(StickerStock::where('serial_number', 'STK-3')->value('status'))->toBe('MISSING')
        ->and(StickerStock::where('serial_number', 'STK-2')->value('status'))->toBe('DAMAGED')
        ->and(DB::table('sticker_custody_events')->where('event_type', 'RECONCILED_MISSING')->count())->toBe(1);

    $this->postJson('/api/v1/sticker-reconciliations', ['carrier_id' => $f['carrier']->id, 'holder' => ['level' => 'BRANCH', 'branch_id' => $f['branch']],
        'counted_serials' => ['STK-1']], $h)->assertCreated()->assertJsonPath('data.status', 'BALANCED');
});

it('refuses a sticker for a non-motor policy', function () {
    $f = b7dStickerChain();
    $f['quote']->update(['line_code' => 'TRAVEL']);
    DB::table('sticker_stock')->where('serial_number', 'STK-1')->update(['custody_level' => 'BROKER', 'custodian_tenant_id' => $f['tenant']->id]);
    Passport::actingAs($f['admin']);
    $this->postJson("/api/v1/policies/{$f['policy']->id}/sticker", ['serial_number' => 'STK-1'], tenantHeaderFor($f['tenant']))->assertStatus(422)->assertJsonValidationErrors('policy_id');
});
