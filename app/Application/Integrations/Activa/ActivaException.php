<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use RuntimeException;

/**
 * A failed Activa call, classified. The message is built by us from the classification only — never from a response
 * body or a request — so it is safe to log, store and show (no secrets, no personal data).
 */
final class ActivaException extends RuntimeException
{
    public const CONFIG_REQUIRED = 'CONFIG_REQUIRED';

    public const SUBSCRIPTION_KEY_REJECTED = 'SUBSCRIPTION_KEY_REJECTED';

    public const AUTH_FAILED = 'AUTH_FAILED';

    public const CIRCUIT_OPEN = 'CIRCUIT_OPEN';

    public const UNAVAILABLE = 'UNAVAILABLE';

    public const REJECTED = 'REJECTED';

    public const NOT_FOUND = 'NOT_FOUND';

    public const INVALID_RESPONSE = 'INVALID_RESPONSE';

    public const MAPPING_REQUIRED = 'MAPPING_REQUIRED';

    /** Worth retrying later without anyone changing anything. */
    public const TRANSIENT = [self::CIRCUIT_OPEN, self::UNAVAILABLE];

    /** Waiting on configuration / Activa's approval: retried automatically once credentials work. */
    public const WAITING_ON_CONFIG = [self::CONFIG_REQUIRED, self::SUBSCRIPTION_KEY_REJECTED, self::AUTH_FAILED];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $service = null,
        public readonly ?string $operation = null,
    ) {
        parent::__construct($message);
    }

    public static function make(string $code, ?string $service = null, ?string $operation = null, ?int $status = null, ?string $detail = null): self
    {
        $base = match ($code) {
            self::CONFIG_REQUIRED => 'Activa credentials are not configured for this service.',
            self::SUBSCRIPTION_KEY_REJECTED => 'Activa rejected the API subscription key (401): the subscription is not approved yet or the key is wrong.',
            self::AUTH_FAILED => 'Activa rejected the service login (401): check the user / client credentials.',
            self::CIRCUIT_OPEN => 'Activa calls are paused after repeated failures (circuit breaker open).',
            self::UNAVAILABLE => 'Activa did not answer successfully (timeout or server error) after retries.',
            self::REJECTED => 'Activa refused the request.',
            self::NOT_FOUND => 'Activa does not know this record.',
            self::INVALID_RESPONSE => 'Activa answered with an unexpected response.',
            self::MAPPING_REQUIRED => 'A value needed by Activa is missing from our record or mapping.',
            default => 'Activa call failed.',
        };
        $where = trim(($service ?? '').' '.($operation ?? ''));

        return new self($code, $base.($where !== '' ? " [{$where}]" : '').($status ? " HTTP {$status}." : '').($detail ? ' '.$detail : ''), $status, $service, $operation);
    }

    public function isTransient(): bool
    {
        return in_array($this->errorCode, self::TRANSIENT, true);
    }

    public function waitsOnConfig(): bool
    {
        return in_array($this->errorCode, self::WAITING_ON_CONFIG, true);
    }
}
