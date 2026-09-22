<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/helpers.php';

function insertTestOutboxMessage(string $eventName, array $payload): string
{
    $id = (string) Str::uuid();
    DB::table('outbox_messages')->insert([
        'id' => $id, 'event_name' => $eventName, 'event_version' => 1, 'aggregate_type' => 'test', 'aggregate_id' => (string) Str::uuid(),
        'payload' => json_encode($payload), 'metadata' => '{}', 'occurred_at' => now(), 'attempts' => 0,
    ]);

    return $id;
}

it('delivers a pending outbox event to every active matching subscription and marks it published', function () {
    $actor = User::factory()->create();
    [$subscription] = makeTestWebhookSubscription($actor);
    Http::fake(['partner.example.test/*' => Http::response(['ok' => true], 200)]);

    $eventId = insertTestOutboxMessage('policy.issued', ['policy_id' => 'p-1', 'carrier_id' => 'c-1']);

    $this->artisan('integration:dispatch-outbox')->assertExitCode(0);

    expect(DB::table('outbox_messages')->where('id', $eventId)->value('published_at'))->not->toBeNull();
    expect(DB::table('integration_delivery_attempts')->where('integration_webhook_subscription_id', $subscription->id)->where('event_id', $eventId)->where('status', 'DELIVERED')->exists())->toBeTrue();
});

it('does not deliver to a subscription for a different event name', function () {
    $actor = User::factory()->create();
    [$subscription] = makeTestWebhookSubscription($actor, eventName: 'claim.transitioned');
    Http::fake(['partner.example.test/*' => Http::response(['ok' => true], 200)]);

    insertTestOutboxMessage('policy.issued', ['policy_id' => 'p-1', 'carrier_id' => 'c-1']);

    $this->artisan('integration:dispatch-outbox')->assertExitCode(0);

    Http::assertNothingSent();
    expect(DB::table('integration_delivery_attempts')->where('integration_webhook_subscription_id', $subscription->id)->exists())->toBeFalse();
});

it('does not delegate to a suspended connection\'s subscription', function () {
    $actor = User::factory()->create();
    [$subscription] = makeTestWebhookSubscription($actor);
    app(App\Application\Integrations\IntegrationClientLifecycleService::class)->suspend($subscription->client, 'REVIEW', 'x', $actor);
    Http::fake(['partner.example.test/*' => Http::response(['ok' => true], 200)]);

    insertTestOutboxMessage('policy.issued', ['policy_id' => 'p-1']);

    $this->artisan('integration:dispatch-outbox')->assertExitCode(0);

    Http::assertNothingSent();
});

it('retries a previously RETRY_SCHEDULED attempt once its backoff window has elapsed', function () {
    $actor = User::factory()->create();
    [$subscription] = makeTestWebhookSubscription($actor);
    $eventId = insertTestOutboxMessage('policy.issued', ['policy_id' => 'p-1']);

    // Http::fake() calls accumulate rather than replace (Factory::fake() merges
    // into stubCallbacks), so a second Http::fake() for the same URL pattern
    // would never actually override the first — fakeSequence() is the correct
    // tool for "first call fails, second call succeeds".
    Http::fakeSequence('partner.example.test/*')
        ->push([], 503)
        ->push(['ok' => true], 200);

    $this->artisan('integration:dispatch-outbox')->assertExitCode(0);

    $firstAttempt = DB::table('integration_delivery_attempts')->where('event_id', $eventId)->first();
    expect($firstAttempt->status)->toBe('RETRY_SCHEDULED');

    // Force the backoff window to have already elapsed, then succeed on retry.
    DB::table('integration_delivery_attempts')->where('id', $firstAttempt->id)->update(['next_attempt_at' => now()->subMinute()]);
    $this->artisan('integration:dispatch-outbox')->assertExitCode(0);

    $latest = DB::table('integration_delivery_attempts')->where('event_id', $eventId)->orderByDesc('attempt')->first();
    expect($latest->status)->toBe('DELIVERED');
    expect($latest->attempt)->toBe(2);
});

