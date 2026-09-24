<?php

declare(strict_types=1);

use App\Application\Notifications\Otp\OtpDeliveryService;
use App\Application\Settings\PlatformSettings;
use App\Filament\Admin\Pages\PlatformSettingsPage;
use App\Models\OtpDelivery;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\VerificationChallenge;
use Database\Seeders\DemoMobileAccountSeeder;
use Database\Seeders\MobileOAuthClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';

beforeEach(fn () => $this->seed(MobileOAuthClientSeeder::class));

function settingsRow(array $values): PlatformSetting
{
    $row = app(PlatformSettings::class)->editable();
    $row->fill($values)->save();

    return $row;
}

function etechConfigured(array $extra = []): void
{
    settingsRow([
        'etech_sms_login' => 'opes', 'etech_sms_password' => 'secret', 'etech_sms_sender' => 'OPESINSURE',
        'etech_rest_token' => 'tok-123', 'etech_whatsapp_template_name' => 'otp_code', 'etech_whatsapp_template_language' => 'fr',
        ...$extra,
    ]);
}

const PSA_DEVICE = ['fingerprint' => 'dev-1', 'name' => 'Pixel', 'platform' => 'android'];

// ------------------------------------------------------------ Phase 2: no rate limit

it('does not rate limit a demo persona: 30 OTP requests for one phone from one IP all succeed', function () {
    config(['demo.enabled' => true]);
    Http::fake();
    makeMobileTestUser('+237600000100');

    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237600000100'])->assertSuccessful();
    }
});

it('caps code sends per real phone at 6 an hour with a 429, whether or not the number exists', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);
    makeMobileTestUser('+237670001111');

    foreach (['+237670001111', '+237670001199'] as $phone) {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $phone])->assertOk();
        }
        $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $phone])->assertStatus(429)->assertJsonFragment(['message' => 'Too many attempts. Try again in 60 minute(s).']);
        $this->postJson('/api/v1/auth/mobile/password/forgot', ['phone_e164' => $phone])->assertStatus(429);
    }
});

it('locks password login after 10 failures per phone for 15 minutes (429), demo personas exempt', function () {
    User::create(['full_name' => 'Pat', 'phone_e164' => '+237670005010', 'password' => 'Secret123', 'locale' => 'en', 'status' => 'ACTIVE']);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005010', 'password' => 'Wrong1234', 'device' => PSA_DEVICE])->assertStatus(422);
    }
    // Even the right password is refused while locked: stops guessing.
    $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005010', 'password' => 'Secret123', 'device' => PSA_DEVICE])->assertStatus(429);

    config(['demo.enabled' => true]);
    for ($i = 0; $i < 15; $i++) {
        $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237600000101', 'password' => 'Wrong1234', 'device' => PSA_DEVICE])->assertStatus(422);
    }
});

it('applies a generous per-IP cap (300/hour) across the auth endpoints', function () {
    $key = 'mobile-auth:ip:'.hash('sha256', '127.0.0.1');
    for ($i = 0; $i < 300; $i++) {
        Illuminate\Support\Facades\RateLimiter::hit($key, 3600);
    }

    $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005011', 'password' => 'Wrong1234', 'device' => PSA_DEVICE])->assertStatus(429);
    $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670005012', 'password' => 'Secret123'])->assertStatus(429);
});

it('still locks a code after 5 wrong guesses', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);
    makeMobileTestUser('+237670001112');
    $id = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237670001112'])->json('data.challenge_id');
    $code = extractMobileOtpCode();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $id, 'code' => '000000', 'device' => PSA_DEVICE])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $id, 'code' => $code, 'device' => PSA_DEVICE])->assertStatus(422);
});

// ------------------------------------------------------------ 9.1 settings

it('stores secrets encrypted and overrides .env only when set', function () {
    config(['services.twilio.sms_from' => '+15550000000']);
    settingsRow(['twilio_auth_token' => 'admin-token', 'support_email' => 'help@example.cm']);

    $raw = DB::table('platform_settings')->value('twilio_auth_token');
    expect($raw)->not->toBe('admin-token');

    $twilio = app(PlatformSettings::class)->twilio();
    expect($twilio['auth_token'])->toBe('admin-token')
        ->and($twilio['sms_from'])->toBe('+15550000000'); // blank admin field falls back to .env
});

