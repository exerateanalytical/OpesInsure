<?php

declare(strict_types=1);

use App\Application\Policies\Lapse\PolicySuspender;
use App\Application\Policies\Lapse\SuspensionServicePolicySuspender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-POL-006 suspends, queues, rejects and reinstates over HTTP with maker-checker', function () {
    $f = w1Policy();
    $t = $f['tenant'];
    $pid = $f['policy']->id;
    $maker = w1Staff($t, ['policies.suspend', 'policies.reinstatement.request']);
    $checker = w1Staff($t, ['policies.reinstatement.approve']);

    Passport::actingAs($maker);
    $this->postJson("/api/v1/policies/{$pid}/suspend", ['reason_code' => 'CUSTOMER_REQUEST'], w1Headers($t))
        ->assertCreated()->assertJsonPath('data.status', 'SUSPENDED')->assertJsonPath('data.source', 'MANUAL');
    $this->postJson("/api/v1/policies/{$pid}/reinstatement-requests", ['reason_code' => 'VEHICLE_BACK'], w1Headers($t))
        ->assertCreated()->assertJsonPath('data.status', 'REINSTATEMENT_REQUESTED');

    Passport::actingAs($checker);
    expect($this->getJson('/api/v1/policy-reinstatement-queue', w1Headers($t))->assertOk()->json('data.0.policy_id'))->toBe($pid);
    $this->postJson("/api/v1/policies/{$pid}/reinstatement-requests/reject", ['reason' => 'Proof missing'], w1Headers($t))
        ->assertOk()->assertJsonPath('data.status', 'SUSPENDED');

    // Second policy in the same tenant goes through to reinstatement (the checker is a different user).
    $p2 = w1Policy()['policy'];
    $p2->update(['tenant_id' => $t->id]);
    Passport::actingAs($maker);
    $this->postJson("/api/v1/policies/{$p2->id}/suspend", ['reason_code' => 'CUSTOMER_REQUEST'], w1Headers($t))->assertCreated();
    $this->postJson("/api/v1/policies/{$p2->id}/reinstatement-requests", ['reason_code' => 'VEHICLE_BACK'], w1Headers($t))->assertCreated();
    $this->postJson("/api/v1/policies/{$p2->id}/reinstate", ['reason_code' => 'VEHICLE_BACK'], w1Headers($t))->assertStatus(403);
    Passport::actingAs($checker);
    $this->postJson("/api/v1/policies/{$p2->id}/reinstate", ['reason_code' => 'VEHICLE_BACK'], w1Headers($t))
        ->assertOk()->assertJsonPath('data.status', 'ACTIVE');
});

it('REQ-POL-006 enforces permissions and tenant isolation on suspension routes', function () {
    $f = w1Policy();
    $t = $f['tenant'];
    $pid = $f['policy']->id;

    Passport::actingAs(w1Staff($t, ['policies.reinstatement.request']));
    $this->postJson("/api/v1/policies/{$pid}/suspend", ['reason_code' => 'X'], w1Headers($t))->assertStatus(403);
    $this->getJson('/api/v1/policy-reinstatement-queue', w1Headers($t))->assertStatus(403);
    $this->postJson("/api/v1/policies/{$pid}/reinstate", ['reason_code' => 'X'], w1Headers($t))->assertStatus(403);

    $other = w1Policy();
    Passport::actingAs(w1Staff($other['tenant'], ['policies.suspend', 'policies.reinstatement.approve']));
    $this->postJson("/api/v1/policies/{$pid}/suspend", ['reason_code' => 'X'], w1Headers($other['tenant']))->assertStatus(404);
    expect($f['policy']->refresh()->status)->toBe('ACTIVE');

    Passport::actingAs(w1Staff($t, ['policies.suspend']));
    $this->postJson("/api/v1/policies/{$pid}/suspend", ['reason_code' => 'X'], w1Headers($t))->assertCreated();
    Passport::actingAs(w1Staff($other['tenant'], ['policies.reinstatement.approve']));
    expect($this->getJson('/api/v1/policy-reinstatement-queue', w1Headers($other['tenant']))->assertOk()->json('data'))->toBe([]);
});

it('routes premium-default suspension through the single suspension path and keeps policy.premium.suspended', function () {
    $f = w1Policy();
    $pid = $f['policy']->id;
    $suspender = app(PolicySuspender::class);
    expect($suspender)->toBeInstanceOf(SuspensionServicePolicySuspender::class);

    expect($suspender->suspend($pid, 'PREMIUM_DEFAULT', ['instalment_id' => 'i-1']))->toBeTrue()
        ->and($suspender->suspend($pid, 'PREMIUM_DEFAULT'))->toBeFalse();
    $s = DB::table('policy_suspensions')->where('policy_id', $pid)->first();
    expect($s->source)->toBe('PREMIUM_DEFAULT')->and($s->status)->toBe('SUSPENDED')->and($s->suspended_by)->toBeNull()
        ->and($f['policy']->refresh()->status)->toBe('SUSPENDED')
        ->and(DB::table('outbox_messages')->where('aggregate_id', $pid)->where('event_name', 'policy.premium.suspended')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('aggregate_id', $pid)->where('event_name', 'policy.suspended')->count())->toBe(1);
});
