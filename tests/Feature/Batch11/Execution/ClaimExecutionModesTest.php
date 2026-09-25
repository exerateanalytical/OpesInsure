<?php

declare(strict_types=1);

use App\Application\Claims\Execution\ApiSynchronizedClaimExecution;
use App\Application\Claims\Execution\BrokerAssistedClaimExecution;
use App\Application\Claims\Execution\ClaimCarrierSignatureVerifier;
use App\Application\Claims\Execution\ClaimExecutionRegistry;
use App\Application\Claims\Execution\ClaimExecutionService;
use App\Application\Claims\Execution\FullyDigitalClaimExecution;
use App\Application\Claims\Execution\InsurerPortalClaimExecution;
use App\Application\Claims\Execution\ManualCarrierClaimExecution;
use App\Application\Distribution\Execution\ExecutionOutcome;
use App\Application\Distribution\Execution\ExecutionPlanner;
use App\Models\Claim;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function c11User(): User
{
    return User::create(['full_name' => 'C11 '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function c11Staff(Tenant $t, array $perms): User
{
    $u = c11User();
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'CLM', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'CLM_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

function c11Profile(string $carrierId, string $mode, string $exec): string
{
    $id = (string) Str::uuid();
    DB::table('carrier_capability_profiles')->where('carrier_id', $carrierId)->update(['status' => 'SUPERSEDED']);
    $version = (int) DB::table('carrier_capability_profiles')->where('carrier_id', $carrierId)->max('version') + 1;
    DB::table('carrier_capability_profiles')->insert(['id' => $id, 'carrier_id' => $carrierId, 'version' => $version, 'status' => 'ACTIVE', 'effective_from' => now()->subDay(),
        'created_by' => c11User()->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_capability_modes')->insert(['id' => (string) Str::uuid(), 'profile_id' => $id, 'capability' => 'CLAIMS_INTAKE', 'mode' => $mode, 'execution_mode' => $exec,
        'config' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** A fixture whose carrier runs claims in $mode, with one claim created after the profile. */
function c11Claim(string $mode, string $exec): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    c11Profile($f['carrier']->id, $mode, $exec);
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);

    return $f + ['claim' => makeMobileTestClaim($f['tenant'], $policy, $f['party'])];
}

function c11Sign(string $secret, string $body, ?int $ts = null): array
{
    $ts ??= time();

    return ['X-Carrier-Timestamp' => (string) $ts, 'X-Carrier-Signature' => hash_hmac('sha256', $ts.'.'.$body, $secret)];
}

it('REQ-CLM-011 maps every claims mode to an adapter on the claims execution port', function () {
    $registry = app(ClaimExecutionRegistry::class);
    foreach ([
        'BROKER_ASSISTED' => [BrokerAssistedClaimExecution::class, 'MANUAL'], 'MANUAL_CARRIER' => [ManualCarrierClaimExecution::class, 'MANUAL'],
        'INSURER_PORTAL' => [InsurerPortalClaimExecution::class, 'MANUAL'], 'API_SYNCHRONIZED' => [ApiSynchronizedClaimExecution::class, 'REMOTE_API'],
        'FULLY_DIGITAL' => [FullyDigitalClaimExecution::class, 'CONFIGURED'],
    ] as $mode => [$class, $exec]) {
        $a = $registry->forClaimsMode($mode);
        expect($a)->toBeInstanceOf($class)->and($a->executionMode())->toBe($exec)->and($a->capability())->toBe('CLAIMS_INTAKE')->and($a->claimsMode())->toBe($mode);
    }
    expect(ExecutionPlanner::PORTS)->toHaveKey('claims')->and(app(ExecutionPlanner::class)->registry('claims'))->toBeInstanceOf(ClaimExecutionRegistry::class);
});

it('REQ-CLM-011 pins the claims mode per claim so a later profile change does not re-route it', function () {
    $f = c11Claim('MANUAL_CARRIER', 'MANUAL');
    $svc = app(ClaimExecutionService::class);
    expect($svc->mode($f['claim']))->toBe('MANUAL_CARRIER')
        ->and($svc->execution($f['claim'])->status)->toBe(ExecutionOutcome::AWAITING_CARRIER);

    c11Profile($f['carrier']->id, 'API_SYNCHRONIZED', 'REMOTE_API');
    expect($svc->mode($f['claim']->fresh()))->toBe('MANUAL_CARRIER');
    $new = makeMobileTestClaim($f['tenant'], $f['claim']->policy, $f['party']);
    expect($svc->mode($new))->toBe('API_SYNCHRONIZED')->and($svc->execution($new)->status)->toBe(ExecutionOutcome::INTEGRATION_UNAVAILABLE);
});

it('REQ-CLM-011 submits a claim to the carrier and records a maker-checker manual acknowledgement and decision', function () {
    $f = c11Claim('MANUAL_CARRIER', 'MANUAL');
    $h = tenantHeaderFor($f['tenant']);
    $maker = c11Staff($f['tenant'], ['claims.view', 'claims.carrier.exchange', 'claims.carrier.manual_entry']);
    $checker = c11Staff($f['tenant'], ['claims.carrier.manual_approve']);
    $id = $f['claim']->id;

    Passport::actingAs($maker);
    $sub = $this->postJson("/api/v1/claims/$id/execution/submit", ['idempotency_key' => str_repeat('k', 20)], $h)->assertStatus(202)
        ->assertJsonPath('data.mode', 'MANUAL_CARRIER')->assertJsonPath('data.message.message_type', 'CLAIM_SUBMISSION')->assertJsonPath('data.message.channel', 'MANUAL_CARRIER');
    $this->postJson("/api/v1/claims/$id/execution/submit", ['idempotency_key' => str_repeat('k', 20)], $h)->assertStatus(202)->assertJsonPath('data.message.id', $sub->json('data.message.id'));

    // Signed API channel is refused for a manual-carrier claim.
    $this->postJson("/api/v1/claims/$id/carrier-messages/manual", ['message_type' => 'CLAIM_ACKNOWLEDGEMENT'], $h)->assertStatus(422)->assertJsonValidationErrors('external_reference');
    $entry = $this->postJson("/api/v1/claims/$id/carrier-messages/manual", ['message_type' => 'CLAIM_ACKNOWLEDGEMENT', 'external_reference' => 'CAR-777'], $h)
        ->assertCreated()->assertJsonPath('data.status', 'PENDING_APPROVAL')->json('data.id');
    expect(Claim::find($id)->carrier_reference)->toBeNull();

    // The maker cannot approve their own entry, even holding the permission.
    expect(fn () => app(ClaimExecutionService::class)->reviewManualEntry(Claim::find($id), $entry, true, $maker))->toThrow(\Illuminate\Validation\ValidationException::class);
    $this->postJson("/api/v1/claims/$id/carrier-messages/manual/$entry/approve", [], $h)->assertForbidden();

    Passport::actingAs($checker);
    $this->postJson("/api/v1/claims/$id/carrier-messages/manual/$entry/approve", [], $h)->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $this->postJson("/api/v1/claims/$id/carrier-messages/manual/$entry/approve", [], $h)->assertStatus(422);
    $claim = Claim::find($id);
    expect($claim->carrier_reference)->toBe('CAR-777')->and($claim->acknowledged_at)->not->toBeNull()
        ->and(DB::table('carrier_exchange_messages')->where('id', $sub->json('data.message.id'))->value('status'))->toBe('ACKNOWLEDGED')
        ->and(DB::table('carrier_exchange_messages')->where(['claim_id' => $id, 'direction' => 'INBOUND', 'channel' => 'MANUAL_ENTRY'])->count())->toBe(1);

    Passport::actingAs($maker);
    $decision = $this->postJson("/api/v1/claims/$id/carrier-messages/manual", ['message_type' => 'CLAIM_DECISION', 'decision' => ['outcome' => 'PARTIALLY_APPROVED', 'amount_minor' => 50000, 'currency' => 'XAF']], $h)->json('data.id');
    Passport::actingAs($checker);
    $this->postJson("/api/v1/claims/$id/carrier-messages/manual/$decision/reject", ['reason' => 'Amount mistyped'], $h)->assertOk()->assertJsonPath('data.status', 'REJECTED');
    expect(DB::table('outbox_messages')->where(['event_name' => 'claim.carrier_decision.received', 'aggregate_id' => $id])->exists())->toBeFalse()
        ->and(DB::table('outbox_messages')->where(['event_name' => 'claim.carrier_entry.rejected', 'aggregate_id' => $id])->exists())->toBeTrue();
});

it('REQ-CLM-011 accepts only correctly signed carrier messages for API_SYNCHRONIZED claims, idempotently', function () {
    $f = c11Claim('API_SYNCHRONIZED', 'REMOTE_API');
    $h = tenantHeaderFor($f['tenant']);
    $carrierSystem = c11Staff($f['tenant'], ['claims.carrier.callback', 'claims.carrier.manual_entry', 'claims.carrier.keys', 'claims.view']);
    Passport::actingAs($carrierSystem);
    $key = $this->postJson("/api/v1/carriers/{$f['carrier']->id}/claims-signing-keys", ['key_id' => 'carrier-key-1'], $h)->assertCreated()->json('data');
    $id = $f['claim']->id;
    $body = json_encode(['event_id' => 'evt-000000001', 'message_type' => 'CLAIM_DECISION', 'external_reference' => 'API-9', 'decision' => ['outcome' => 'APPROVED', 'amount_minor' => 120000, 'currency' => 'XAF']]);
    $send = fn (string $b, array $sig) => $this->call('POST', "/api/v1/claims/$id/carrier-messages/inbound", [], [], [], $this->transformHeadersToServerVars($h + $sig + ['X-Carrier-Key-Id' => $key['key_id'], 'Content-Type' => 'application/json', 'Accept' => 'application/json']), $b);

    $send($body, c11Sign('wrong-secret', $body))->assertStatus(422)->assertJsonValidationErrors('signature');
    $send($body, c11Sign($key['secret'], $body, time() - 3600))->assertStatus(422);
    $send($body, c11Sign($key['secret'], $body))->assertCreated()->assertJsonPath('data.channel', 'SIGNED_API')->assertJsonPath('data.signature_key_id', 'carrier-key-1');
    $send($body, c11Sign($key['secret'], $body))->assertOk()->assertJsonPath('meta.replayed', true);
    $other = json_encode(['event_id' => 'evt-000000001', 'message_type' => 'CLAIM_DECISION', 'decision' => ['outcome' => 'REJECTED']]);
    $send($other, c11Sign($key['secret'], $other))->assertStatus(422);
    expect(DB::table('outbox_messages')->where(['event_name' => 'claim.carrier_decision.received', 'aggregate_id' => $id])->count())->toBe(1);

    // Manual entry is not a channel of an API_SYNCHRONIZED claim; a revoked key stops working.
    $this->postJson("/api/v1/claims/$id/carrier-messages/manual", ['message_type' => 'CLAIM_ACKNOWLEDGEMENT', 'external_reference' => 'X'], $h)->assertStatus(422)->assertJsonValidationErrors('mode');
    $this->postJson("/api/v1/carriers/{$f['carrier']->id}/claims-signing-keys/{$key['key_id']}/revoke", [], $h)->assertOk();
    $b2 = json_encode(['event_id' => 'evt-000000002', 'message_type' => 'CLAIM_ACKNOWLEDGEMENT', 'external_reference' => 'API-9']);
    $send($b2, c11Sign($key['secret'], $b2))->assertStatus(422);
});

it('REQ-CLM-011 keeps FULLY_DIGITAL claims on the platform and scopes the execution view by tenant', function () {
    $f = c11Claim('FULLY_DIGITAL', 'CONFIGURED');
    $h = tenantHeaderFor($f['tenant']);
    Passport::actingAs(c11Staff($f['tenant'], ['claims.view', 'claims.carrier.exchange']));
    $id = $f['claim']->id;
    $this->getJson("/api/v1/claims/$id/execution", $h)->assertOk()->assertJsonPath('data.mode', 'FULLY_DIGITAL')
        ->assertJsonPath('data.execution.status', 'HANDLED_BY_PLATFORM')->assertJsonPath('data.semantics.outbound_submission', false);
    $this->postJson("/api/v1/claims/$id/execution/submit", ['idempotency_key' => str_repeat('d', 20)], $h)->assertStatus(422)->assertJsonValidationErrors('mode');

    $other = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    Passport::actingAs(c11Staff($other['tenant'], ['claims.view']));
    $this->getJson("/api/v1/claims/$id/execution", tenantHeaderFor($other['tenant']))->assertNotFound();
});
