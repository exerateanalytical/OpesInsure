<?php

declare(strict_types=1);

/**
 * S14 SMS providers: adapters (Orange, Twilio, Africa's Talking, generic HTTP) against Http::fake, primary → fallback
 * failover, masked delivery log, per-number rate limit, GSM-7/UCS-2 length handling, CONFIG_REQUIRED, encrypted
 * write-only secrets, platform-admin-only management and wiring into the OTP + notification paths.
 * All credentials here are obvious fakes.
 */

use App\Application\Notifications\Adapters\GatewaySmsAdapter;
use App\Application\Notifications\Adapters\NotificationAdapterRegistry;
use App\Application\Notifications\Otp\OtpDeliveryService;
use App\Application\Notifications\Sms\SmsDeliveryException;
use App\Application\Notifications\Sms\SmsEncoding;
use App\Application\Notifications\Sms\SmsGateway;
use App\Application\Notifications\Sms\SmsProviderConnections;
use App\Application\Settings\PlatformSettings;
use App\Models\SmsMessage;
use App\Models\SmsProviderConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

const S14_TO = '+237677123412';

function s14Conn(string $provider, string $role, array $settings, array $secrets): SmsProviderConnection
{
    return SmsProviderConnection::create(['provider' => $provider, 'label' => $provider, 'role' => $role, 'status' => 'ACTIVE', 'settings' => $settings, 'secrets' => $secrets]);
}

function s14Orange(string $role = 'PRIMARY'): SmsProviderConnection
{
    return s14Conn('orange', $role, ['sender_address' => 'tel:+237690000000'], ['client_id' => 'fake-id', 'client_secret' => 'fake-secret']);
}

beforeEach(function () {
    RateLimiter::clear('sms:number:'.hash('sha256', S14_TO));
    $this->platform = makeAuthTestTenant();
    $this->platform->update(['type' => 'PLATFORM']);
    $this->admin = makeAuthTestUser($this->platform, [], 'SYSTEM_ADMIN');
});

it('sends through Orange with an OAuth client-credentials token and tel: sender address', function () {
    Http::fake([
        'api.orange.com/oauth/v3/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
        'api.orange.com/smsmessaging/*' => Http::response(['outboundSMSMessageRequest' => ['resourceURL' => 'https://api.orange.com/x/requests/abc123']], 201),
    ]);
    s14Orange();

    $r = app(SmsGateway::class)->send(S14_TO, 'Code 123456', 'OTP');

    expect($r['provider'])->toBe('orange')->and($r['reference'])->toBe('abc123');
    Http::assertSent(fn (Request $q) => str_contains($q->url(), '/oauth/v3/token') && $q->hasHeader('Authorization', 'Basic '.base64_encode('fake-id:fake-secret')));
    Http::assertSent(fn (Request $q) => str_contains($q->url(), '/smsmessaging/v1/outbound/'.rawurlencode('tel:+237690000000').'/requests')
        && $q->hasHeader('Authorization', 'Bearer tok-1')
        && $q['outboundSMSMessageRequest']['address'] === 'tel:'.S14_TO
        && $q['outboundSMSMessageRequest']['outboundSMSTextMessage']['message'] === 'Code 123456');
});

it('sends through Twilio', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMfake'], 201)]);
    s14Conn('twilio', 'PRIMARY', ['from' => '+15005550006'], ['account_sid' => 'ACfake', 'auth_token' => 'fake-token']);

    expect(app(SmsGateway::class)->send(S14_TO, 'Hello')['reference'])->toBe('SMfake');
    Http::assertSent(fn (Request $q) => str_contains($q->url(), '/Accounts/ACfake/Messages.json') && $q['To'] === S14_TO && $q['From'] === '+15005550006');
});

it("sends through Africa's Talking and treats a non-Success recipient as a failure", function () {
    Http::fakeSequence('api.sandbox.africastalking.com/*')
        ->push(['SMSMessageData' => ['Recipients' => [['status' => 'Success', 'messageId' => 'ATX1', 'statusCode' => 101]]]], 201)
        ->push(['SMSMessageData' => ['Recipients' => [['status' => 'InsufficientBalance', 'statusCode' => 405]]]], 201);
    s14Conn('africastalking', 'PRIMARY', ['username' => 'sandbox', 'sandbox' => true], ['api_key' => 'fake-key']);

    expect(app(SmsGateway::class)->send(S14_TO, 'Hello')['reference'])->toBe('ATX1');
    Http::assertSent(fn (Request $q) => $q->hasHeader('apiKey', 'fake-key') && $q['username'] === 'sandbox' && $q['to'] === S14_TO);

    expect(fn () => app(SmsGateway::class)->send(S14_TO, 'Hello again'))->toThrow(SmsDeliveryException::class);
});

