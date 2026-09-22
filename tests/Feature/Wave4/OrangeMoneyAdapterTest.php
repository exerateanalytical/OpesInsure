<?php

declare(strict_types=1);

use App\Application\Payments\Adapters\OrangeMoneyAdapter;
use App\Models\PaymentIntentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeOrangeTestIntent(array $overrides = []): PaymentIntentRecord
{
    return (new PaymentIntentRecord())->forceFill(array_merge([
        'id' => (string) Str::uuid(),
        'amount_minor' => 150000,
        'currency' => 'XAF',
        'payer_phone_e164' => '+237670000000',
    ], $overrides));
}

it('authenticates, initiates a web payment, and returns the pay_token as the provider reference', function () {
    Http::fake([
        '*/oauth/v3/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/orange-money-webpay/*/v1/webpayment' => Http::response([
            'payment_url' => 'https://orange-money.test/pay/abc',
            'pay_token' => 'pay-token-xyz',
            'notif_token' => 'notif-xyz',
        ], 201),
    ]);

    $intent = makeOrangeTestIntent();
    $requestId = (string) Str::uuid();

    $result = (new OrangeMoneyAdapter)->requestCustomerAuthorization($intent, $requestId);

    expect($result->providerReference)->toBe('pay-token-xyz');
    expect($result->status)->toBe('PENDING_CUSTOMER');
    expect($result->safeResponse['payment_url'])->toBe('https://orange-money.test/pay/abc');

    Http::assertSent(function ($request) use ($intent) {
        return str_contains($request->url(), '/orange-money-webpay/cm/v1/webpayment')
            && $request->hasHeader('Authorization', 'Bearer tok-123')
            && $request['order_id'] === $intent->id
            && $request['amount'] === '150000'
            && $request['currency'] === 'XAF'
            && $request['merchant_key'] === 'testing-merchant-key';
    });
});

it('embeds the callback token in the notif_url sent to Orange', function () {
    Http::fake([
        '*/oauth/v3/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/orange-money-webpay/*/v1/webpayment' => Http::response(['payment_url' => 'x', 'pay_token' => 'pay-token-xyz', 'notif_token' => 'n'], 201),
    ]);

    (new OrangeMoneyAdapter)->requestCustomerAuthorization(makeOrangeTestIntent(), (string) Str::uuid());

    Http::assertSent(fn ($request) => str_contains($request->url(), '/webpayment')
        && str_contains((string) $request['notif_url'], 'token=testing-orange-callback-token'));
});

it('caches the access token across calls instead of re-authenticating every time', function () {
    Http::fake([
        '*/oauth/v3/token' => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600], 200),
        '*/orange-money-webpay/*/v1/webpayment' => Http::response(['payment_url' => 'x', 'pay_token' => 'p', 'notif_token' => 'n'], 201),
    ]);

    $adapter = new OrangeMoneyAdapter;
    $adapter->requestCustomerAuthorization(makeOrangeTestIntent(), (string) Str::uuid());
    $adapter->requestCustomerAuthorization(makeOrangeTestIntent(), (string) Str::uuid());

    Http::assertSentCount(3); // 1 token fetch + 2 webpayment calls, not 2 token fetches
});

it('throws when the provider does not return a pay_token', function () {
    Http::fake([
        '*/oauth/v3/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/orange-money-webpay/*/v1/webpayment' => Http::response(['payment_url' => 'x'], 201),
    ]);

    expect(fn () => (new OrangeMoneyAdapter)->requestCustomerAuthorization(makeOrangeTestIntent(), (string) Str::uuid()))
        ->toThrow(DomainException::class);
});

it('queries transactionstatus with order_id, amount, and pay_token, and returns it verbatim', function () {
    Http::fake([
        '*/oauth/v3/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/orange-money-webpay/*/v1/transactionstatus' => Http::response(['status' => 'SUCCESS'], 200),
    ]);

    $status = (new OrangeMoneyAdapter)->status('pay-token-xyz', 'order-1', 150000);

    expect($status['status'])->toBe('SUCCESS');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/orange-money-webpay/cm/v1/transactionstatus')
            && $request['order_id'] === 'order-1'
            && $request['amount'] === '150000'
            && $request['pay_token'] === 'pay-token-xyz';
    });
});

it('throws when the status query fails', function () {
    Http::fake([
        '*/oauth/v3/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/orange-money-webpay/*/v1/transactionstatus' => Http::response([], 500),
    ]);

    expect(fn () => (new OrangeMoneyAdapter)->status('pay-token-xyz', 'order-1', 150000))->toThrow(DomainException::class);
});
