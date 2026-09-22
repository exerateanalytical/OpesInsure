<?php

declare(strict_types=1);

use App\Application\Notifications\Adapters\SmtpEmailAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('sends a plain-text email via the Mail facade', function () {
    Mail::fake();

    $result = (new SmtpEmailAdapter)->send('customer@example.test', 'Your policy', 'Thanks for your payment.', 'idem-1');

    expect($result->provider)->toBe('smtp');
    expect($result->providerReference)->not->toBe('');

    Mail::assertSentCount(1);
});

it('rejects a destination that is not a valid-looking email address', function () {
    expect(fn () => (new SmtpEmailAdapter)->send('not-an-email', 'Subject', 'Body', 'idem-2'))->toThrow(DomainException::class);
});

it('wraps a mail transport failure in a DomainException', function () {
    Mail::shouldReceive('to')->once()->with('customer@example.test')->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP connection refused'));

    expect(fn () => (new SmtpEmailAdapter)->send('customer@example.test', 'Subject', 'Body', 'idem-3'))->toThrow(DomainException::class);
});
