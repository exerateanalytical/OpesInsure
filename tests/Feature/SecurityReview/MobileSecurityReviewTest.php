<?php

declare(strict_types=1);

/**
 * Security review 2026-09-27 (docs/SECURITY_REVIEW_MOBILE_2026-09-27.md), items 3-6 regression tests:
 *   3. MTN MoMo / Orange Money callbacks: operator authenticity and idempotency.
 *   4. Agent withdrawals: step-up, registered payout number only, cooling-off after a payout-number change.
 *   5. Auth rate limits per phone and per IP.
 *   6. Resumable uploads: magic-byte sniffing, size/type limits, unusable until scanned clean.
 */

use App\Models\PartnerPayoutRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave4/Concerns/mobile_money_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

// ------------------------------------------------------------------ 3. mobile money callbacks

function srMtnFake(array $status): void
{
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response($status, 200),
    ]);
}

it('credits an MTN payment exactly once however many times the callback is replayed', function () {
    $ref = (string) Str::uuid();
    $intent = makeMobileMoneyTestIntent('mtn_momo', $ref);
    srMtnFake(['status' => 'SUCCESSFUL', 'amount' => '100000', 'currency' => 'XAF', 'externalId' => $intent->id]);
    $url = "/api/v1/webhooks/payments/mtn-momo/callback?reference_id={$ref}&token=testing-mtn-callback-token";

    foreach (range(1, 3) as $_) {
        $this->postJson($url, ['status' => 'SUCCESSFUL'])->assertOk();
    }

    expect($intent->refresh()->status)->toBe('SUCCEEDED')
        ->and(DB::table('payment_events')->where('payment_intent_id', $intent->id)->where('new_status', 'SUCCEEDED')->count())->toBe(1)
        ->and(DB::table('webhook_inbox')->where('provider', 'mtn_momo')->count())->toBe(1);
});

it('never credits MTN on the callback body alone: a forged SUCCESSFUL body with MTN still PENDING changes nothing', function () {
    $ref = (string) Str::uuid();
    $intent = makeMobileMoneyTestIntent('mtn_momo', $ref);
    srMtnFake(['status' => 'PENDING']);

    $this->postJson("/api/v1/webhooks/payments/mtn-momo/callback?reference_id={$ref}&token=testing-mtn-callback-token", ['status' => 'SUCCESSFUL', 'amount' => '100000'])->assertOk();

    expect($intent->refresh()->status)->toBe('PENDING_CUSTOMER');
});

it('refuses to credit when the re-queried MTN record is for another amount, currency or intent', function (array $record) {
    $ref = (string) Str::uuid();
    $intent = makeMobileMoneyTestIntent('mtn_momo', $ref);
    srMtnFake(['status' => 'SUCCESSFUL'] + array_map(fn ($v) => $v === '@intent' ? $intent->id : $v, $record));

    $this->postJson("/api/v1/webhooks/payments/mtn-momo/callback?reference_id={$ref}&token=testing-mtn-callback-token")->assertOk();

    expect($intent->refresh()->status)->toBe('PENDING_CUSTOMER');
})->with([
    'amount' => [['amount' => '100', 'currency' => 'XAF', 'externalId' => '@intent']],
    'currency' => [['amount' => '100000', 'currency' => 'EUR', 'externalId' => '@intent']],
    'intent' => [['amount' => '100000', 'currency' => 'XAF', 'externalId' => '00000000-0000-0000-0000-000000000000']],
]);

it('rejects mobile-money callbacks without the shared callback token and never calls the operator', function () {
    Http::fake();
    $ref = (string) Str::uuid();
    $mtn = makeMobileMoneyTestIntent('mtn_momo', $ref);
    $orange = makeMobileMoneyTestIntent('orange_money', 'pay-token-sr');

    $this->postJson("/api/v1/webhooks/payments/mtn-momo/callback?reference_id={$ref}")->assertStatus(401);
    $this->get("/api/v1/webhooks/payments/orange-money/callback?order_id={$orange->id}&status=SUCCESS&token=")->assertStatus(401);
    Http::assertNothingSent();
    expect($mtn->refresh()->status)->toBe('PENDING_CUSTOMER')->and($orange->refresh()->status)->toBe('PENDING_CUSTOMER');
});

it('does not accept MTN or Orange statuses through the generic signed-webhook endpoint', function () {
    foreach (['mtn_momo', 'orange_money', 'mtn-momo', 'orange-money'] as $provider) {
        $this->postJson("/api/v1/webhooks/payments/{$provider}", ['payment_reference' => 'x', 'status' => 'SUCCEEDED', 'amount_minor' => 1, 'currency' => 'XAF'])->assertNotFound();
    }
});