it('applies admin SMTP settings to the mailer when enabled', function () {
    settingsRow(['mail_enabled' => true, 'mail_host' => 'smtp.example.cm', 'mail_port' => 2525, 'mail_username' => 'u', 'mail_password' => 'p', 'mail_from_address' => 'no-reply@example.cm']);
    app(PlatformSettings::class)->applyMailConfig();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.cm')
        ->and(config('mail.mailers.smtp.password'))->toBe('p')
        ->and(config('mail.from.address'))->toBe('no-reply@example.cm');
});

it('restricts the Filament settings page to platform/system admins', function () {
    $admin = makeMobileTestUser('+237670002001');
    makeMobileTestWorkspace($admin, ['*'], 'SYSTEM_ADMIN');
    $agent = makeMobileTestUser('+237670002002');
    makeMobileTestWorkspace($agent, ['*'], 'AGENT');

    $this->actingAs($agent);
    expect(PlatformSettingsPage::canAccess())->toBeFalse();

    $this->actingAs($admin);
    expect(PlatformSettingsPage::canAccess())->toBeTrue();
});

it('saves through the Filament page, keeping stored secrets when left blank', function () {
    $admin = makeMobileTestUser('+237670002003');
    makeMobileTestWorkspace($admin, ['*'], 'SYSTEM_ADMIN');
    settingsRow(['etech_rest_token' => 'keep-me']);
    $this->actingAs($admin);

    \Livewire\Livewire::test(PlatformSettingsPage::class)
        ->set('data.support_email', 'support@example.cm')
        ->set('data.etech_sms_sender', 'OPES')
        ->set('data.etech_rest_token', '')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(PlatformSettings::class);
    expect($settings->supportContacts()['email'])->toBe('support@example.cm')
        ->and($settings->etech()['rest_token'])->toBe('keep-me');
});

// ------------------------------------------------------------ 9.2 support contacts

it('serves support contacts from the admin settings', function () {
    settingsRow(['support_email' => 'help@example.cm', 'support_phone' => '+237222000000', 'whatsapp_number' => '+237 690 00 00 00', 'partner_email' => 'partners@example.cm']);

    $this->getJson('/api/v1/public/support-contacts')->assertOk()->assertExactJson(['data' => [
        'email' => 'help@example.cm',
        'phone' => '+237222000000',
        'whatsapp' => '+237 690 00 00 00',
        'whatsapp_url' => 'https://wa.me/237690000000',
        'partner_email' => 'partners@example.cm',
    ]]);
});

it('returns nulls when no support contacts are configured', function () {
    $this->getJson('/api/v1/public/support-contacts')->assertOk()->assertExactJson(['data' => [
        'email' => null, 'phone' => null, 'whatsapp' => null, 'whatsapp_url' => null, 'partner_email' => null,
    ]]);
});

// ------------------------------------------------------------ 9.3 OTP drivers

it('sends the OTP via ETECH WhatsApp template first when configured', function () {
    Http::fake(['v1.api.etech-keys.com/*' => Http::response(['id' => 'wa-1'], 200), '*' => Http::response(['sid' => 'SM1'], 201)]);
    etechConfigured();
    makeMobileTestUser('+237670003001');

    $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237670003001'])->assertOk();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://v1.api.etech-keys.com/api/v1/whatsapp/send'
            && $request->hasHeader('Authorization', 'Bearer tok-123')
            && $request['to'] === '237670003001'
            && $request['type'] === 'template'
            && $request['template']['name'] === 'otp_code'
            && $request['template']['language'] === ['code' => 'fr']
            && preg_match('/^\d{6}$/', $request['template']['components'][0]['parameters'][0]['text']) === 1;
    });
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'twilio'));
    expect(OtpDelivery::where('provider', 'etech')->where('channel', 'whatsapp')->where('status', 'SENT')->value('provider_reference'))->toBe('wa-1');
});

