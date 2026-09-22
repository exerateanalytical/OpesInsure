<?php

declare(strict_types=1);

use App\Application\Integrations\WebhookDeliveryService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/helpers.php';

it('signs the payload with the subscription secret and marks a 2xx response as delivered', function () {
    [$subscription, $secret] = makeTestWebhookSubscription(User::factory()->create());
    Http::fake(['partner.example.test/*' => Http::response(['ok' => true], 200)]);

    $attempt = app(WebhookDeliveryService::class)->attempt($subscription, (string) Str::uuid(), 'policy.issued', ['policy_id' => 'p-1'], 1);

    expect($attempt->status)->toBe('DELIVERED');
    expect($attempt->delivered_at)->not->toBeNull();

    Http::assertSent(function ($request) use ($secret) {
        $signature = $request->header('X-OpesInsure-Signature')[0] ?? null;
        $timestamp = $request->header('X-OpesInsure-Timestamp')[0] ?? null;
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret);

        return $signature === $expected;
    });
});

it('schedules a retry on a 429', function () {
    [$subscription] = makeTestWebhookSubscription(User::factory()->create());
    Http::fake(['partner.example.test/*' => Http::response(['retry' => true], 429)]);

    $attempt = app(WebhookDeliveryService::class)->attempt($subscription, (string) Str::uuid(), 'policy.issued', [], 1);

    expect($attempt->status)->toBe('RETRY_SCHEDULED');
    expect($attempt->next_attempt_at)->not->toBeNull();
});

it('dead-letters a permanent 4xx rejection without scheduling a retry', function () {
    [$subscription] = makeTestWebhookSubscription(User::factory()->create());
    Http::fake(['partner.example.test/*' => Http::response(['error' => 'bad signature'], 401)]);

    $attempt = app(WebhookDeliveryService::class)->attempt($subscription, (string) Str::uuid(), 'policy.issued', [], 1);

    expect($attempt->status)->toBe('DEAD_LETTERED');
    expect($attempt->next_attempt_at)->toBeNull();
});

it('dead-letters once the maximum attempt count is reached, even for an otherwise-retryable error', function () {
    [$subscription] = makeTestWebhookSubscription(User::factory()->create());
    Http::fake(['partner.example.test/*' => Http::response([], 503)]);

    $attempt = app(WebhookDeliveryService::class)->attempt($subscription, (string) Str::uuid(), 'policy.issued', [], 8);

    expect($attempt->status)->toBe('DEAD_LETTERED');
});

it('replays a dead-lettered attempt as a manual, actor-attributed attempt, and audits it', function () {
    [$subscription] = makeTestWebhookSubscription(User::factory()->create());
    $actor = User::factory()->create();
    // fakeSequence(), not two Http::fake() calls — see the note in
    // DispatchIntegrationOutboxTest for why the latter silently fails to
    // override the first stub for the same URL pattern.
    Http::fakeSequence('partner.example.test/*')
        ->push(['bad' => true], 401)
        ->push(['ok' => true], 200);
    $original = app(WebhookDeliveryService::class)->attempt($subscription, (string) Str::uuid(), 'policy.issued', ['policy_id' => 'p-1'], 1);
    expect($original->status)->toBe('DEAD_LETTERED');

    $replayed = app(WebhookDeliveryService::class)->replay($original, ['policy_id' => 'p-1'], $actor);

    expect($replayed->status)->toBe('DELIVERED');
    expect($replayed->is_manual_replay)->toBeTrue();
    expect($replayed->replayed_by)->toBe($actor->id);
    expect($replayed->attempt)->toBe($original->attempt + 1);
    expect(DB::table('audit_log')->where('action', 'integration.webhook.replayed')->where('subject_id', $replayed->id)->exists())->toBeTrue();
});

it('refuses to replay an attempt that is not dead-lettered', function () {
    [$subscription] = makeTestWebhookSubscription(User::factory()->create());
    $actor = User::factory()->create();
    Http::fake(['partner.example.test/*' => Http::response(['ok' => true], 200)]);
    $delivered = app(WebhookDeliveryService::class)->attempt($subscription, (string) Str::uuid(), 'policy.issued', [], 1);

    expect(fn () => app(WebhookDeliveryService::class)->replay($delivered, [], $actor))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});
