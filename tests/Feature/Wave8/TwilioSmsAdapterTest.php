<?php

declare(strict_types=1);

use App\Application\Notifications\Adapters\TwilioSmsAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('sends an SMS via the Twilio Messages resource with Basic auth and the configured From number', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201)]);

    $result = (new TwilioSmsAdapter)->send('+237670000000', '', 'Your code is 123456', 'idem-1');

    expect($result->provider)->toBe('twilio');
    expect($result->providerReference)->toBe('SM123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/Accounts/ACtestingaccountsid00000000000000/Messages.json')
            && $request['From'] === '+15005550006'
            && $request['To'] === '+237670000000'
            && $request['Body'] === 'Your code is 123456'
            && str_contains((string) $request['StatusCallback'], 'webhooks/notifications/twilio/callback');
    });
});

it('throws when Twilio rejects the message', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'invalid number'], 400)]);

    expect(fn () => (new TwilioSmsAdapter)->send('+237670000000', '', 'Body', 'idem-2'))->toThrow(DomainException::class);
});

it('throws when Twilio does not return a message sid', function () {
    Http::fake(['api.twilio.com/*' => Http::response([], 201)]);

    expect(fn () => (new TwilioSmsAdapter)->send('+237670000000', '', 'Body', 'idem-3'))->toThrow(DomainException::class);
});
