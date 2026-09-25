<?php

declare(strict_types=1);

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

it('REQ-POL-010 opens, settles, waives and approves a recovery over HTTP', function () {
    $f = w1Policy();
    $t = $f['tenant'];
    $pid = $f['policy']->id;
    $f['policy']->update(['status' => 'SUSPENDED']);
    $i1 = w1Instalment($t->id, $pid, 'DEFAULTED', 40000);
    $i2 = w1Instalment($t->id, $pid, 'OVERDUE', 10000);
    $maker = w1Staff($t, ['policy.recovery.request']);
    $checker = w1Staff($t, ['policy.recovery.approve', 'policy.premium.waive']);

    Passport::actingAs($maker);
    $case = $this->postJson("/api/v1/policies/{$pid}/recovery-cases", ['reason_code' => 'CUSTOMER_PAID'], w1Headers($t))->assertCreated()->json('data');
    expect((int) $case['arrears_minor'])->toBe(50000);
    $this->postJson("/api/v1/policy-premium-instalments/{$i1}/settle", ['amount_minor' => 40000], w1Headers($t))->assertOk()->assertJsonPath('data.status', 'PAID');
    $this->getJson("/api/v1/policy-recovery-cases/{$case['id']}", w1Headers($t))->assertOk()->assertJsonPath('data.status', 'OPEN');

    Passport::actingAs($checker);
    expect($this->getJson('/api/v1/policy-recovery-cases', w1Headers($t))->assertOk()->json('data.0.id'))->toBe($case['id']);
    $this->postJson("/api/v1/policy-recovery-cases/{$case['id']}/approve", [], w1Headers($t))->assertStatus(422);
    $this->postJson("/api/v1/policy-premium-instalments/{$i2}/waive", ['reason' => 'Goodwill'], w1Headers($t))->assertOk()->assertJsonPath('data.status', 'WAIVED');
    $this->postJson("/api/v1/policy-recovery-cases/{$case['id']}/approve", [], w1Headers($t))->assertOk()->assertJsonPath('data.status', 'ACTIVE');
});

it('REQ-POL-010 enforces permissions and tenant isolation on recovery routes; rejects over HTTP', function () {
    $f = w1Policy();
    $t = $f['tenant'];
    $pid = $f['policy']->id;
    $f['policy']->update(['status' => 'SUSPENDED']);
    $inst = w1Instalment($t->id, $pid);

    Passport::actingAs(w1Staff($t, ['policy.recovery.approve']));
    $this->postJson("/api/v1/policies/{$pid}/recovery-cases", ['reason_code' => 'X'], w1Headers($t))->assertStatus(403);
    $this->postJson("/api/v1/policy-premium-instalments/{$inst}/waive", ['reason' => 'x'], w1Headers($t))->assertStatus(403);

    Passport::actingAs(w1Staff($t, ['policy.recovery.request']));
    $case = $this->postJson("/api/v1/policies/{$pid}/recovery-cases", ['reason_code' => 'X'], w1Headers($t))->assertCreated()->json('data');
    $this->postJson("/api/v1/policy-recovery-cases/{$case['id']}/approve", [], w1Headers($t))->assertStatus(403);

    $other = w1Policy();
    Passport::actingAs(w1Staff($other['tenant'], ['policy.recovery.request', 'policy.recovery.approve', 'policy.premium.waive']));
    $h = w1Headers($other['tenant']);
    $this->getJson("/api/v1/policy-recovery-cases/{$case['id']}", $h)->assertStatus(404);
    $this->postJson("/api/v1/policy-recovery-cases/{$case['id']}/reject", ['reason' => 'x'], $h)->assertStatus(404);
    $this->postJson("/api/v1/policy-premium-instalments/{$inst}/settle", ['amount_minor' => 100], $h)->assertStatus(404);
    $this->postJson("/api/v1/policy-premium-instalments/{$inst}/waive", ['reason' => 'x'], $h)->assertStatus(404);
    $this->postJson("/api/v1/policies/{$pid}/recovery-cases", ['reason_code' => 'X'], $h)->assertStatus(404);
    expect($this->getJson('/api/v1/policy-recovery-cases', $h)->assertOk()->json('data'))->toBe([])
        ->and(DB::table('policy_premium_instalments')->find($inst)->paid_minor)->toBe(0);

    Passport::actingAs(w1Staff($t, ['policy.recovery.approve']));
    $this->postJson("/api/v1/policy-recovery-cases/{$case['id']}/reject", ['reason' => 'Withdrawn'], w1Headers($t))->assertOk()->assertJsonPath('data.status', 'REJECTED');
});

it('REQ-POL-010 servicing REINSTATEMENT of a LAPSED policy points to recovery with a 422', function () {
    $f = w1Policy();
    $t = $f['tenant'];
    $f['policy']->update(['status' => 'LAPSED']);
    Passport::actingAs(w1Staff($t, ['policies.service.approve']));
    $res = $this->postJson("/api/v1/policies/{$f['policy']->id}/transactions", [
        'type' => 'REINSTATEMENT', 'effective_at' => now()->toDateString(), 'premium_delta_minor' => 0, 'reason_code' => 'LATE_PAYMENT',
    ], w1Headers($t))->assertStatus(422);
    expect($res->json('errors.status.0'))->toContain('RECOVERY_REQUIRED');
});
