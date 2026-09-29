<?php

declare(strict_types=1);

/**
 * S1 — partner developer portal (/developers, DEV-001..014): a partner user linked to ONE integration client sees only
 * that client's keys, request logs, usage, webhook deliveries; sandbox secrets are shown once and never stored or
 * logged; request logging from the partner API gate; changelog from OpenAPI diffs; staff linking route; EN/FR.
 */

use App\Application\Integrations\Developer\Portal\ApiChangelogService;
use App\Application\Integrations\Developer\Portal\Filament\DeveloperPanelProvider;
use App\Application\Integrations\Developer\Portal\Filament\Pages\{ChangelogPage,CredentialsPage,DeliveryLogsPage,EventCataloguePage,ExplorerPage,HomePage,RequestLogsPage,UsagePage};
use App\Application\Integrations\Developer\Portal\PartnerDeveloperPortalService;
use App\Application\Integrations\IntegrationClientLifecycleService;
use App\Models\IntegrationClient;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client as OAuthClient;
use Laravel\Passport\Passport;
use Livewire\Livewire;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Integrations/Concerns/helpers.php';

uses(RefreshDatabase::class);

function s1Client(User $admin, string $name): IntegrationClient
{
    $svc = app(IntegrationClientLifecycleService::class);
    $c = registerTestIntegrationClient($admin, ['name' => $name, 'scopes' => ['quotes.read', 'claims.read']]);
    foreach (['TECHNICAL_REVIEW', 'SANDBOX_ENABLED'] as $s) {
        $c = $svc->advance($c, $s, null, $admin);
    }

    return $c;
}

function s1Partner(object $t, IntegrationClient $client, string $role = 'DEVELOPER'): User
{
    $u = makeAuthTestUser(makeAuthTestTenant(), []);
    app(PartnerDeveloperPortalService::class)->linkDeveloper($client, $u, $role, $t->admin);

    return $u;
}