it('honours channel=sms and prefers the ETECH REST API when a token is set', function () {
    Http::fake(['*' => Http::response(['id' => 'sms-9'], 200)]);
    etechConfigured();
    makeMobileTestUser('+237670003002');

    $this->postJson('/api/v1/auth/mobile/otp/request', ['phone' => '+237670003002', 'channel' => 'sms'])->assertOk();

    Http::assertSent(fn ($r) => $r->url() === 'https://v1.api.etech-keys.com/api/v1/send-sms'
        && $r['sender'] === 'OPESINSURE' && $r['tel'] === '237670003002' && str_contains($r['msg'], 'code is'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'whatsapp'));
});

it('uses the legacy ETECH SMS GET endpoint when no REST token is set', function () {
    Http::fake(['*' => Http::response('OK:12345', 200)]);
    settingsRow(['etech_sms_login' => 'opes', 'etech_sms_password' => 'secret', 'etech_sms_sender' => 'OPESINSURE', 'otp_channel_priority' => 'sms']);
    makeMobileTestUser('+237670003003');

    $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237670003003'])->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'GET' && str_starts_with($r->url(), 'https://sms.etech-keys.com/ss/envoyer.php?')
        && $r['login'] === 'opes' && $r['password'] === 'secret' && $r['sender'] === 'OPESINSURE' && $r['tel'] === '237670003003');
});

it('falls back ETECH -> Twilio and logs critical when every provider fails', function () {
    Http::fake(['v1.api.etech-keys.com/*' => Http::response(['error' => 'x'], 500), 'api.twilio.com/*' => Http::response(['sid' => 'SMok'], 201)]);
    etechConfigured(['otp_channel_priority' => 'sms']);

    $result = app(OtpDeliveryService::class)->send('+237670003004', '123123', 'Your OpesInsure verification code is 123123.');
    expect($result)->toBe(['provider' => 'twilio', 'channel' => 'sms']);
    expect(OtpDelivery::where('status', 'FAILED')->where('provider', 'etech')->exists())->toBeTrue();
});

it('logs critical when every OTP provider fails', function () {
    Http::fake(['*' => Http::response([], 500)]);
    etechConfigured(['otp_channel_priority' => 'sms']);
    Log::spy();
    expect(app(OtpDeliveryService::class)->send('+237670003004', '123123', 'x'))->toBeNull();
    Log::shouldHaveReceived('critical')->withArgs(fn ($message) => $message === 'otp.delivery_failed')->once();
});

it('records ETECH delivery reports on the DLR webhook', function () {
    $delivery = OtpDelivery::create(['provider' => 'etech', 'channel' => 'sms', 'destination_hash' => 'x', 'provider_reference' => 'abc-1', 'status' => 'SENT']);

    $this->postJson('/api/v1/webhooks/etech/dlr', ['tel' => '237670000000', 'etat' => '1', 'id' => 'abc-1', 'date' => '2026-09-24 10:00:00'])
        ->assertOk()->assertJson(['accepted' => true, 'matched' => true]);
    expect($delivery->fresh()->status)->toBe('DELIVERED')->and($delivery->fresh()->delivered_at)->not->toBeNull();

    $this->post('/api/v1/webhooks/etech/dlr', ['tel' => '237670000000', 'etat' => '2', 'id' => 'abc-1'])->assertOk();
    expect($delivery->fresh()->status)->toBe('FAILED');
});

it('never sends anything to demo phones and keeps the fixed demo code', function () {
    config(['demo.enabled' => true]);
    Http::fake();
    etechConfigured();
    makeMobileTestUser('+237600000007');

    $id = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237600000007'])->json('data.challenge_id');

    Http::assertNothingSent();
    expect(Hash::check('123456', VerificationChallenge::findOrFail($id)->code_hash))->toBeTrue();
});

// ------------------------------------------------------------ 9.4 registration

