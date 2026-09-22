<?php

declare(strict_types=1);

namespace App\Application\Notifications\Adapters;

use App\Mail\NotificationMail;
use DomainException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uses Laravel's own Mail system rather than a third-party HTTP API, so it
 * works with whatever mailer driver is configured in config/mail.php
 * (SMTP, SES, Mailgun, Postmark, ...) without picking one vendor here.
 *
 * Honest limitation: plain SMTP has no universal, synchronous delivery
 * receipt the way Twilio's StatusCallback does — "sent without throwing" is
 * the only signal available here. A queued/DEAD_LETTERED transition still
 * happens on failure, but there is no adapter-level DELIVERED confirmation
 * for email (see NotificationDispatchService and the progress report).
 */
final class SmtpEmailAdapter implements NotificationChannelAdapter
{
    public function channel(): string
    {
        return 'EMAIL';
    }

    public function send(string $destination, string $subject, string $body, string $idempotencyKey): ChannelSendResult
    {
        if ($destination === '' || ! str_contains($destination, '@')) {
            throw new DomainException('A valid email destination is required.');
        }

        try {
            Mail::to($destination)->send(new NotificationMail($subject, $body));
        } catch (Throwable $e) {
            throw new DomainException('Email delivery failed: '.$e->getMessage(), previous: $e);
        }

        return new ChannelSendResult('smtp', (string) Str::uuid());
    }
}