it('is safe to run twice on the same outbox row without delivering twice', function () {
    $actor = User::factory()->create();
    [$subscription] = makeTestWebhookSubscription($actor);
    Http::fake(['partner.example.test/*' => Http::response(['ok' => true], 200)]);

    insertTestOutboxMessage('policy.issued', ['policy_id' => 'p-1']);

    $this->artisan('integration:dispatch-outbox')->assertExitCode(0);
    $this->artisan('integration:dispatch-outbox')->assertExitCode(0); // outbox row already published_at — second run is a no-op for it

    Http::assertSentCount(1);
});

it('keeps retrying across more than two consecutive cycles', function () {
    // Regression test: unique() on the RETRY_SCHEDULED query picked the
    // FIRST row per (subscription, event) — the oldest attempt, once a
    // second one existed — which then failed the "is this the latest"
    // filter and silently dropped the pair, permanently stopping retries
    // after exactly one cycle. Caught this live, not by this suite —
    // hence needing four cycles here, not two.
    $actor = User::factory()->create();
    [$subscription] = makeTestWebhookSubscription($actor);
    $eventId = insertTestOutboxMessage('policy.issued', ['policy_id' => 'p-1']);

    Http::fake(['partner.example.test/*' => Http::response([], 503)]);
    $this->artisan('integration:dispatch-outbox')->assertExitCode(0);

    foreach ([2, 3, 4] as $expectedAttempt) {
        DB::table('integration_delivery_attempts')->where('status', 'RETRY_SCHEDULED')->update(['next_attempt_at' => now()->subMinute()]);
        $this->artisan('integration:dispatch-outbox')->assertExitCode(0);

        $latest = DB::table('integration_delivery_attempts')->where('event_id', $eventId)->orderByDesc('attempt')->first();
        expect($latest->attempt)->toBe($expectedAttempt);
        expect($latest->status)->toBe('RETRY_SCHEDULED');
    }
});

it('opens the circuit after the failure threshold and stops attempting delivery', function () {
    $actor = User::factory()->create();
    [$subscription] = makeTestWebhookSubscription($actor);
    insertTestOutboxMessage('policy.issued', ['policy_id' => 'p-1']);

    Http::fake(['partner.example.test/*' => Http::response([], 503)]);
    $this->artisan('integration:dispatch-outbox')->assertExitCode(0); // attempt 1

    for ($i = 0; $i < 4; $i++) { // attempts 2-5 — the 5th failure crosses the threshold
        DB::table('integration_delivery_attempts')->where('status', 'RETRY_SCHEDULED')->update(['next_attempt_at' => now()->subMinute()]);
        $this->artisan('integration:dispatch-outbox')->assertExitCode(0);
    }

    expect($subscription->refresh()->circuit_state)->toBe('OPEN');

    Http::fake(); // any further call would be unexpected now
    DB::table('integration_delivery_attempts')->where('status', 'RETRY_SCHEDULED')->update(['next_attempt_at' => now()->subMinute()]);
    $this->artisan('integration:dispatch-outbox')->assertExitCode(0);

    Http::assertNothingSent();
    expect(DB::table('audit_log')->where('action', 'integration.circuit.opened')->where('subject_id', $subscription->id)->exists())->toBeTrue();
});

it('closes the circuit again after a successful delivery', function () {
    $actor = User::factory()->create();
    [$subscription] = makeTestWebhookSubscription($actor);
    insertTestOutboxMessage('policy.issued', ['policy_id' => 'p-1']);

    // Http::fake() accumulates rather than replaces for the same URL pattern
    // (see the comment above) — fakeSequence() is required whenever a test
    // needs the same endpoint to behave differently across two calls.
    Http::fakeSequence('partner.example.test/*')
        ->push([], 503)
        ->push(['ok' => true], 200);
    $this->artisan('integration:dispatch-outbox')->assertExitCode(0);
    $subscription->refresh()->update(['consecutive_failures' => 3]); // pretend it's partway to the threshold

    DB::table('integration_delivery_attempts')->where('status', 'RETRY_SCHEDULED')->update(['next_attempt_at' => now()->subMinute()]);
    $this->artisan('integration:dispatch-outbox')->assertExitCode(0);

    expect($subscription->refresh()->consecutive_failures)->toBe(0);
    expect($subscription->circuit_state)->toBe('CLOSED');
});