it('registers with phone + password only and signs the app in (verification off by default)', function () {
    Http::fake();

    $response = $this->postJson('/api/v1/public/accounts', ['phone' => '670 00 44 01', 'password' => 'Secret123', 'device' => PSA_DEVICE]);

    $response->assertStatus(201)
        ->assertJsonPath('data.verification_required', false)
        ->assertJsonPath('data.user.phone_e164', '+237670004401')
        ->assertJsonPath('data.user.status', 'ACTIVE')
        ->assertJsonPath('data.user.phone_verified', false)
        ->assertJsonPath('data.user.email_verified_at', null)
        ->assertJsonPath('data.user.contacts_verified', false)
        ->assertJsonPath('data.workspaces.0.role_code', 'CUSTOMER');
    expect($response->json('data.access_token'))->not->toBeEmpty()
        ->and($response->json('data.refresh_token'))->not->toBeEmpty();
    Http::assertNothingSent();

    $this->withToken($response->json('data.access_token'))->getJson('/api/v1/auth/mobile/session')->assertOk();
});

it('accepts an optional email and a matching password_confirmation', function () {
    $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670004402', 'email' => 'Me@Example.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'full_name' => 'Awa'])
        ->assertStatus(201)->assertJsonPath('data.user.email', 'me@example.com');

    $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670004403', 'password' => 'Secret123', 'password_confirmation' => 'nope'])
        ->assertStatus(422)->assertJsonValidationErrors('password_confirmation');
});

it('requires a phone and an 8+ character password', function () {
    $this->postJson('/api/v1/public/accounts', ['password' => 'Secret123'])->assertStatus(422)->assertJsonValidationErrors('phone_e164');
    $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670004404'])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670004404', 'password' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('sends a code and withholds tokens when contact verification is required', function () {
    Http::fake(['*' => Http::response(['id' => 'sms-1'], 200)]);
    etechConfigured(['require_contact_verification' => true]);

    $response = $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670004405', 'password' => 'Secret123', 'verification_channel' => 'sms']);

    $response->assertStatus(201)
        ->assertJsonPath('data.verification_required', true)
        ->assertJsonPath('data.status', 'PENDING_VERIFICATION')
        ->assertJsonPath('data.verification_channel', 'sms')
        ->assertJsonMissingPath('data.access_token');

    $code = null;
    Http::assertSent(function ($r) use (&$code) {
        if ($r->url() === 'https://v1.api.etech-keys.com/api/v1/send-sms' && preg_match('/code is (\d{6})/', $r['msg'], $m)) {
            $code = $m[1];

            return true;
        }

        return false;
    });

    $verified = $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $response->json('data.challenge_id'), 'code' => $code, 'device' => PSA_DEVICE]);
    $verified->assertStatus(201)->assertJsonPath('data.user.status', 'ACTIVE')->assertJsonPath('data.user.phone_verified', true);
});

it('sends the registration code by email when chosen and mail is enabled', function () {
    Mail::fake();
    settingsRow(['require_contact_verification' => true, 'mail_enabled' => true, 'mail_host' => 'smtp.example.cm']);

    $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670004406', 'email' => 'awa@example.com', 'password' => 'Secret123', 'verification_channel' => 'email'])
        ->assertStatus(201)->assertJsonPath('data.verification_channel', 'email');
});

// ------------------------------------------------------------ 9.5 password login + reset

it('signs in with phone + password, same payload as OTP verify', function () {
    User::create(['full_name' => 'Pat', 'phone_e164' => '+237670005001', 'password' => 'Secret123', 'locale' => 'en', 'status' => 'ACTIVE']);

    $ok = $this->postJson('/api/v1/auth/mobile/password-login', ['phone' => '+237670005001', 'password' => 'Secret123', 'device' => PSA_DEVICE]);
    $ok->assertStatus(201)->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'expires_in', 'user' => ['id', 'phone_e164', 'email_verified_at', 'contacts_verified'], 'workspaces']]);
});

