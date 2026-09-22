<?php

declare(strict_types=1);

use App\Application\Payments\Adapters\MtnMomoAdapter;
use App\Models\PaymentIntentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeMtnTestIntent(array $overrides = []): PaymentIntentRecord
{
    return (new PaymentIntentRecord())->forceFill(array_merge([
        'id' => (string) Str::uuid(),
        'amount_minor' => 150000,
        'currency' => 'XAF',
        'payer_phone_e164' => '+237670000000',
    ], $overrides));
}

it('authenticates, requests to pay, and returns the reference id as the provider reference', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay' => Http::response('', 202),
    ]);

    $intent = makeMtnTestIntent();
    $requestId = (string) Str::uuid();

    $result = (new MtnMomoAdapter)->requestCustomerAuthorization($intent, $requestId);

    expect($result->providerReference)->toBe($requestId);
    expect($result->status)->toBe('PENDING_CUSTOMER');

    Http::assertSent(function ($request) use ($requestId, $intent) {
        return str_contains($request->url(), '/collection/v1_0/requesttopay')
            && $request->hasHeader('X-Reference-Id', $requestId)
            && $request->hasHeader('Authorization', 'Bearer tok-123')
            && $request->hasHeader('Ocp-Apim-Subscription-Key', 'testing-subscription-key')
            && $request->hasHeader('X-Target-Environment', 'sandbox')
            && $request['amount'] === '150000'
            && $request['currency'] === 'XAF'
            && $request['externalId'] === $intent->id
            && $request['payer']['partyIdType'] === 'MSISDN'
            && $request['payer']['partyId'] === '237670000000';
    });
});

it('embeds the callback token and reference id in the X-Callback-Url header', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay' => Http::response('', 202),
    ]);

    $requestId = (string) Str::uuid();
    (new MtnMomoAdapter)->requestCustomerAuthorization(makeMtnTestIntent(), $requestId);

    Http::assertSent(function ($request) use ($requestId) {
        $callbackUrl = $request->header('X-Callback-Url')[0] ?? '';

        return str_contains($callbackUrl, 'reference_id='.$requestId)
            && str_contains($callbackUrl, 'token=testing-mtn-callback-token');
    });
});

it('caches the access token across calls instead of re-authenticating every time', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay' => Http::response('', 202),
    ]);

    $adapter = new MtnMomoAdapter;
    $adapter->requestCustomerAuthorization(makeMtnTestIntent(), (string) Str::uuid());
    $adapter->requestCustomerAuthorization(makeMtnTestIntent(), (string) Str::uuid());

    Http::assertSentCount(3); // 1 token fetch + 2 requesttopay calls, not 2 token fetches
});

it('throws when the provider rejects the request-to-pay with a non-202 status', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay' => Http::response(['message' => 'rejected'], 400),
    ]);

    expect(fn () => (new MtnMomoAdapter)->requestCustomerAuthorization(makeMtnTestIntent(), (string) Str::uuid()))
        ->toThrow(DomainException::class);
});

it('throws when authentication does not return an access token', function () {
    Http::fake(['*/collection/token/' => Http::response([], 200)]);

    expect(fn () => (new MtnMomoAdapter)->requestCustomerAuthorization(makeMtnTestIntent(), (string) Str::uuid()))
        ->toThrow(DomainException::class);
});

it('queries the requesttopay status resource and returns it verbatim', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => '150000'], 200),
    ]);

    $referenceId = (string) Str::uuid();
    $status = (new MtnMomoAdapter)->status($referenceId);

    expect($status['status'])->toBe('SUCCESSFUL');

    Http::assertSent(function ($request) use ($referenceId) {
        return str_contains($request->url(), "/collection/v1_0/requesttopay/{$referenceId}")
            && $request->method() === 'GET';
    });
});

it('throws when the status query fails', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response([], 500),
    ]);

    expect(fn () => (new MtnMomoAdapter)->status((string) Str::uuid()))->toThrow(DomainException::class);
});