function s1Seed(IntegrationClient $c, string $marker): void
{
    app(PartnerDeveloperPortalService::class)->logRequest($c, null, 'sandbox', 'GET', '/api/v1/partner/'.$marker, '*', 200, 'allowed', null, 12, '10.0.0.1', null);
    DB::table('integration_client_usage')->insert(['id' => (string) Str::uuid(), 'integration_client_id' => $c->id, 'usage_date' => now()->toDateString(),
        'environment' => 'sandbox', 'scope' => $marker, 'allowed_count' => 7, 'denied_count' => 0, 'rate_limited_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $sub = (string) Str::uuid();
    DB::table('integration_webhook_subscriptions')->insert(['id' => $sub, 'integration_client_id' => $c->id, 'event_name' => 'policy.issued.'.$marker,
        'endpoint_encrypted' => Crypt::encryptString('https://'.$marker.'.example.test/hook'), 'signing_secret_hash' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('integration_delivery_attempts')->insert(['id' => (string) Str::uuid(), 'integration_webhook_subscription_id' => $sub, 'event_id' => (string) Str::uuid(),
        'attempt' => 1, 'status' => 'DEAD_LETTERED', 'response_status' => 500, 'failure_reason' => 'fail-'.$marker, 'created_at' => now(), 'updated_at' => now()]);
}

beforeEach(function () {
    $this->tenant = makeAuthTestTenant();
    $this->tenant->update(['type' => 'PLATFORM']);
    $this->admin = makeAuthTestUser($this->tenant, ['integrations.manage', 'integrations.revoke']);
    $this->a = s1Client($this->admin, 'Alpha Connector');
    $this->b = s1Client($this->admin, 'Bravo Connector');
    $this->userA = s1Partner($this, $this->a);
    $this->userB = s1Partner($this, $this->b);
    s1Seed($this->a, 'alphamark');
    s1Seed($this->b, 'bravomark');
    Filament::setCurrentPanel(Filament::getPanel('developers'));
});

it('renders every developer screen for a linked partner; refuses unlinked users and redirects guests', function () {
    $this->get('/developers/home')->assertRedirect('/developers/login');
    foreach (DeveloperPanelProvider::pages() as $page) {
        $this->actingAs($this->userA, 'web')->get($page::getUrl())->assertOk();
    }
    $this->actingAs($this->userA, 'web')->get(HomePage::getUrl())->assertSee('Alpha Connector')->assertDontSee('Bravo Connector');
    $stranger = makeAuthTestUser(makeAuthTestTenant(), ['*']);
    $this->actingAs($stranger, 'web')->get(HomePage::getUrl())->assertForbidden();
    // a revoked link closes the portal
    app(PartnerDeveloperPortalService::class)->unlinkDeveloper($this->a, $this->userA->id, $this->admin);
    $this->actingAs($this->userA, 'web')->get(HomePage::getUrl())->assertForbidden();
});

it('shows only the own client request logs, usage, deliveries and subscriptions', function () {
    $this->actingAs($this->userA, 'web');
    Livewire::test(RequestLogsPage::class)->assertSee('/api/v1/partner/alphamark')->assertDontSee('bravomark')
        ->set('outcome', 'denied')->assertDontSee('/api/v1/partner/alphamark');
    Livewire::test(UsagePage::class)->assertSee('7')->assertDontSee('bravomark');
    Livewire::test(DeliveryLogsPage::class)->assertSee('fail-alphamark')->assertDontSee('fail-bravomark');
    DB::table('canonical_event_schemas')->insert(['id' => (string) Str::uuid(), 'event_name' => 'policy.issued', 'version' => 1, 'status' => 'ACTIVE',
        'json_schema' => '{}', 'example_payload' => '{}', 'privacy_classification' => 'INTERNAL', 'created_at' => now(), 'updated_at' => now()]);
    Livewire::test(EventCataloguePage::class)->assertSee('policy.issued')->assertSee('alphamark.example.test')->assertDontSee('bravomark.example.test');

    // switching to a client that is not theirs is ignored
    Livewire::test(HomePage::class)->call('switchClient', $this->b->id)->assertSee('Alpha Connector')->assertDontSee('Bravo Connector');

    $this->actingAs($this->userB, 'web');
    Livewire::test(RequestLogsPage::class)->assertSee('bravomark')->assertDontSee('alphamark');
});

it('shows a sandbox secret once, never stores or logs it, and cannot touch another client key', function () {
    $this->actingAs($this->userA, 'web');
    $lw = Livewire::test(CredentialsPage::class)->set('label', 'ci')->call('issueKey')->assertSet('state', 'SUCCESS');
    $key = DB::table('integration_client_keys')->where('integration_client_id', $this->a->id)->first();
    expect($key)->not->toBeNull()->and($key->environment)->toBe('sandbox');
    $html = $lw->html();
    preg_match('/data-secret-once.*?font-mono break-all">([^<]+)<.*?font-mono break-all">([^<]+)</s', $html, $m);
    $secret = trim($m[2] ?? '');
    expect($secret)->not->toBe('')->and(trim($m[1]))->toBe($key->oauth_client_id);

    // not in the Livewire snapshot, gone on the next round-trip, not stored in clear, not audited / logged
    expect(json_encode($lw->snapshot))->not->toContain($secret);
    $lw->call('$refresh')->assertDontSee($secret);
    expect(DB::table('oauth_clients')->where('id', $key->oauth_client_id)->value('secret'))->not->toBe($secret)
        ->and(DB::table('audit_log')->whereRaw('cast(metadata as text) like ?', ['%'.$secret.'%'])->exists())->toBeFalse()
        ->and(DB::table('outbox_messages')->whereRaw('cast(payload as text) like ?', ['%'.$secret.'%'])->exists())->toBeFalse();

    // another client's key: not found, stays ACTIVE
    $bKey = app(\App\Application\Integrations\Developer\DeveloperPortalService::class)->issueKey($this->b, 'sandbox', null, $this->admin)['key'];
    Livewire::test(CredentialsPage::class)->call('revokeKey', $bKey->id)->assertSet('state', 'VALIDATION_FAILED')->assertDontSee($bKey->oauth_client_id);
    expect(DB::table('integration_client_keys')->where('id', $bKey->id)->value('status'))->toBe('ACTIVE');

    // own key revoke works
    Livewire::test(CredentialsPage::class)->call('revokeKey', $key->id)->assertSet('state', 'SUCCESS');
    expect(DB::table('integration_client_keys')->where('id', $key->id)->value('status'))->toBe('REVOKED');

    // a VIEWER cannot issue keys
    $viewer = s1Partner($this, $this->a, 'VIEWER');
    $this->actingAs($viewer, 'web');
    $before = DB::table('integration_client_keys')->count();
    Livewire::test(CredentialsPage::class)->call('issueKey')->assertSet('state', 'VALIDATION_FAILED');
    expect(DB::table('integration_client_keys')->count())->toBe($before);
});

it('logs partner API calls (metadata only) from the integration gate', function () {
    $issued = app(\App\Application\Integrations\Developer\DeveloperPortalService::class)->issueKey($this->a, 'sandbox', null, $this->admin);
    Passport::actingAsClient(OAuthClient::find($issued['client_id']), ['quotes.read']);
    $this->getJson('/api/v1/partner/whoami', ['X-Request-Id' => 'req-123'])->assertOk();
    $log = DB::table('integration_request_logs')->where(['integration_client_id' => $this->a->id, 'route' => '/api/v1/partner/whoami'])->first();
    expect($log->request_id)->not->toBeNull()->and($log->integration_client_key_id)->toBe($issued['key']->id);
    expect($log)->not->toBeNull()->and($log->route)->toBe('/api/v1/partner/whoami')->and($log->outcome)->toBe('allowed')
        ->and($log->environment)->toBe('sandbox')->and((int) $log->status_code)->toBe(200)->and(json_encode($log))->not->toContain($issued['client_secret']);
});

it('builds the changelog from OpenAPI diffs', function () {
    $svc = app(ApiChangelogService::class);
    $op = fn (array $scopes, array $params = []) => ['operationId' => 'x', 'x-partner-scopes' => $scopes, 'parameters' => array_map(fn ($p) => ['name' => $p], $params)];
    $v1 = ['info' => ['version' => 'v1'], 'paths' => ['/api/v1/partner/a' => ['get' => $op(['*'], ['id'])], '/api/v1/partner/b' => ['get' => $op(['*'])]]];
    expect($svc->sync($v1))->toMatchArray(['baseline' => 2, 'added' => 0]);
    expect($svc->sync($v1))->toMatchArray(['baseline' => 0, 'added' => 0, 'changed' => 0, 'removed' => 0]);
    $v2 = ['info' => ['version' => 'v1'], 'paths' => ['/api/v1/partner/a' => ['get' => $op(['quotes.read'], ['id'])], '/api/v1/partner/c' => ['post' => $op(['*'])]]];
    expect($svc->sync($v2, now()->addMinute()->toIso8601String()))->toMatchArray(['added' => 1, 'changed' => 1, 'removed' => 1]);
    $e = collect($svc->entries())->keyBy('operation');
    expect($e['POST /api/v1/partner/c']['change_type'])->toBe('ADDED')->and($e['GET /api/v1/partner/b']['change_type'])->toBe('REMOVED')
        ->and((bool) $e['GET /api/v1/partner/a']['breaking'])->toBeTrue();

    $this->actingAs($this->userA, 'web');
    Livewire::test(ChangelogPage::class)->assertSee('POST /api/v1/partner/c')->assertSee('REMOVED');
    Livewire::test(ExplorerPage::class)->call('pick', 'nope')->assertSet('operation', null);
});

it('lets platform staff link a partner user through the API and renders in French', function () {
    $newbie = makeAuthTestUser(makeAuthTestTenant(), []);
    Passport::actingAs($this->admin, [], 'api');
    $this->postJson("/api/v1/developer/clients/{$this->a->id}/developers", ['user_id' => $newbie->id, 'role' => 'OWNER'], tenantHeader($this->tenant))->assertCreated()
        ->assertJsonPath('data.role', 'OWNER');
    $this->getJson("/api/v1/developer/clients/{$this->a->id}/developers", tenantHeader($this->tenant))->assertOk()->assertJsonFragment(['user_id' => $newbie->id]);
    // a non-platform tenant cannot
    $carrier = makeAuthTestTenant();
    $other = makeAuthTestUser($carrier, ['integrations.manage']);
    app('auth')->forgetGuards();
    Passport::actingAs($other, [], 'api');
    $this->postJson("/api/v1/developer/clients/{$this->a->id}/developers", ['user_id' => $newbie->id, 'role' => 'OWNER'], tenantHeader($carrier))->assertForbidden();

    app()->setLocale('fr');
    $this->actingAs($newbie, 'web');
    Livewire::test(UsagePage::class)->assertSee('Consommation et limites');
});