it('gives one generic 422 for a wrong password or unknown phone', function () {
    User::create(['full_name' => 'Pat', 'phone_e164' => '+237670005002', 'password' => 'Secret123', 'locale' => 'en', 'status' => 'ACTIVE']);

    $wrong = $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005002', 'password' => 'Wrong1234', 'device' => PSA_DEVICE])->assertStatus(422);
    $unknown = $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005099', 'password' => 'Wrong1234', 'device' => PSA_DEVICE])->assertStatus(422);

    expect($wrong->json('errors'))->toBe($unknown->json('errors'));
});

it('resets a forgotten password with a code and signs in', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);
    $user = User::create(['full_name' => 'Pat', 'phone_e164' => '+237670005003', 'password' => 'OldSecret1', 'locale' => 'en', 'status' => 'ACTIVE']);

    $this->postJson('/api/v1/auth/mobile/password/forgot', ['phone' => '+237670005003', 'channel' => 'sms'])->assertOk()->assertJsonStructure(['data' => ['challenge_id', 'expires_in']]);
    $code = extractMobileOtpCode();

    $this->postJson('/api/v1/auth/mobile/password/reset', ['phone' => '+237670005003', 'code' => '000000', 'password' => 'NewSecret1'])->assertStatus(422);

    $reset = $this->postJson('/api/v1/auth/mobile/password/reset', ['phone' => '+237670005003', 'code' => $code, 'password' => 'NewSecret1', 'device' => PSA_DEVICE]);
    $reset->assertOk();
    expect($reset->json('data.access_token'))->not->toBeEmpty();
    expect(Hash::check('NewSecret1', $user->fresh()->password))->toBeTrue();

    $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670005003', 'password' => 'NewSecret1', 'device' => PSA_DEVICE])->assertStatus(201);
});

it('a login OTP cannot be used as a password-reset code', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);
    User::create(['full_name' => 'Pat', 'phone_e164' => '+237670005004', 'password' => 'OldSecret1', 'locale' => 'en', 'status' => 'ACTIVE']);

    $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237670005004'])->assertOk();
    $code = extractMobileOtpCode();

    $this->postJson('/api/v1/auth/mobile/password/reset', ['phone_e164' => '+237670005004', 'code' => $code, 'password' => 'NewSecret1'])->assertStatus(422);
});

// ------------------------------------------------------------ email verification

it('email verification is a 202 no-op while mail is disabled', function () {
    $user = User::create(['full_name' => 'Pat', 'phone_e164' => '+237670006001', 'email' => 'pat@example.com', 'password' => 'Secret123', 'locale' => 'en', 'status' => 'ACTIVE']);
    Passport::actingAs($user);

    $this->postJson('/api/v1/me/email/verification')->assertStatus(202)->assertJsonPath('data.sent', false);
});