it('sends through a generic HTTP gateway with templated params and bearer auth', function () {
    Http::fake(['sms.aggregator.test/*' => Http::response(['status' => 'OK', 'data' => ['id' => 'G-77']])]);
    s14Conn('generic_http', 'PRIMARY', ['url' => 'https://sms.aggregator.test/send', 'method' => 'POST', 'format' => 'json', 'auth' => 'bearer', 'sender' => 'OPES',
        'params' => ['phone' => '{to_local}', 'text' => '{message}', 'from' => '{sender}', 'key' => '{api_key}'], 'success_contains' => 'OK', 'reference_path' => 'data.id'],
        ['token' => 'fake-bearer', 'api_key' => 'fake-api-key']);

    expect(app(SmsGateway::class)->send(S14_TO, 'Bonjour')['reference'])->toBe('G-77');
    Http::assertSent(fn (Request $q) => $q->method() === 'POST' && $q->hasHeader('Authorization', 'Bearer fake-bearer')
        && $q['phone'] === '677123412' && $q['text'] === 'Bonjour' && $q['from'] === 'OPES' && $q['key'] === 'fake-api-key');
});

it('fails over from the primary to the fallback, logs both attempts masked and records health', function () {
    Http::fake([
        'api.orange.com/*' => Http::response(['message' => 'down'], 503),
        'api.twilio.com/*' => Http::response(['sid' => 'SMback'], 201),
    ]);
    $primary = s14Orange('PRIMARY');
    $fallback = s14Conn('twilio', 'FALLBACK', ['from' => '+15005550006'], ['account_sid' => 'ACfake', 'auth_token' => 'fake-token']);

    $r = app(SmsGateway::class)->send(S14_TO, 'Code 654321', 'OTP');

    expect($r['provider'])->toBe('twilio');
    $log = SmsMessage::orderBy('attempt')->get();
    expect($log->pluck('status')->all())->toBe(['FAILED', 'SENT'])
        ->and($log->pluck('destination_masked')->unique()->all())->toBe(['+2376******12'])
        ->and($log->pluck('destination_masked')->implode(''))->not->toContain('77123');
    expect(DB::table('sms_messages')->where('destination_masked', S14_TO)->exists())->toBeFalse();
    expect($primary->fresh()->health_status)->toBe('FAILING')->and($primary->fresh()->consecutive_failures)->toBe(1)
        ->and($fallback->fresh()->health_status)->toBe('OK');
});

it('masks numbers', function () {
    expect(SmsGateway::mask('+237677123412'))->toBe('+2376******12')->and(SmsGateway::mask('+1234'))->toBe('*****');
});

it('reports CONFIG_REQUIRED with no active provider and blocks OTP login with a clear message', function () {
    Http::fake();
    expect(app(SmsGateway::class)->status())->toBe('CONFIG_REQUIRED');
    expect(fn () => app(SmsGateway::class)->send(S14_TO, 'x'))->toThrow(fn (SmsDeliveryException $e) => expect($e->status)->toBe('CONFIG_REQUIRED'));

    // No legacy provider either.
    config(['services.twilio.account_sid' => null, 'services.twilio.auth_token' => null, 'services.twilio.sms_from' => null, 'services.twilio.whatsapp_from' => null,
        'services.etech.sms_login' => null, 'services.etech.sms_password' => null, 'services.etech.rest_token' => null, 'services.etech.sms_sender' => null]);
    app(PlatformSettings::class)->flush();
    expect(app(OtpDeliveryService::class)->status())->toBe('CONFIG_REQUIRED');

    $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237655000123'])
        ->assertStatus(422)->assertJsonPath('errors.phone_e164.0', __('sms_providers.otp_config_required'));

    s14Orange();
    expect(app(OtpDeliveryService::class)->status())->toBe('CONFIGURED');
});

