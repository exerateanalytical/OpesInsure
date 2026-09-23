<?php

declare(strict_types=1);

use App\Models\TelemetryEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

if (! function_exists('telemetryPayload')) {
    function telemetryPayload(array $overrides = []): array
    {
        return array_merge([
            'event' => 'APP_CRASHED',
            'correlation_id' => (string) Str::uuid(),
            'app_version' => '1.2.0',
            'release_channel' => 'production',
            'attributes' => ['screen' => 'PaymentDetails', 'error_code' => 'NETWORK_TIMEOUT'],
        ], $overrides);
    }
}

it('accepts an allowlisted event with allowlisted attributes, without requiring authentication', function () {
    $response = $this->postJson('/api/v1/mobile/runtime/telemetry', telemetryPayload(), ['Idempotency-Key' => (string) Str::uuid()]);

    $response->assertStatus(201);
    expect($response->json('data.accepted'))->toBeTrue();
    expect(TelemetryEvent::count())->toBe(1);

    $event = TelemetryEvent::first();
    expect($event->event_name)->toBe('APP_CRASHED');
    expect($event->attributes)->toBe(['screen' => 'PaymentDetails', 'error_code' => 'NETWORK_TIMEOUT']);
});

it('rejects an event name outside the allowlist', function () {
    $response = $this->postJson('/api/v1/mobile/runtime/telemetry', telemetryPayload(['event' => 'USER_DELETED_ACCOUNT']), ['Idempotency-Key' => (string) Str::uuid()]);

    $response->assertStatus(422);
    expect(TelemetryEvent::count())->toBe(0);
});

it('rejects an attribute key outside the allowlist instead of silently dropping it', function () {
    $response = $this->postJson('/api/v1/mobile/runtime/telemetry', telemetryPayload(['attributes' => ['email' => 'someone@example.com']]), ['Idempotency-Key' => (string) Str::uuid()]);

    $response->assertStatus(422);
    expect(TelemetryEvent::count())->toBe(0);
});

it('rejects a nested/non-scalar attribute value', function () {
    $response = $this->postJson('/api/v1/mobile/runtime/telemetry', telemetryPayload(['attributes' => ['screen' => ['nested' => 'object']]]), ['Idempotency-Key' => (string) Str::uuid()]);

    $response->assertStatus(422);
    expect(TelemetryEvent::count())->toBe(0);
});

it('requires an Idempotency-Key header', function () {
    $response = $this->postJson('/api/v1/mobile/runtime/telemetry', telemetryPayload());

    $response->assertStatus(422);
    expect(TelemetryEvent::count())->toBe(0);
});

it('replays the same response for a repeated Idempotency-Key instead of recording a duplicate event', function () {
    $key = (string) Str::uuid();
    $payload = telemetryPayload();

    $first = $this->postJson('/api/v1/mobile/runtime/telemetry', $payload, ['Idempotency-Key' => $key]);
    $second = $this->postJson('/api/v1/mobile/runtime/telemetry', $payload, ['Idempotency-Key' => $key]);

    $first->assertStatus(201);
    $second->assertStatus(201);
    expect($second->headers->get('X-Idempotent-Replay'))->toBe('true');
    expect(TelemetryEvent::count())->toBe(1);
});

it('truncates an allowlisted attribute value instead of rejecting an unusually long one', function () {
    $response = $this->postJson('/api/v1/mobile/runtime/telemetry', telemetryPayload(['attributes' => ['reason' => str_repeat('x', 500)]]), ['Idempotency-Key' => (string) Str::uuid()]);

    $response->assertStatus(201);
    expect(strlen(TelemetryEvent::first()->attributes['reason']))->toBe(200);
});
