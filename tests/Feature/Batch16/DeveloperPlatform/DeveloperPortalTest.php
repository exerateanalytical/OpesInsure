<?php

declare(strict_types=1);

/**
 * Agent B3 — REQ-API-006 developer portal: sandbox keys, usage metering, per-client rate limits,
 * tenant consent / scoped delegated access.
 */

use App\Application\Integrations\IntegrationClientLifecycleService;
use App\Models\IntegrationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client as OAuthClient;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Integrations/Concerns/helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = makeAuthTestTenant();
    $this->h = tenantHeader($this->tenant);
    $this->admin = makeAuthTestUser($this->tenant, ['integrations.manage', 'integrations.revoke', 'integrations.consent.manage']);
});

function b3SandboxClient($actor): IntegrationClient
{
    $svc = app(IntegrationClientLifecycleService::class);
    $client = registerTestIntegrationClient($actor, ['scopes' => ['quotes.read', 'claims.read']]);
    foreach (['TECHNICAL_REVIEW', 'SANDBOX_ENABLED'] as $s) {
        $client = $svc->advance($client, $s, null, $actor);
    }

    return $client;
}

it('issues a sandbox key usable while the connection is only SANDBOX_ENABLED, meters usage and applies the sandbox limit', function () {
    $client = b3SandboxClient($this->admin);
    Passport::actingAs($this->admin, [], 'api');
    $this->putJson("/api/v1/developer/clients/{$client->id}/rate-limits", ['rate_limit_per_minute' => 100, 'sandbox_rate_limit_per_minute' => 2], $this->h)
        ->assertOk()->assertJsonPath('data.sandbox_rate_limit_per_minute', 2);
    $key = $this->postJson("/api/v1/developer/clients/{$client->id}/keys", ['environment' => 'sandbox', 'label' => 'dev'], $this->h)->assertCreated();
    expect($key->json('data.client_secret'))->not->toBeEmpty();
    // a production key is refused before production approval
    $this->postJson("/api/v1/developer/clients/{$client->id}/keys", ['environment' => 'production'], $this->h)->assertUnprocessable();

    app('auth')->forgetGuards();
    Passport::actingAsClient(OAuthClient::find($key->json('data.client_id')), ['quotes.read']);
    $this->getJson('/api/v1/partner/whoami')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
    $this->getJson('/api/v1/partner/usage')->assertOk()->assertJsonPath('data.environment', 'sandbox')
        ->assertJsonPath('data.permissions', ['claims.view', 'quotes.read']);
    $this->getJson('/api/v1/partner/whoami')->assertStatus(429);

    $usage = DB::table('integration_client_usage')->where(['integration_client_id' => $client->id, 'environment' => 'sandbox', 'scope' => '*'])->first();
    expect((int) $usage->allowed_count)->toBe(2)->and((int) $usage->rate_limited_count)->toBe(1);

    // the primary (DRAFT-lifecycle) credential is still not allowed in production
    app('auth')->forgetGuards();
    Passport::actingAsClient(OAuthClient::find($client->oauth_client_id), ['quotes.read']);
    $this->getJson('/api/v1/partner/whoami')->assertForbidden();

    app('auth')->forgetGuards();
    Passport::actingAs($this->admin, [], 'api');
    $this->getJson("/api/v1/developer/clients/{$client->id}/usage", $this->h)->assertOk()->assertJsonFragment(['environment' => 'sandbox']);
    $this->getJson('/api/v1/developer/clients', $this->h)->assertOk()->assertJsonPath('data.0.keys.0.environment', 'sandbox');
});

it('revokes a key and its tokens', function () {
    $client = b3SandboxClient($this->admin);
    Passport::actingAs($this->admin, [], 'api');
    $key = $this->postJson("/api/v1/developer/clients/{$client->id}/keys", ['environment' => 'sandbox'], $this->h)->assertCreated();
    $this->postJson("/api/v1/developer/clients/{$client->id}/keys/{$key->json('data.id')}/revoke", [], $this->h)->assertOk()->assertJsonPath('data.status', 'REVOKED');
    expect((bool) DB::table('oauth_clients')->where('id', $key->json('data.client_id'))->value('revoked'))->toBeTrue()
        ->and(DB::table('outbox_messages')->where('event_name', 'integration.client_key.revoked')->exists())->toBeTrue();

    app('auth')->forgetGuards();
    Passport::actingAsClient(OAuthClient::find($key->json('data.client_id')), ['quotes.read']);
    $this->getJson('/api/v1/partner/whoami')->assertForbidden();
});

it('enforces tenant consent for delegated (on-behalf-of) calls, limited to the client scopes', function () {
    $client = activateTestIntegrationClient($this->admin, ['scopes' => ['quotes.read', 'claims.read']]);
    Passport::actingAs($this->admin, [], 'api');

    $this->postJson('/api/v1/developer/consents', ['integration_client_id' => $client->id, 'scopes' => ['policies.read']], $this->h)->assertUnprocessable();

    app('auth')->forgetGuards();
    Passport::actingAsClient(OAuthClient::find($client->oauth_client_id), ['quotes.read']);
    $this->getJson('/api/v1/partner/whoami', ['X-OpesInsure-On-Behalf-Of' => $this->tenant->id])->assertForbidden();
    expect(DB::table('audit_log')->where(['action' => 'integration.request.denied', 'reason_code' => 'consent_missing'])->exists())->toBeTrue();

    app('auth')->forgetGuards();
    Passport::actingAs($this->admin, [], 'api');
    $consent = $this->postJson('/api/v1/developer/consents', ['integration_client_id' => $client->id, 'scopes' => ['quotes.read']], $this->h)
        ->assertCreated()->assertJsonPath('data.scopes', ['quotes.read'])->json('data.id');

    app('auth')->forgetGuards();
    Passport::actingAsClient(OAuthClient::find($client->oauth_client_id), ['quotes.read']);
    $this->getJson('/api/v1/partner/usage', ['X-OpesInsure-On-Behalf-Of' => $this->tenant->id])->assertOk()->assertJsonPath('data.permissions', ['quotes.read']);

    app('auth')->forgetGuards();
    Passport::actingAs($this->admin, [], 'api');
    $this->getJson('/api/v1/developer/consents', $this->h)->assertOk()->assertJsonPath('data.0.id', $consent);
    $this->postJson("/api/v1/developer/consents/{$consent}/revoke", ['reason' => 'offboarded'], $this->h)->assertOk()->assertJsonPath('data.status', 'REVOKED');

    app('auth')->forgetGuards();
    Passport::actingAsClient(OAuthClient::find($client->oauth_client_id), ['quotes.read']);
    $this->getJson('/api/v1/partner/whoami', ['X-OpesInsure-On-Behalf-Of' => $this->tenant->id])->assertForbidden();
});

it('requires the developer-portal permissions', function () {
    $user = makeAuthTestUser($this->tenant, ['quotes.read']);
    Passport::actingAs($user, [], 'api');
    $this->getJson('/api/v1/developer/clients', $this->h)->assertForbidden();
    $this->getJson('/api/v1/developer/consents', $this->h)->assertForbidden();
});