it('routes OTP delivery through the configured providers before the legacy drivers', function () {
    Http::fake([
        'api.orange.com/oauth/v3/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'api.orange.com/smsmessaging/*' => Http::response(['outboundSMSMessageRequest' => ['resourceURL' => 'r/1']], 201),
    ]);
    s14Orange();

    $sent = app(OtpDeliveryService::class)->send(S14_TO, '123456', 'Your code is 123456', 'sms');

    expect($sent)->toBe(['provider' => 'gateway', 'channel' => 'sms']);
    Http::assertNotSent(fn (Request $q) => str_contains($q->url(), 'twilio'));
    expect(SmsMessage::where('purpose', 'OTP')->where('status', 'SENT')->count())->toBe(1);
});

it('uses the configured providers for SMS notifications', function () {
    expect(app(NotificationAdapterRegistry::class)->for('SMS'))->not->toBeInstanceOf(GatewaySmsAdapter::class);
    s14Orange();
    expect(app(NotificationAdapterRegistry::class)->for('SMS'))->toBeInstanceOf(GatewaySmsAdapter::class);
});

it('rate-limits per number and does not fall back to another provider to get around it', function () {
    config(['services.sms.per_number_per_hour' => 2]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    s14Conn('twilio', 'PRIMARY', ['from' => '+15005550006'], ['account_sid' => 'ACfake', 'auth_token' => 'fake-token']);

    app(SmsGateway::class)->send(S14_TO, 'a');
    app(SmsGateway::class)->send(S14_TO, 'b');
    expect(fn () => app(SmsGateway::class)->send(S14_TO, 'c'))->toThrow(fn (SmsDeliveryException $e) => expect($e->status)->toBe('RATE_LIMITED'));
    expect(app(OtpDeliveryService::class)->send(S14_TO, '1', 'code 1', 'sms'))->toBeNull();
    Http::assertSentCount(2);
    expect(SmsMessage::where('status', 'RATE_LIMITED')->count())->toBeGreaterThanOrEqual(2);
});

it('handles GSM-7 and UCS-2 lengths and rejects over-long messages', function () {
    expect(SmsEncoding::analyse(str_repeat('a', 160)))->toMatchArray(['encoding' => 'GSM7', 'segments' => 1])
        ->and(SmsEncoding::analyse(str_repeat('a', 161))['segments'])->toBe(2)
        ->and(SmsEncoding::analyse(str_repeat('€', 80)))->toMatchArray(['encoding' => 'GSM7', 'length' => 160, 'segments' => 1])
        ->and(SmsEncoding::analyse('Votre code expire à 10h — merci'))->toMatchArray(['encoding' => 'UCS2', 'segments' => 1])
        ->and(SmsEncoding::analyse(str_repeat('ê', 71))['segments'])->toBe(2)
        ->and(SmsEncoding::analyse('é à è ù ì ò Ç Ä Ö Ñ Ü ß')['encoding'])->toBe('GSM7');

    Http::fake();
    s14Orange();
    config(['services.sms.max_segments' => 2]);
    expect(fn () => app(SmsGateway::class)->send(S14_TO, str_repeat('ê', 200)))->toThrow(fn (SmsDeliveryException $e) => expect($e->status)->toBe('REJECTED'));
    Http::assertNothingSent();
});

it('stores secrets encrypted, keeps them when left blank, never presents them, and allows one primary', function () {
    $svc = app(SmsProviderConnections::class);
    $a = $svc->save(['provider' => 'orange', 'label' => 'Orange', 'role' => 'PRIMARY', 'settings' => ['sender_address' => 'tel:+237690000000'],
        'secrets' => ['client_id' => 'fake-id', 'client_secret' => 'fake-secret']], $this->admin);

    $raw = (string) DB::table('sms_provider_connections')->where('id', $a->id)->value('secrets');
    expect($raw)->not->toContain('fake-secret');

    $svc->save(['id' => $a->id, 'provider' => 'orange', 'label' => 'Orange CM', 'role' => 'PRIMARY', 'settings' => ['sender_address' => 'tel:+237690000000'], 'secrets' => ['client_secret' => '']], $this->admin);
    expect($a->fresh()->secret('client_secret'))->toBe('fake-secret');

    $presented = json_encode($svc->present());
    expect($presented)->not->toContain('fake-secret')->not->toContain('fake-id')->toContain('client_secret');
    expect(json_encode($a->fresh()->toArray()))->not->toContain('fake-secret');

    $b = $svc->save(['provider' => 'twilio', 'label' => 'Twilio', 'role' => 'PRIMARY', 'settings' => ['from' => '+15005550006'], 'secrets' => ['account_sid' => 'ACfake', 'auth_token' => 'x']], $this->admin);
    expect($a->fresh()->role)->toBe('STANDBY')->and($b->fresh()->role)->toBe('PRIMARY');
});

it('lets only platform administrators manage providers or send a test SMS', function () {
    $other = makeAuthTestUser(makeAuthTestTenant(), [], 'SYSTEM_ADMIN');
    expect(fn () => app(SmsProviderConnections::class)->save(['provider' => 'twilio', 'label' => 'x'], $other))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => app(SmsProviderConnections::class)->test(null, S14_TO, $other))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMtest'], 201)]);
    $c = s14Conn('twilio', 'STANDBY', ['from' => '+15005550006'], ['account_sid' => 'ACfake', 'auth_token' => 'fake-token']);
    expect(app(SmsProviderConnections::class)->test($c->id, S14_TO, $this->admin)['reference'])->toBe('SMtest');
    expect(SmsMessage::where('purpose', 'TEST')->value('status'))->toBe('SENT');
});

it('renders the admin screen for a platform administrator only', function () {
    $this->actingAs($this->admin)->get('/admin/integrations/sms-providers')->assertOk()->assertSee(__('sms_providers.status_config_required'));
    // Non-platform admin: refused (403, or redirected away by the panel's tenant middleware).
    $status = $this->actingAs(makeAuthTestUser(makeAuthTestTenant(), [], 'SYSTEM_ADMIN'))->get('/admin/integrations/sms-providers')->getStatusCode();
    expect($status)->toBeIn([302, 403]);
    expect(\App\Filament\Admin\Pages\Integrations\SmsProviders::canAccess())->toBeFalse();
});
