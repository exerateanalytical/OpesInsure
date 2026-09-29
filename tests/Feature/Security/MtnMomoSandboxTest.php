<?php

declare(strict_types=1);

use App\Application\Payments\Adapters\FakePaymentAdapter;
use App\Application\Payments\Adapters\MtnMomoAdapter;
use App\Application\Payments\Adapters\MtnMomoCredentials;
use App\Application\Payments\Adapters\PaymentAdapterRegistry;
use App\Models\PaymentProviderConnection;
use App\Models\Policy;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const MOMO_SBX_PERSONA = '+237600000100'; // DemoMobileAccountSeeder 'Demo Customer'
const MOMO_SBX_FAKE_KEY = 'fake-sandbox-subscription-key';
const MOMO_SBX_FAKE_USER = 'fake-sandbox-api-user';
const MOMO_SBX_FAKE_APIKEY = 'fake-sandbox-api-key';
const MOMO_SBX_FAKE_CALLBACK = 'fake-sandbox-callback-token';

function momoSandboxConnection(): PaymentProviderConnection
{
    $a = User::create(['full_name' => 'Admin A', 'phone_e164' => '+237699000001', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $b = User::create(['full_name' => 'Admin B', 'phone_e164' => '+237699000002', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

    return PaymentProviderConnection::create(['tenant_id' => null, 'provider' => 'mtn_momo', 'environment' => 'SANDBOX', 'status' => 'ACTIVE',
        'credential_reference' => 'db://test', 'created_by' => $a->id, 'approved_by' => $b->id, 'approved_at' => now(),
        'secrets' => ['subscription_key' => MOMO_SBX_FAKE_KEY, 'api_user' => MOMO_SBX_FAKE_USER, 'api_key' => MOMO_SBX_FAKE_APIKEY, 'callback_token' => MOMO_SBX_FAKE_CALLBACK]]);
}

function momoSandboxWorld(string $phone): array
{
    $f = makeMobileCustomerFixture($phone);
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['total_minor' => 100000, 'currency' => 'XAF']]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['provider' => 'mtn_momo', 'status' => 'CREATED', 'provider_reference' => null, 'payer_phone_e164' => '+46733123454']);
    $approver = User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+237699999999', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $f['tenant']->id, 'user_id' => $approver->id, 'role_code' => 'CARRIER_STAFF', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::firstOrCreate(['tenant_id' => $f['tenant']->id, 'code' => 'CARRIER_STAFF'], ['permissions' => ['*'], 'is_system' => true])->id);

    return $f;
}

function fakeMtnSandbox(string $status = 'SUCCESSFUL'): void
{
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'sandbox-tok', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay/*' => fn (HttpRequest $r) => Http::response(['externalId' => null, 'status' => $status, 'currency' => 'EUR'], 200),
        '*/collection/v1_0/requesttopay' => Http::response(null, 202),
    ]);
}

beforeEach(function () {
    config(['demo.enabled' => true]);
});

it('reads the encrypted DB connection over .env, stores secrets encrypted and hides them', function () {
    $conn = momoSandboxConnection();
    $creds = MtnMomoCredentials::resolve();

    expect($creds->configured())->toBeTrue()
        ->and($creds->isSandbox())->toBeTrue()
        ->and($creds->get('base_url'))->toBe('https://sandbox.momodeveloper.mtn.com')
        ->and($creds->get('api_user'))->toBe(MOMO_SBX_FAKE_USER)
        ->and($creds->wireCurrency('XAF'))->toBe('EUR');
    $raw = (string) $conn->getRawOriginal('secrets');
    expect($raw)->not->toContain(MOMO_SBX_FAKE_APIKEY)->not->toContain(MOMO_SBX_FAKE_KEY)
        ->and(json_encode($conn->fresh()->toArray()))->not->toContain(MOMO_SBX_FAKE_APIKEY);
});

it('keeps the .env fallback with production currency when no connection exists', function () {
    $creds = MtnMomoCredentials::resolve();
    expect($creds->configured())->toBeTrue()->and($creds->isSandbox())->toBeFalse()->and($creds->wireCurrency('XAF'))->toBe('XAF');
});

it('gets a sandbox token with Basic api user/key and the subscription key, then settles a demo purchase on SUCCESSFUL', function () {
    momoSandboxConnection();
    fakeMtnSandbox('SUCCESSFUL');
    $f = momoSandboxWorld(MOMO_SBX_PERSONA);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    expect((new PaymentAdapterRegistry)->for('mtn_momo', true))->toBeInstanceOf(MtnMomoAdapter::class);

    $this->postJson("/api/v1/payments/{$f['payment']->id}/initiate", [], $h)->assertStatus(202);
    $payment = $f['payment']->fresh();
    expect($payment->status)->toBe('PENDING_CUSTOMER');

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/collection/token/')
        && $r->hasHeader('Authorization', 'Basic '.base64_encode(MOMO_SBX_FAKE_USER.':'.MOMO_SBX_FAKE_APIKEY))
        && $r->hasHeader('Ocp-Apim-Subscription-Key', MOMO_SBX_FAKE_KEY));
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/collection/v1_0/requesttopay')
        && $r->hasHeader('X-Target-Environment', 'sandbox') && $r['currency'] === 'EUR' && $r['payer']['partyId'] === '46733123454');

    // The app polls; the settler asks MTN (sandbox) instead of faking the outcome.
    $this->getJson("/api/v1/payments/{$payment->id}", $h)->assertOk();
    $payment->refresh();
    expect($payment->status)->toBe('SUCCEEDED')
        ->and($payment->provider_snapshot['sandbox'] ?? null)->toBeTrue();
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/collection/v1_0/requesttopay/'.$payment->provider_reference));
    expect(Policy::where('proposal_id', $f['proposal']->id)->exists())->toBeTrue();
});

