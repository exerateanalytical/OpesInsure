<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-CAN-001 exposes preview → request → queue → review → approve over HTTP with maker-checker', function () {
    $f = w1Policy();
    $t = $f['tenant'];
    $pid = $f['policy']->id;
    $maker = w1Staff($t, ['policies.cancellation.request']);
    $checker = w1Staff($t, ['policies.cancellation.review', 'policies.cancellation.approve']);
    $body = ['effective_at' => now()->addDays(30)->toDateString(), 'reason_code' => 'INSURED_REQUEST', 'initiated_by' => 'INSURED'];

    Passport::actingAs($maker);
    $preview = $this->postJson("/api/v1/policies/{$pid}/cancellations/preview", $body, w1Headers($t))->assertOk()->json('data');
    expect($preview['refund_minor'])->toBeGreaterThan(0);
    $case = $this->postJson("/api/v1/policies/{$pid}/cancellations", $body, w1Headers($t))->assertCreated()->json('data');
    expect($case['status'])->toBe('REQUESTED');

    Passport::actingAs($checker);
    expect($this->getJson('/api/v1/policy-cancellations', w1Headers($t))->assertOk()->json('data.0.id'))->toBe($case['id']);
    $this->postJson("/api/v1/policy-cancellations/{$case['id']}/review", ['note' => 'ok'], w1Headers($t))->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW');
    $this->postJson("/api/v1/policy-cancellations/{$case['id']}/approve", [], w1Headers($t))->assertOk()->assertJsonPath('data.status', 'APPROVED');
    expect($f['policy']->refresh()->status)->toBe('CANCELLED');
});

it('REQ-CAN-001 rejects over HTTP; enforces permissions and tenant isolation', function () {
    $f = w1Policy();
    $t = $f['tenant'];
    $pid = $f['policy']->id;
    $body = ['effective_at' => now()->addDays(30)->toDateString(), 'reason_code' => 'INSURED_REQUEST', 'initiated_by' => 'INSURED'];

    Passport::actingAs(w1Staff($t, ['policies.cancellation.review']));
    $this->postJson("/api/v1/policies/{$pid}/cancellations", $body, w1Headers($t))->assertStatus(403);

    Passport::actingAs(w1Staff($t, ['policies.cancellation.request']));
    $case = $this->postJson("/api/v1/policies/{$pid}/cancellations", $body, w1Headers($t))->assertCreated()->json('data');
    $this->getJson('/api/v1/policy-cancellations', w1Headers($t))->assertStatus(403);
    $this->postJson("/api/v1/policy-cancellations/{$case['id']}/approve", [], w1Headers($t))->assertStatus(403);

    $other = w1Policy();
    Passport::actingAs(w1Staff($other['tenant'], ['policies.cancellation.request', 'policies.cancellation.review', 'policies.cancellation.approve']));
    $this->getJson("/api/v1/policy-cancellations/{$case['id']}", w1Headers($other['tenant']))->assertStatus(404);
    $this->postJson("/api/v1/policy-cancellations/{$case['id']}/reject", ['reason' => 'x'], w1Headers($other['tenant']))->assertStatus(404);
    $this->postJson("/api/v1/policies/{$pid}/cancellations/preview", $body, w1Headers($other['tenant']))->assertStatus(404);
    expect($this->getJson('/api/v1/policy-cancellations', w1Headers($other['tenant']))->assertOk()->json('data'))->toBe([]);

    Passport::actingAs(w1Staff($t, ['policies.cancellation.approve']));
    $this->postJson("/api/v1/policy-cancellations/{$case['id']}/reject", ['reason' => 'Customer withdrew'], w1Headers($t))->assertOk()->assertJsonPath('data.status', 'REJECTED');
    expect($f['policy']->refresh()->status)->toBe('ACTIVE');
});