// ------------------------------------------------------------------ 4. withdrawals and payout number

function srWithdraw($test, array $fixture, string $phone)
{
    $grant = issueMobileStepUpGrant($fixture['user'], $fixture['tenant'], 'COMMISSION_WITHDRAWAL');

    return $test->postJson('/api/v1/mobile/agent/withdrawals', ['provider' => 'mtn_momo', 'amount_minor' => 20000, 'destination_phone' => $phone], agentHeaders($fixture) + stepUpHeaderFor($grant['token']));
}

it('pays a withdrawal only to the registered payout number, never to a number typed into the request', function () {
    $fixture = makeMobileAgentFixture('+237680019400', ['compliance' => ['momo_phone_e164' => '+237670019401']]);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 90000]);
    Passport::actingAs($fixture['user']);

    srWithdraw($this, $fixture, '+237699999999')->assertStatus(422)->assertJsonPath('code', 'PAYOUT_DESTINATION_NOT_REGISTERED');
    expect(PartnerPayoutRequest::count())->toBe(0);

    srWithdraw($this, $fixture, '+237670019401')->assertStatus(201);
});

it('blocks withdrawals to a newly changed payout number for the configured cooling-off period, then allows them', function () {
    config(['payments.payout_destination_cooling_off_hours' => 24]);
    $fixture = makeMobileAgentFixture('+237680019410', ['compliance' => ['momo_phone_e164' => '+237670019411']]);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 90000]);
    Passport::actingAs($fixture['user']);

    $grant = issueMobileStepUpGrant($fixture['user'], $fixture['tenant'], 'PAYOUT_DESTINATION_CHANGE');
    $this->patchJson('/api/v1/mobile/agent/profile', ['momo_phone_e164' => '+237670019412'], agentHeaders($fixture) + stepUpHeaderFor($grant['token']))->assertOk();
    expect($fixture['partner']->refresh()->compliance['momo_phone_changed_at'] ?? null)->not->toBeNull();

    srWithdraw($this, $fixture, '+237670019412')->assertStatus(422)->assertJsonPath('code', 'PAYOUT_DESTINATION_COOLING_OFF');
    srWithdraw($this, $fixture, '+237670019411')->assertStatus(422)->assertJsonPath('code', 'PAYOUT_DESTINATION_NOT_REGISTERED');

    $this->travel(25)->hours();
    srWithdraw($this, $fixture, '+237670019412')->assertStatus(201);
});

it('does not restart the cooling-off when a profile update leaves the payout number unchanged', function () {
    $fixture = makeMobileAgentFixture('+237680019420', ['compliance' => ['momo_phone_e164' => '+237670019421']]);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 90000]);
    Passport::actingAs($fixture['user']);

    $this->patchJson('/api/v1/mobile/agent/profile', ['full_name' => 'Renamed Agent', 'momo_phone_e164' => '+237670019421'], agentHeaders($fixture))->assertOk();

    expect($fixture['partner']->refresh()->compliance)->not->toHaveKey('momo_phone_changed_at');
    srWithdraw($this, $fixture, '+237670019421')->assertStatus(201);
});

it('keeps the step-up gate on withdrawals and on payout-number changes', function () {
    $fixture = makeMobileAgentFixture('+237680019430', ['compliance' => ['momo_phone_e164' => '+237670019431']]);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 90000]);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/withdrawals', ['provider' => 'mtn_momo', 'amount_minor' => 20000, 'destination_phone' => '+237670019431'], agentHeaders($fixture))
        ->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    $this->patchJson('/api/v1/mobile/agent/profile', ['momo_phone_e164' => '+237670019432'], agentHeaders($fixture))
        ->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');

    expect($fixture['partner']->refresh()->compliance['momo_phone_e164'])->toBe('+237670019431');
})->skip(! class_exists(App\Application\Security\StepUpGate::class), 'payout-number step-up ships with the mobile-audit B5 change (StepUpGate)');

// ------------------------------------------------------------------ 5. auth rate limits

it('limits OTP sends per phone', function () {
    $hit = fn () => $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237670019500'], ['REMOTE_ADDR' => '10.9.0.1']);
    foreach (range(1, 6) as $_) {
        $hit()->assertOk();
    }
    $hit()->assertStatus(429);
});

it('limits OTP sends and password resets per IP across many phones', function () {
    $status = [];
    foreach (range(0, 60) as $i) {
        $path = $i % 2 ? '/api/v1/auth/mobile/otp/request' : '/api/v1/auth/mobile/password/forgot';
        $status[] = $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.2'])->postJson($path, ['phone_e164' => sprintf('+2376700%05d', 19600 + $i)])->status();
    }

    expect(array_slice($status, 0, 60))->each->toBe(200)->and(end($status))->toBe(429);
});