it('does not fake-settle a sandbox MoMo payment that MTN still reports PENDING', function () {
    momoSandboxConnection();
    fakeMtnSandbox('PENDING');
    $f = momoSandboxWorld(MOMO_SBX_PERSONA);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);
    $this->postJson("/api/v1/payments/{$f['payment']->id}/initiate", [], $h)->assertStatus(202);
    $f['payment']->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();

    $this->getJson("/api/v1/payments/{$f['payment']->id}", $h)->assertOk();
    expect($f['payment']->fresh()->status)->toBe('PENDING_CUSTOMER');
});

it('refuses a real customer: the sandbox connection is not a live provider', function () {
    momoSandboxConnection();
    Http::fake();
    $f = momoSandboxWorld('+237677555111');
    Passport::actingAs($f['user']);

    $this->postJson("/api/v1/payments/{$f['payment']->id}/initiate", [], tenantHeaderFor($f['tenant']))
        ->assertStatus(422)->assertJsonValidationErrors('provider');
    expect($f['payment']->fresh()->status)->toBe('CREATED');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'momodeveloper') || str_contains($r->url(), '/collection/'));
});

it('refuses a real customer\'s intent at the adapter even if called directly', function () {
    momoSandboxConnection();
    Http::fake();
    $f = momoSandboxWorld('+237677555112');

    expect(fn () => (new MtnMomoAdapter)->requestCustomerAuthorization($f['payment'], 'ref-1'))->toThrow(DomainException::class, 'test mode');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'momodeveloper') || str_contains($r->url(), '/collection/'));
});

it('never settles a real customer\'s intent on a sandbox status (poll or callback)', function () {
    momoSandboxConnection();
    fakeMtnSandbox('SUCCESSFUL');
    $f = momoSandboxWorld('+237677555113');
    $f['payment']->update(['status' => 'PENDING_CUSTOMER', 'provider_reference' => 'sbx-ref-real']);

    $this->artisan('payments:poll-pending')->assertSuccessful();
    $this->postJson('/api/v1/webhooks/payments/mtn-momo/callback?reference_id=sbx-ref-real&token='.MOMO_SBX_FAKE_CALLBACK)->assertOk();

    expect($f['payment']->fresh()->status)->toBe('PENDING_CUSTOMER')
        ->and(Policy::where('proposal_id', $f['proposal']->id)->exists())->toBeFalse();
});

it('still enforces the callback token (now from the encrypted connection)', function () {
    momoSandboxConnection();
    Http::fake();
    $f = momoSandboxWorld(MOMO_SBX_PERSONA);
    $f['payment']->update(['status' => 'PENDING_CUSTOMER', 'provider_reference' => 'sbx-ref-demo']);

    $this->postJson('/api/v1/webhooks/payments/mtn-momo/callback?reference_id=sbx-ref-demo&token=testing-mtn-callback-token')->assertStatus(401);
    $this->postJson('/api/v1/webhooks/payments/mtn-momo/callback?reference_id=sbx-ref-demo&token=wrong')->assertStatus(401);
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'momodeveloper') || str_contains($r->url(), '/collection/'));
});

it('hands demo personas the fake adapter when no sandbox is configured', function () {
    expect((new PaymentAdapterRegistry)->for('mtn_momo', true))->toBeInstanceOf(FakePaymentAdapter::class);
});

it('never logs the secrets, even when MTN rejects authentication', function () {
    momoSandboxConnection();
    $logged = [];
    Log::listen(function ($e) use (&$logged) { $logged[] = $e->message.json_encode($e->context); });
    Http::fake(['*/collection/token/' => Http::response(['error' => 'unauthorized'], 401)]);
    $f = momoSandboxWorld(MOMO_SBX_PERSONA);
    Passport::actingAs($f['user']);

    $this->postJson("/api/v1/payments/{$f['payment']->id}/initiate", [], tenantHeaderFor($f['tenant']));
    $f['payment']->update(['status' => 'PENDING_CUSTOMER', 'provider_reference' => 'sbx-ref-x']);
    $this->artisan('payments:poll-pending')->assertSuccessful();

    $all = implode("\n", $logged);
    foreach ([MOMO_SBX_FAKE_KEY, MOMO_SBX_FAKE_USER, MOMO_SBX_FAKE_APIKEY, MOMO_SBX_FAKE_CALLBACK] as $secret) {
        expect($all)->not->toContain($secret);
    }
});
