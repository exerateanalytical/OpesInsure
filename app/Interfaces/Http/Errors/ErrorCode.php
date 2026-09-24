<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Errors;

/**
 * Machine-readable error codes carried as `code` on every /api error body
 * (REQ-API-003). Clients branch on these, never on the human `message`.
 *
 * Codes already emitted by existing middleware (STEP_UP_REQUIRED,
 * APP_VERSION_UNSUPPORTED, ...) are preserved verbatim by ProblemDetails —
 * an explicit `code` in a body always wins over the status-based default.
 */
final class ErrorCode
{
    // Generic, inferred from HTTP status when a handler gives no code.
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';
    public const FORBIDDEN = 'FORBIDDEN';
    public const NOT_FOUND = 'NOT_FOUND';
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    public const CONFLICT = 'CONFLICT';
    public const LOCKED = 'LOCKED';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const SERVER_ERROR = 'SERVER_ERROR';
    public const REQUEST_FAILED = 'REQUEST_FAILED';

    // Concurrency / idempotency (REQ-API-002, REQ-IDM-001).
    public const STALE_RECORD = 'STALE_RECORD';
    public const PRECONDITION_REQUIRED = 'PRECONDITION_REQUIRED';
    public const DUPLICATE_SUBMISSION = 'DUPLICATE_SUBMISSION';
    public const IDEMPOTENCY_KEY_REQUIRED = 'IDEMPOTENCY_KEY_REQUIRED';
    public const IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';

    // Business conditions services can raise via ApiProblemException.
    public const AUTHORITY_EXCEEDED = 'AUTHORITY_EXCEEDED';
    public const PAYMENT_OK_ISSUANCE_FAILED = 'PAYMENT_OK_ISSUANCE_FAILED';
    public const INTEGRATION_UNAVAILABLE = 'INTEGRATION_UNAVAILABLE';
    public const STEP_UP_REQUIRED = 'STEP_UP_REQUIRED';

    public static function forStatus(int $status): string
    {
        return match (true) {
            $status === 401 => self::UNAUTHENTICATED,
            $status === 403 => self::FORBIDDEN,
            $status === 404 => self::NOT_FOUND,
            $status === 405 => self::METHOD_NOT_ALLOWED,
            $status === 409 => self::CONFLICT,
            $status === 412 => self::STALE_RECORD,
            $status === 422 => self::VALIDATION_FAILED,
            $status === 423 => self::LOCKED,
            $status === 428 => self::PRECONDITION_REQUIRED,
            $status === 429 => self::RATE_LIMITED,
            in_array($status, [502, 503, 504], true) => self::INTEGRATION_UNAVAILABLE,
            $status >= 500 => self::SERVER_ERROR,
            default => self::REQUEST_FAILED,
        };
    }
}
