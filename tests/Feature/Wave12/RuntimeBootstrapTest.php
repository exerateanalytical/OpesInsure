<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the full runtime bootstrap shape without requiring authentication', function () {
    config(['mobile_runtime.release.minimum_version' => '1.0.0', 'mobile_runtime.release.latest_version' => '1.2.0']);

    $response = $this->getJson('/api/v1/mobile/runtime/bootstrap?version=1.2.0&build=142&channel=production');

    $response->assertStatus(200);
    expect($response->json('data.release.minimum_version'))->toBe('1.0.0');
    expect($response->json('data.release.force_update'))->toBeFalse();
    expect($response->json('data.maintenance.active'))->toBeFalse();
    expect($response->json('data.services'))->toBeArray();
    expect(collect($response->json('data.services'))->pluck('key')->all())->toEqualCanonicalizing(['LARAVEL_API', 'MTN_MOMO', 'ORANGE_MONEY', 'CARRIER_GATEWAY', 'SMS']);
    expect($response->json('data.security.step_up_ttl_seconds'))->toBeInt();
    expect($response->json('data.security.device_risk_action'))->toBe('ALLOW');
});

it('reports force_update true only when the client version is below the configured minimum', function () {
    config(['mobile_runtime.release.minimum_version' => '1.2.0', 'mobile_runtime.release.latest_version' => '1.3.0']);

    $old = $this->getJson('/api/v1/mobile/runtime/bootstrap?version=1.0.0&build=1&channel=production');
    $current = $this->getJson('/api/v1/mobile/runtime/bootstrap?version=1.2.0&build=1&channel=production');

    expect($old->json('data.release.force_update'))->toBeTrue();
    expect($current->json('data.release.force_update'))->toBeFalse();
});

it('reports update_recommended for a version between minimum and latest, never alongside force_update', function () {
    config(['mobile_runtime.release.minimum_version' => '1.0.0', 'mobile_runtime.release.latest_version' => '1.3.0']);

    $response = $this->getJson('/api/v1/mobile/runtime/bootstrap?version=1.1.0&build=1&channel=production');

    expect($response->json('data.release.force_update'))->toBeFalse();
    expect($response->json('data.release.update_recommended'))->toBeTrue();
});

it('does not force or recommend an update when the client omits its version', function () {
    config(['mobile_runtime.release.minimum_version' => '1.2.0']);

    $response = $this->getJson('/api/v1/mobile/runtime/bootstrap');

    $response->assertStatus(200);
    expect($response->json('data.release.force_update'))->toBeFalse();
    expect($response->json('data.release.update_recommended'))->toBeFalse();
});

it('surfaces the configured maintenance window verbatim, never letting the client override it', function () {
    config([
        'mobile_runtime.maintenance.active' => true,
        'mobile_runtime.maintenance.message' => 'Scheduled maintenance in progress.',
        'mobile_runtime.maintenance.ends_at' => '2026-10-01T02:00:00Z',
    ]);

    $response = $this->getJson('/api/v1/mobile/runtime/bootstrap');

    expect($response->json('data.maintenance.active'))->toBeTrue();
    expect($response->json('data.maintenance.message'))->toBe('Scheduled maintenance in progress.');
    expect($response->json('data.maintenance.ends_at'))->toBe('2026-10-01T02:00:00Z');
});

it('rejects a request whose X-App-Version header is below the configured minimum', function () {
    config(['mobile_runtime.release.minimum_version' => '1.2.0']);

    $response = $this->getJson('/api/v1/public/capabilities', ['X-App-Version' => '1.0.0']);

    $response->assertStatus(426);
    expect($response->json('code'))->toBe('APP_VERSION_UNSUPPORTED');
});

it('allows requests that omit X-App-Version or meet the minimum', function () {
    config(['mobile_runtime.release.minimum_version' => '1.2.0']);

    $this->getJson('/api/v1/public/capabilities')->assertStatus(200);
    $this->getJson('/api/v1/public/capabilities', ['X-App-Version' => '1.2.0'])->assertStatus(200);
    $this->getJson('/api/v1/public/capabilities', ['X-App-Version' => '2.0.0'])->assertStatus(200);
});
