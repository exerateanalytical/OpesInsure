<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * End-to-end correlation id (REQ-NFR-001, REQ-API-002).
 *
 * Accepts the caller's `X-Correlation-ID` (or legacy `X-Request-Id`) when it
 * is a safe token, otherwise mints a UUID. The id is then:
 *  - put in Laravel's Context, which is automatically attached to every log
 *    line AND serialized into every queued job dispatched during the
 *    request (jobs/webhook deliveries/notifications inherit it);
 *  - written back onto the request as X-Request-Id so AuditWriter (which
 *    reads that header for audit_log.correlation_id) records the same id;
 *  - echoed on the response as X-Correlation-ID and X-Request-Id, and in
 *    every error body as `correlation_id` (see ProblemDetails).
 *
 * Services needing it explicitly (ledger journals, outbox) call
 * AssignCorrelationId::current().
 */
final class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public const CONTEXT_KEY = 'correlation_id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::sanitize($request->headers->get(self::HEADER))
            ?? self::sanitize($request->headers->get('X-Request-Id'))
            ?? (string) Str::uuid();

        Context::add(self::CONTEXT_KEY, $id);
        $request->headers->set(self::HEADER, $id);
        $request->headers->set('X-Request-Id', $id);

        $response = $next($request);

        $response->headers->set(self::HEADER, $id);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    public static function current(): ?string
    {
        $id = Context::get(self::CONTEXT_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    private static function sanitize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match('/^[A-Za-z0-9._:\-]{8,128}$/', $value) ? $value : null;
    }
}
