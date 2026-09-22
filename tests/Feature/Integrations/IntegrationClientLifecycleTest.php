<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/helpers.php';

it('registers a client in DRAFT status with a real oauth client behind it', function () {
    $actor = User::factory()->create();
    $client = registerTestIntegrationClient($actor);

    expect($client->status)->toBe('DRAFT');
    expect(DB::table('oauth_clients')->where('id', $client->oauth_client_id)->exists())->toBeTrue();
});

it('walks the full forward lifecycle in order and refuses to skip a stage', function () {
    $actor = User::factory()->create();
    $client = activateTestIntegrationClient($actor);

    expect($client->status)->toBe('ACTIVE');
    expect($client->activated_at)->not->toBeNull();
    expect($client->certified_at)->not->toBeNull();

    $service = app(App\Application\Integrations\IntegrationClientLifecycleService::class);
    expect(fn () => $service->advance($client, 'PROGRESSED', null, $actor))->toThrow(ValidationException::class);
});

it('suspends and reinstates a connection, and records both transitions in history', function () {
    $actor = User::factory()->create();
    $client = registerTestIntegrationClient($actor);
    $service = app(App\Application\Integrations\IntegrationClientLifecycleService::class);

    $client = $service->suspend($client, 'SECURITY_REVIEW', 'temporary hold', $actor);
    expect($client->status)->toBe('SUSPENDED');

    $client = $service->reinstate($client, 'cleared', $actor);
    expect($client->status)->toBe('ACTIVE');

    $history = DB::table('integration_client_status_history')->where('integration_client_id', $client->id)->orderBy('occurred_at')->get();
    expect($history)->toHaveCount(3); // REGISTERED, SUSPENDED, REINSTATED
});

it('revokes a connection permanently and refuses any further transition', function () {
    $actor = User::factory()->create();
    $client = registerTestIntegrationClient($actor);
    $service = app(App\Application\Integrations\IntegrationClientLifecycleService::class);

    $client = $service->revoke($client, 'PARTNER_OFFBOARDED', 'contract ended', $actor);

    expect($client->status)->toBe('REVOKED');
    expect($client->revoked_at)->not->toBeNull();
    expect(fn () => $service->reinstate($client, 'try again', $actor))->toThrow(ValidationException::class);
    expect(fn () => $service->suspend($client, 'x', 'y', $actor))->toThrow(ValidationException::class);
});

it('writes an audit_log row for every status transition', function () {
    $actor = User::factory()->create();
    $client = registerTestIntegrationClient($actor);
    $service = app(App\Application\Integrations\IntegrationClientLifecycleService::class);

    $service->advance($client, 'PROGRESSED', null, $actor);

    expect(DB::table('audit_log')->where('action', 'integration.client.status_changed')->where('subject_id', $client->id)->exists())->toBeTrue();
});
