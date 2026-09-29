<?php

declare(strict_types=1);

namespace App\Application\Notifications\Sms;

use App\Application\Notifications\Sms\Providers\AfricasTalkingSmsProvider;
use App\Application\Notifications\Sms\Providers\GenericHttpSmsProvider;
use App\Application\Notifications\Sms\Providers\OrangeSmsProvider;
use App\Application\Notifications\Sms\Providers\TwilioSmsProvider;
use App\Models\SmsMessage;
use App\Models\SmsProviderConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Throwable;

/**
 * Single SMS sending path for the admin-configured providers (Integrations → SMS providers):
 * PRIMARY first, then FALLBACK (ACTIVE connections only); a provider failure moves on to the next one.
 * Before sending: per-number rate limit, GSM-7/UCS-2 length check (max segments). Every attempt is logged in
 * sms_messages with a masked destination, and the connection's health is updated. With no active provider the
 * status is CONFIG_REQUIRED. MTN Cameroon has no public self-serve SMS API: MTN bulk SMS is reached through an
 * aggregator, configured as a generic HTTP gateway.
 */
final class SmsGateway
{
    public const PROVIDERS = ['orange', 'twilio', 'africastalking', 'generic_http'];

    public const PURPOSES = ['OTP', 'NOTIFICATION', 'TEST'];

    public function provider(string $key): SmsProvider
    {
        return match ($key) {
            'orange' => new OrangeSmsProvider,
            'twilio' => new TwilioSmsProvider,
            'africastalking' => new AfricasTalkingSmsProvider,
            'generic_http' => new GenericHttpSmsProvider,
            default => throw new InvalidArgumentException('Unknown SMS provider.'),
        };
    }

    /** @return list<SmsProviderConnection> PRIMARY then FALLBACK, active and complete */
    public function chain(): array
    {
        return SmsProviderConnection::query()->where('status', 'ACTIVE')->whereIn('role', ['PRIMARY', 'FALLBACK'])
            ->orderByRaw("CASE role WHEN 'PRIMARY' THEN 0 ELSE 1 END")->orderBy('created_at')->get()
            ->filter(fn (SmsProviderConnection $c) => $this->isComplete($c))->values()->all();
    }

    public function isConfigured(): bool
    {
        return $this->chain() !== [];
    }

    /** CONFIGURED when a PRIMARY/FALLBACK provider is active, else CONFIG_REQUIRED. */
    public function status(): string
    {
        return $this->isConfigured() ? 'CONFIGURED' : 'CONFIG_REQUIRED';
    }

    public function isComplete(SmsProviderConnection $c): bool
    {
        if (! in_array($c->provider, self::PROVIDERS, true)) {
            return false;
        }
        $p = $this->provider($c->provider);
        foreach ($p->requiredSettings() as $k) {
            if ((string) $c->setting($k, '') === '') {
                return false;
            }
        }
        foreach ($p->requiredSecrets() as $k) {
            if ($c->secret($k) === '') {
                return false;
            }
        }

        return true;
    }

    public static function mask(string $phone): string
    {
        $p = preg_replace('/[^\d+]/', '', $phone) ?? '';
        $len = strlen($p);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($p, 0, 5).str_repeat('*', $len - 7).substr($p, -2);
    }

    /**
     * Sends through the chain (or only through $only, for an admin test). Throws SmsDeliveryException
     * (status FAILED | RATE_LIMITED | CONFIG_REQUIRED | REJECTED) when nothing accepted the message.
     *
     * @return array{provider: string, connection_id: string, reference: string, encoding: string, segments: int}
     */
    public function send(string $toE164, string $message, string $purpose = 'NOTIFICATION', ?SmsProviderConnection $only = null): array
    {
        $to = '+'.ltrim(preg_replace('/[^\d+]/', '', $toE164) ?? '', '+');
        $shape = SmsEncoding::analyse($message);

        if (! preg_match('/^\+\d{8,15}$/', $to)) {
            throw $this->refuse($to, $shape, $purpose, 'REJECTED', 'Invalid phone number (E.164 expected).');
        }

        $max = (int) config('services.sms.max_segments', 6);
        if (trim($message) === '' || $shape['segments'] > $max) {
            throw $this->refuse($to, $shape, $purpose, 'REJECTED', "Message is empty or longer than {$max} SMS parts ({$shape['encoding']}).");
        }

        $key = 'sms:number:'.hash('sha256', $to);
        $limit = (int) config('services.sms.per_number_per_hour', 10);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw $this->refuse($to, $shape, $purpose, 'RATE_LIMITED', 'Too many SMS to this number; try again later.');
        }

        $chain = $only !== null ? [$only] : $this->chain();
        if ($chain === []) {
            Log::critical('sms.config_required', ['purpose' => $purpose]);
            throw $this->refuse($to, $shape, $purpose, 'CONFIG_REQUIRED', 'No SMS provider is configured.');
        }

        RateLimiter::hit($key, 3600);
        $errors = [];

        foreach ($chain as $i => $connection) {
            try {
                if (! $this->isComplete($connection)) {
                    throw new SmsDeliveryException('Provider settings are incomplete.');
                }
                $reference = $this->provider($connection->provider)->send($connection, $to, $message);
                $this->log($to, $shape, $purpose, 'SENT', $connection, $i + 1, $reference, null);
                $connection->forceFill(['health_status' => 'OK', 'consecutive_failures' => 0, 'last_success_at' => now()])->save();

                return ['provider' => $connection->provider, 'connection_id' => $connection->id, 'reference' => $reference,
                    'encoding' => $shape['encoding'], 'segments' => $shape['segments']];
            } catch (Throwable $e) {
                $error = mb_substr($e instanceof SmsDeliveryException ? $e->getMessage() : 'Provider unreachable: '.class_basename($e), 0, 255);
                $errors[] = "{$connection->provider}: {$error}";
                $this->log($to, $shape, $purpose, 'FAILED', $connection, $i + 1, null, $error);
                $connection->forceFill(['health_status' => 'FAILING', 'consecutive_failures' => $connection->consecutive_failures + 1,
                    'last_failure_at' => now(), 'last_error' => $error])->save();
            }
        }

        Log::critical('sms.delivery_failed', ['purpose' => $purpose, 'failures' => $errors]);

        throw new SmsDeliveryException('Every configured SMS provider failed: '.implode(' | ', $errors), 'FAILED');
    }

    private function refuse(string $to, array $shape, string $purpose, string $status, string $why): SmsDeliveryException
    {
        $this->log($to, $shape, $purpose, $status, null, 0, null, $why);

        return new SmsDeliveryException($why, $status);
    }

    private function log(string $to, array $shape, string $purpose, string $status, ?SmsProviderConnection $c, int $attempt, ?string $reference, ?string $error): void
    {
        try {
            SmsMessage::create([
                'connection_id' => $c?->id, 'provider' => $c?->provider, 'purpose' => in_array($purpose, self::PURPOSES, true) ? $purpose : 'NOTIFICATION',
                'destination_masked' => self::mask($to), 'destination_hash' => hash('sha256', $to),
                'encoding' => $shape['encoding'], 'length' => min($shape['length'], 65535), 'segments' => $shape['segments'],
                'status' => $status, 'attempt' => $attempt, 'provider_reference' => ($reference ?? '') !== '' ? $reference : null,
                'error' => $error !== null ? mb_substr($error, 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