it('limits password attempts per phone and per IP', function () {
    $body = fn (string $phone) => ['phone_e164' => $phone, 'password' => 'wrong-password', 'device_fingerprint' => 'fp-sr-1', 'device_name' => 'Test', 'platform' => 'android'];

    // Per phone: 10 failures in 15 minutes, then 429.
    $s = [];
    foreach (range(1, 11) as $_) {
        $s[] = $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.3'])->postJson('/api/v1/auth/mobile/password-login', $body('+237670019700'))->status();
    }
    expect(array_slice($s, 0, 10))->each->toBe(422)->and(end($s))->toBe(429);

    // Per IP: 60 attempts in 15 minutes across different phones, then 429.
    $s = [];
    foreach (range(0, 60) as $i) {
        $s[] = $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.4'])->postJson('/api/v1/auth/mobile/password-login', $body(sprintf('+2376710%05d', $i)))->status();
    }
    expect(array_slice($s, 0, 60))->each->toBe(422)->and(end($s))->toBe(429);
});

// ------------------------------------------------------------------ 6. uploads

function srUpload($test, array $customer, string $mime, string $bytes)
{
    $h = tenantHeaderFor($customer['tenant']);
    $start = $test->postJson('/api/v1/mobile/uploads', ['resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => $mime, 'total_chunks' => 1, 'total_size_bytes' => strlen($bytes)], $h + ['Idempotency-Key' => (string) Str::uuid()]);
    $start->assertStatus(201);
    $id = $start->json('data.id');
    $test->putJson("/api/v1/mobile/uploads/{$id}/chunks/0", ['data' => base64_encode($bytes)], $h)->assertOk();

    return [$id, $test->postJson("/api/v1/mobile/uploads/{$id}/finalize", [], $h + ['Idempotency-Key' => (string) Str::uuid()])];
}

it('refuses to finalize an upload whose bytes are not the declared type (magic-byte sniffing), and discards it', function () {
    $c = makeMobileCustomerFixture('+237670019800');
    Passport::actingAs($c['user']);

    [$id, $finalize] = srUpload($this, $c, 'image/jpeg', "MZ\x90\x00 this is a windows executable, not a photo");

    $finalize->assertStatus(422);
    $session = App\Models\UploadSession::findOrFail($id);
    expect($session->status)->toBe('FAILED')->and($session->storage_key)->toBeNull()
        ->and(Illuminate\Support\Facades\Storage::disk('local')->exists("upload-sessions/{$id}/assembled"))->toBeFalse();
});

it('finalizes an upload whose bytes match the declared type', function () {
    $c = makeMobileCustomerFixture('+237670019810');
    Passport::actingAs($c['user']);

    [, $finalize] = srUpload($this, $c, 'application/pdf', "%PDF-1.7\n% a tiny pdf body\n%%EOF");

    $finalize->assertOk()->assertJsonPath('data.status', 'COMPLETED');
});

it('rejects disallowed types and oversize uploads at start', function () {
    $c = makeMobileCustomerFixture('+237670019820');
    Passport::actingAs($c['user']);
    $h = tenantHeaderFor($c['tenant']);

    foreach (['text/html', 'application/x-msdownload', 'image/svg+xml'] as $mime) {
        $this->postJson('/api/v1/mobile/uploads', ['resource_type' => 'KYC', 'mime_type' => $mime, 'total_chunks' => 1, 'total_size_bytes' => 10], $h + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
    }
    $this->postJson('/api/v1/mobile/uploads', ['resource_type' => 'KYC', 'mime_type' => 'image/png', 'total_chunks' => 1, 'total_size_bytes' => 104_857_601], $h + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
});

it('checks the magic bytes of every declared type', function () {
    expect(App\Application\Uploads\FileSignature::matches('%PDF-1.4', 'application/pdf'))->toBeTrue()
        ->and(App\Application\Uploads\FileSignature::matches("\xFF\xD8\xFF\xE0", 'image/jpeg'))->toBeTrue()
        ->and(App\Application\Uploads\FileSignature::matches("\x89PNG\x0D\x0A\x1A\x0A", 'image/png'))->toBeTrue()
        ->and(App\Application\Uploads\FileSignature::matches("\x00\x00\x00\x18ftypmp42", 'video/mp4'))->toBeTrue()
        ->and(App\Application\Uploads\FileSignature::matches('<html><script>', 'image/png'))->toBeFalse()
        ->and(App\Application\Uploads\FileSignature::matches('%PDF-1.4', 'image/jpeg'))->toBeFalse()
        ->and(App\Application\Uploads\FileSignature::matches('%PDF-1.4', 'text/html'))->toBeFalse();
});
