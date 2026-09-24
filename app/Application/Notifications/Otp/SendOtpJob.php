<?php

declare(strict_types=1);

namespace App\Application\Notifications\Otp;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one code off the request path, so the HTTP response time never
 * depends on providers (or on whether the number is registered). The
 * payload carries the plaintext code, hence ShouldBeEncrypted. No retries:
 * OtpDeliveryService already falls back across providers, and a stale code
 * arriving minutes later is worse than none.
 */
final class SendOtpJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public string $phoneE164,
        public string $code,
        public string $message,
        public ?string $channel = null,
        public ?string $challengeId = null,
    ) {
    }

    /**
     * Queues the send (default). With OTP_DELIVERY_MODE=after_response the
     * same job runs in the web process after the response has been sent:
     * still off the request path, but needs no queue worker.
     */
    public static function send(string $phoneE164, string $code, string $message, ?string $channel = null, ?string $challengeId = null): void
    {
        if (config('services.otp.delivery_mode') === 'after_response') {
            self::dispatchAfterResponse($phoneE164, $code, $message, $channel, $challengeId);

            return;
        }

        self::dispatch($phoneE164, $code, $message, $channel, $challengeId);
    }

    public function handle(OtpDeliveryService $delivery): void
    {
        $delivery->send($this->phoneE164, $this->code, $this->message, $this->channel, $this->challengeId);
    }
}
