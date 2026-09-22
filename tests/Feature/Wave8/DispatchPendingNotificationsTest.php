<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/notification_helpers.php';

it('dispatches due QUEUED deliveries and reports sent/failed/dead-lettered counts', function () {
    $healthy = makeNotificationTestDelivery('SMS', '+237670000001');
    $broken = makeNotificationTestDelivery('SMS', '+237670000002', ['max_attempts' => 1]);

    Http::fake([
        'api.twilio.com/*' => Http::sequence()
            ->push(['sid' => 'SM1'], 201)
            ->push(['message' => 'rejected'], 400),
    ]);

    expect(Artisan::call('notifications:dispatch-pending'))->toBe(0);
    expect(Artisan::output())->toContain('Claimed: 2')->toContain('Sent: 1')->toContain('Dead-lettered: 1');

    expect($healthy->refresh()->status)->toBe('SENT');
    expect($broken->refresh()->status)->toBe('DEAD_LETTERED');
});

it('does not claim a QUEUED delivery whose next_attempt_at is still in the future', function () {
    Http::fake();

    makeNotificationTestDelivery('SMS', '+237670000000', ['next_attempt_at' => now()->addMinutes(10)]);

    expect(Artisan::call('notifications:dispatch-pending'))->toBe(0);
    expect(Artisan::output())->toContain('Claimed: 0');
    Http::assertNothingSent();
});

it('does not claim a delivery that is not QUEUED', function () {
    Http::fake();

    makeNotificationTestDelivery('SMS', '+237670000000', ['status' => 'CANCELLED']);

    expect(Artisan::call('notifications:dispatch-pending'))->toBe(0);
    expect(Artisan::output())->toContain('Claimed: 0');
    Http::assertNothingSent();
});
