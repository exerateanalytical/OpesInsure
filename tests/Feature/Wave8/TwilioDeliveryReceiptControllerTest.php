<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/notification_helpers.php';

/**
 * Twilio's own signing algorithm (https://www.twilio.com/docs/usage/security#validating-requests):
 * base64(hmac_sha1(url + sorted-concatenated-params, auth_token)). Computed
 * independently here (not by calling the controller's private method) so
 * the test would actually catch a broken implementation.
 */
function signTwilioParams(string $url, array $params, string $authToken): string
{
    ksort($params);
    $data = $url;

    foreach ($params as $key => $value) {
        $data .= $key.$value;
    }

    return base64_encode(hash_hmac('sha1', $data, $authToken, true));
}

it('marks a delivery DELIVERED on a validly signed Twilio callback', function () {
    $delivery = makeNotificationTestDelivery('SMS', '+237670000000', ['status' => 'SENT', 'provider' => 'twilio', 'provider_reference' => 'SM123']);

    $url = route('notifications.twilio.callback');
    $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
    $signature = signTwilioParams($url, $params, 'testing-twilio-auth-token');

    $response = $this->withHeaders(['X-Twilio-Signature' => $signature])->post($url, $params);

    $response->assertStatus(200)->assertJson(['accepted' => true]);
    expect($delivery->refresh()->status)->toBe('DELIVERED');
    expect($delivery->refresh()->delivered_at)->not->toBeNull();
});

it('marks a delivery FAILED on a delivery failure callback and records the error code', function () {
    $delivery = makeNotificationTestDelivery('SMS', '+237670000000', ['status' => 'SENT', 'provider' => 'twilio', 'provider_reference' => 'SM456']);

    $url = route('notifications.twilio.callback');
    $params = ['MessageSid' => 'SM456', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003'];
    $signature = signTwilioParams($url, $params, 'testing-twilio-auth-token');

    $this->withHeaders(['X-Twilio-Signature' => $signature])->post($url, $params)->assertStatus(200);

    $delivery->refresh();
    expect($delivery->status)->toBe('FAILED');
    expect($delivery->failure_code)->toBe('30003');
});

it('rejects a callback with an invalid signature', function () {
    $delivery = makeNotificationTestDelivery('SMS', '+237670000000', ['status' => 'SENT', 'provider' => 'twilio', 'provider_reference' => 'SM789']);

    $url = route('notifications.twilio.callback');

    $this->withHeaders(['X-Twilio-Signature' => 'not-a-real-signature'])
        ->post($url, ['MessageSid' => 'SM789', 'MessageStatus' => 'delivered'])
        ->assertStatus(401);

    expect($delivery->refresh()->status)->toBe('SENT');
});

it('rejects a callback tampered after signing (params changed but signature reused)', function () {
    $delivery = makeNotificationTestDelivery('SMS', '+237670000000', ['status' => 'SENT', 'provider' => 'twilio', 'provider_reference' => 'SM999']);

    $url = route('notifications.twilio.callback');
    $signature = signTwilioParams($url, ['MessageSid' => 'SM999', 'MessageStatus' => 'delivered'], 'testing-twilio-auth-token');

    // Same signature, but the attacker flips the status after the fact.
    $this->withHeaders(['X-Twilio-Signature' => $signature])
        ->post($url, ['MessageSid' => 'SM999', 'MessageStatus' => 'failed'])
        ->assertStatus(401);

    expect($delivery->refresh()->status)->toBe('SENT');
});

it('ignores a status callback for an unknown or already-settled provider_reference', function () {
    $delivery = makeNotificationTestDelivery('SMS', '+237670000000', ['status' => 'DELIVERED', 'provider' => 'twilio', 'provider_reference' => 'SM111']);

    $url = route('notifications.twilio.callback');
    $params = ['MessageSid' => 'SM111', 'MessageStatus' => 'failed'];
    $signature = signTwilioParams($url, $params, 'testing-twilio-auth-token');

    $this->withHeaders(['X-Twilio-Signature' => $signature])->post($url, $params)->assertStatus(200);

    // Already DELIVERED — a later "failed" callback must not un-deliver it.
    expect($delivery->refresh()->status)->toBe('DELIVERED');
});