it('sends a signed link when mail is enabled, and the link verifies the email', function () {
    Mail::fake();
    settingsRow(['mail_enabled' => true, 'mail_host' => 'smtp.example.cm']);
    $user = User::create(['full_name' => 'Pat', 'phone_e164' => '+237670006002', 'email' => 'pat2@example.com', 'password' => 'Secret123', 'locale' => 'en', 'status' => 'ACTIVE']);
    Passport::actingAs($user);

    $this->postJson('/api/v1/me/email/verification')->assertStatus(202)->assertJsonPath('data.sent', true);

    $this->getJson('/api/v1/email/verify/'.$user->id.'/'.sha1('pat2@example.com'))->assertStatus(403);
    $url = URL::temporarySignedRoute('email.verify', now()->addHour(), ['user' => $user->id, 'hash' => sha1('pat2@example.com')]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.verified', true);
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

// ------------------------------------------------------------ demo accounts (C2/C3/W2)

it('gives the demo personas the configured demo password and lets them sign in with it', function () {
    config(['demo.enabled' => true, 'demo.password' => 'Demo@12345']);
    Http::fake();
    $this->seed(DemoMobileAccountSeeder::class);
    $this->seed(Database\Seeders\DatabaseSeeder::class);

    $personas = array_merge(
        array_column(DemoMobileAccountSeeder::ACCOUNTS, 'phone'),
        array_column(array_filter(Database\Seeders\DatabaseSeeder::DEMO_ACCOUNTS, fn ($a) => in_array($a['role_code'], ['AGENT', 'BROKER_STAFF'], true)), 'phone'),
    );

    foreach ($personas as $phone) {
        $this->postJson('/api/v1/auth/mobile/password-login', ['phone' => $phone, 'password' => 'Demo@12345', 'device' => PSA_DEVICE])->assertStatus(201);
    }
});

it('re-applies the demo password to personas on re-seed but never resets admin passwords', function () {
    config(['demo.enabled' => true, 'demo.password' => 'Demo@12345', 'demo.local_admin_password' => 'Admin-Initial-1']);
    $this->seed(Database\Seeders\DatabaseSeeder::class);

    $admin = User::where('phone_e164', '+237600000000')->firstOrFail();
    $broker = User::where('phone_e164', '+237600000007')->firstOrFail();
    expect(Hash::check('Admin-Initial-1', $admin->password))->toBeTrue();

    $admin->forceFill(['password' => 'AdminChanged1'])->save();
    $broker->forceFill(['password' => 'BrokerChanged1'])->save();
    $this->seed(Database\Seeders\DatabaseSeeder::class);

    expect(Hash::check('AdminChanged1', $admin->fresh()->password))->toBeTrue()
        ->and(Hash::check('Demo@12345', $broker->fresh()->password))->toBeTrue();
});

it('never issues the demo OTP to admin, finance, compliance or claims demo accounts', function () {
    config(['demo.enabled' => true]);
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);

    foreach (['+237600000000', '+237600000001', '+237600000002', '+237600000003', '+237600000005'] as $phone) {
        makeMobileTestUser($phone);
        expect(DemoMobileAccountSeeder::otpPhones())->not->toContain($phone);
        $id = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => $phone])->json('data.challenge_id');
        expect(Hash::check('123456', VerificationChallenge::findOrFail($id)->code_hash))->toBeFalse();
    }
});

it('refuses the demo code for a persona phone that holds an admin role', function () {
    config(['demo.enabled' => true]);
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);
    $user = makeMobileTestUser('+237600000101');
    makeMobileTestWorkspace($user, ['*'], 'PLATFORM_ADMIN');

    $id = $this->postJson('/api/v1/auth/mobile/password/forgot', ['phone_e164' => '+237600000101'])->json('data.challenge_id');
    expect(Hash::check('123456', VerificationChallenge::findOrFail($id)->code_hash))->toBeFalse();
});

it('demo accounts cannot reset or change their password', function () {
    config(['demo.enabled' => true, 'demo.password' => 'Demo@12345']);
    Http::fake();
    $this->seed(DemoMobileAccountSeeder::class);
    $phone = DemoMobileAccountSeeder::ACCOUNTS[0]['phone'];

    $this->postJson('/api/v1/auth/mobile/password/forgot', ['phone' => $phone])->assertOk();
    $this->postJson('/api/v1/auth/mobile/password/reset', ['phone' => $phone, 'code' => '123456', 'password' => 'Hijacked1'])
        ->assertStatus(422)->assertJsonValidationErrors('phone_e164');

    Passport::actingAs(User::where('phone_e164', $phone)->firstOrFail());
    $this->putJson('/api/v1/me/password', ['current_password' => 'Demo@12345', 'password' => 'Hijacked1', 'password_confirmation' => 'Hijacked1'], ['X-Tenant-Id' => App\Models\Tenant::where('slug', 'opesinsure-platform')->value('id')])
        ->assertStatus(422);
    expect(Hash::check('Demo@12345', User::where('phone_e164', $phone)->value('password')))->toBeTrue();
});

it('serves the demo password with the demo accounts only in demo mode', function () {
    config(['demo.password' => 'Demo@12345']);
    // The route is registered at boot from demo.enabled; .env.testing decides.
    $response = $this->getJson('/api/v1/public/demo-accounts');
    if (config('demo.enabled')) {
        $response->assertOk()->assertJsonPath('data.password', 'Demo@12345')->assertJsonPath('data.otp', '123456');
    } else {
        $response->assertNotFound();
    }
});

