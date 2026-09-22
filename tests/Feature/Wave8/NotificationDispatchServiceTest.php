<?php

declare(strict_types=1);

use App\Application\Notifications\NotificationDispatchService;
use App\Models\NotificationDelivery;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/notification_helpers.php';

it('resolves the destination, renders the template, sends via the real adapter, and marks the delivery SENT', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM111', 'status' => 'queued'], 201)]);

    $delivery = makeNotificationTestDelivery('SMS', '+237670000000');

    $result = app(NotificationDispatchService::class)->dispatch($delivery);

    expect($result->status)->toBe('SENT');
    expect($result->provider)->toBe('twilio');
    expect($result->provider_reference)->toBe('SM111');
    expect($result->sent_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request['Body'] === 'Hello Amina, your code is 123456.');

    expect(DB::table('notification_attempts')->where('notification_delivery_id', $delivery->id)->where('status', 'SENT')->count())->toBe(1);
});

it('does nothing when the delivery is not QUEUED (already claimed or settled)', function () {
    Http::fake();

    $delivery = makeNotificationTestDelivery('SMS', '+237670000000', ['status' => 'SENT']);

    $result = app(NotificationDispatchService::class)->dispatch($delivery);

    expect($result->status)->toBe('SENT');
    Http::assertNothingSent();
});

it('reschedules with backoff on a send failure while attempts remain', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'rejected'], 400)]);

    $delivery = makeNotificationTestDelivery('SMS', '+237670000000', ['max_attempts' => 5]);

    $result = app(NotificationDispatchService::class)->dispatch($delivery);

    expect($result->status)->toBe('QUEUED');
    expect($result->attempts)->toBe(1);
    expect($result->next_attempt_at)->not->toBeNull();
    expect($result->next_attempt_at->isFuture())->toBeTrue();

    expect(DB::table('notification_attempts')->where('notification_delivery_id', $delivery->id)->where('status', 'FAILED')->count())->toBe(1);
});

it('dead-letters once max_attempts is reached', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'rejected'], 400)]);

    $delivery = makeNotificationTestDelivery('SMS', '+237670000000', ['max_attempts' => 1]);

    $result = app(NotificationDispatchService::class)->dispatch($delivery);

    expect($result->status)->toBe('DEAD_LETTERED');
    expect($result->attempts)->toBe(1);
});

it('refuses to send when the resolved destination no longer matches the stored destination_hash', function () {
    Http::fake();

    $delivery = makeNotificationTestDelivery('SMS', '+237670000000', ['destination_hash' => hash('sha256', '+237699999999')]);

    $result = app(NotificationDispatchService::class)->dispatch($delivery);

    expect($result->status)->toBe('QUEUED'); // recorded as a failed attempt, rescheduled — not silently dropped
    Http::assertNothingSent();
});

it('fails when the party has no primary contact for the channel', function () {
    Http::fake();

    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'No Contact', 'status' => 'ACTIVE']);
    $template = makeNotificationTestTemplate('SMS');

    $delivery = NotificationDelivery::create([
        'party_id' => $party->id,
        'template_id' => $template->id,
        'channel' => 'SMS',
        'destination_hash' => hash('sha256', '+237670000000'),
        'status' => 'QUEUED',
        'attempts' => 0,
        'max_attempts' => 5,
        'payload' => ['name' => 'Amina', 'code' => '123456'],
        'idempotency_key' => (string) Illuminate\Support\Str::uuid(),
    ]);

    $result = app(NotificationDispatchService::class)->dispatch($delivery);

    expect($result->status)->toBe('QUEUED');
    Http::assertNothingSent();
});
