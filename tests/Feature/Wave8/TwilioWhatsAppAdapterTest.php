<?php

declare(strict_types=1);

use App\Application\Notifications\Adapters\TwilioWhatsAppAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('prefixes both From and To with whatsapp: for the WhatsApp channel', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM999', 'status' => 'queued'], 201)]);

    $result = (new TwilioWhatsAppAdapter)->send('+237670000000', '', 'Your code is 123456', 'idem-1');

    expect($result->providerReference)->toBe('SM999');

    Http::assertSent(function ($request) {
        return $request['From'] === 'whatsapp:+14155238886'
            && $request['To'] === 'whatsapp:+237670000000';
    });
});

it('does not double-prefix a destination already carrying whatsapp:', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM998'], 201)]);

    (new TwilioWhatsAppAdapter)->send('whatsapp:+237670000000', '', 'Body', 'idem-2');

    Http::assertSent(fn ($request) => $request['To'] === 'whatsapp:+237670000000');
});