// ------------------------------------------------------------ W1 / W3 / W5 / W6

it('discards a never-verified password when the phone is first proven by a login code', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);
    $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670007001', 'password' => 'Squatter1'])->assertStatus(201);

    $id = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237670007001'])->json('data.challenge_id');
    $verified = $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $id, 'code' => extractMobileOtpCode(), 'device' => PSA_DEVICE]);

    $verified->assertStatus(201)->assertJsonPath('data.password_reset_required', true)->assertJsonPath('data.user.phone_verified', true);
    $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670007001', 'password' => 'Squatter1', 'device' => PSA_DEVICE])->assertStatus(422);
});

it('keeps the password when the registration code itself verifies the phone', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);
    settingsRow(['require_contact_verification' => true]);

    $id = $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670007002', 'password' => 'Mine12345'])->json('data.challenge_id');
    $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $id, 'code' => extractMobileOtpCode(), 'device' => PSA_DEVICE])
        ->assertStatus(201)->assertJsonPath('data.password_reset_required', false);

    $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670007002', 'password' => 'Mine12345', 'device' => PSA_DEVICE])->assertStatus(201);
});

it('verifies the phone from inside a session without touching the password', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);
    $token = $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670007003', 'password' => 'Mine12345'])->json('data.access_token');

    $id = $this->withToken($token)->postJson('/api/v1/me/phone/verification', ['channel' => 'sms'])->assertOk()->json('data.challenge_id');
    $this->withToken($token)->postJson('/api/v1/me/phone/verification/confirm', ['challenge_id' => $id, 'code' => extractMobileOtpCode()])
        ->assertOk()->assertJsonPath('data.user.phone_verified', true);

    $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237670007003', 'password' => 'Mine12345', 'device' => PSA_DEVICE])->assertStatus(201);
});

it('only accepts an invitation against a verified contact', function () {
    $tenant = App\Models\Tenant::create(['type' => 'BROKER', 'legal_name' => 'Invite Org W3', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $admin = makeMobileTestUser('+237670007010');
    makeMobileTestWorkspace($admin, ['identity.invite'], 'PLATFORM_ADMIN');
    $invitee = User::create(['full_name' => 'Unverified', 'phone_e164' => '+237670007011', 'password' => 'Mine12345', 'locale' => 'en', 'status' => 'ACTIVE']);

    $token = app(App\Application\Identity\InvitationService::class)->issue($tenant, $admin, null, '+237670007011', 'AGENT')['token'] ?? null;
    expect($token)->not->toBeNull();

    Passport::actingAs($invitee);
    $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertStatus(422);

    $invitee->forceFill(['phone_verified_at' => now()])->save();
    $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertStatus(200);
});

it('sees admin settings changes from a long-lived instance (queue worker) via the shared version key', function () {
    $worker = new PlatformSettings;
    expect($worker->supportContacts()['email'])->toBeNull();

    settingsRow(['support_email' => 'new@example.cm']);

    expect($worker->supportContacts()['email'])->toBe('new@example.cm');
});

it('revokes mobile refresh tokens when the password changes', function () {
    $session = $this->postJson('/api/v1/public/accounts', ['phone_e164' => '+237670007020', 'password' => 'Mine12345'])->json('data');
    $user = User::where('phone_e164', '+237670007020')->firstOrFail();
    $tenantId = $session['workspaces'][0]['tenant_id'];

    $this->withToken($session['access_token'])->putJson('/api/v1/me/password', ['current_password' => 'Mine12345', 'password' => 'Newer1234', 'password_confirmation' => 'Newer1234'], ['X-Tenant-Id' => $tenantId])
        ->assertOk();

    expect(App\Models\MobileRefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0);
    $this->postJson('/api/v1/auth/mobile/refresh', ['refresh_token' => $session['refresh_token']])->assertStatus(422);
});
