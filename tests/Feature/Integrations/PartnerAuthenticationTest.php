<?php

declare(strict_types=1);

use App\Models\{IntegrationClient,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client as OAuthClient;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/helpers.php';

function actingAsIntegrationClient(IntegrationClient $client, array $scopes = ['quotes.read']): void
{
    Passport::actingAsClient(OAuthClient::find($client->oauth_client_id), $scopes);
}

it('rejects a request with no bearer token at all with 401', function () {
    $this->getJson('/api/v1/partner/whoami')->assertStatus(401);
});

it('allows an active client to reach whoami, and audits the success', function () {
    $actor = User::factory()->create();
    $client = activateTestIntegrationClient($actor);
    actingAsIntegrationClient($client);

    $this->getJson('/api/v1/partner/whoami')
        ->assertStatus(200)
        ->assertJsonPath('data.client_name', 'Test Connector');

    expect(DB::table('audit_log')->where('action', 'integration.request.allowed')->where('subject_id', $client->id)->exists())->toBeTrue();
});

it('rejects a DRAFT (not yet activated) connection with 403, and audits the denial', function () {
    $actor = User::factory()->create();
    $client = registerTestIntegrationClient($actor); // still DRAFT
    actingAsIntegrationClient($client);

    $this->getJson('/api/v1/partner/whoami')->assertStatus(403);

    expect(DB::table('audit_log')->where('action', 'integration.request.denied')->where('reason_code', 'connection_not_active')->exists())->toBeTrue();
});

it('rejects a request outside the connection\'s IP allowlist with 403', function () {
    $actor = User::factory()->create();
    $client = activateTestIntegrationClient($actor);
    $client->update(['allowed_ips' => ['203.0.113.9']]);
    actingAsIntegrationClient($client);

    $this->getJson('/api/v1/partner/whoami')->assertStatus(403);
});

it('allows a request whose IP is on the connection\'s allowlist', function () {
    $actor = User::factory()->create();
    $client = activateTestIntegrationClient($actor);
    $client->update(['allowed_ips' => ['127.0.0.1']]);
    actingAsIntegrationClient($client);

    $this->getJson('/api/v1/partner/whoami')->assertStatus(200);
});

it('rejects a scope the connection was never granted, regardless of what the token claims', function () {
    $actor = User::factory()->create();
    // integration_clients.scopes only has quotes.read — the domain record is
    // authoritative even if a token somehow carried a broader claim.
    $client = activateTestIntegrationClient($actor, ['scopes' => ['quotes.read']]);
    actingAsIntegrationClient($client, ['quotes.read', 'settlements.read']);

    // whoami requires no specific scope ('*'), so exercise a scope check via
    // the middleware directly against a permission it truly lacks.
    $middleware = new App\Interfaces\Http\Middleware\AuthenticateIntegrationClient;
    $request = Illuminate\Http\Request::create('/api/v1/partner/whoami');
    $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]), 'settlements.read');

    expect($response->getStatusCode())->toBe(403);
});

it('does not authorize a revoked connection even with a token that would otherwise validate', function () {
    $actor = User::factory()->create();
    $client = activateTestIntegrationClient($actor);
    $service = app(App\Application\Integrations\IntegrationClientLifecycleService::class);
    $service->revoke($client, 'OFFBOARDED', 'contract ended', $actor);
    actingAsIntegrationClient($client->refresh());

    $this->getJson('/api/v1/partner/whoami')->assertStatus(403);
});
