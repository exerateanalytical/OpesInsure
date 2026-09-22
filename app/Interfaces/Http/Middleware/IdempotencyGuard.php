<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generic idempotent-replay guard for mutating mobile endpoints, backed by
 * the shared idempotency_keys table (tenant_id, user_id, key, operation,
 * request_hash, response_status, response_body, expires_at — see
 * database/migrations/2026_09_20_000001_create_opesinsure_core.php). Any
 * batch can opt any POST/PUT/PATCH mobile route into this without writing
 * its own check-then-insert logic against a one-off column.
 *
 * Usage: ->middleware('idempotency:<operation-name>')
 *
 *   Route::post('mobile/uploads', [Controller::class, 'start'])
 *       ->middleware('idempotency:mobile.uploads.start');
 *
 * <operation-name> is an arbitrary caller-chosen string identifying the
 * route/action (matches the `operation` column). It is part of the
 * uniqueness key specifically so the same literal Idempotency-Key value
 * used against two different operations never collides — a mobile client
 * generating one UUID per logical user action, reused as the key on
 * whichever single endpoint that action calls, is exactly the intended use.
 *
 * Client contract: send an `Idempotency-Key` header (any opaque string,
 * typically a client-generated UUID) on every request to a route guarded
 * by this middleware.
 *
 * Behaviour:
 *  - No header                             -> 422, {"errors": {"idempotency_key": [...]}}.
 *  - New (key, operation) pair              -> request proceeds; the response
 *                                              (status + body) is persisted
 *                                              for replay.
 *  - Same key+operation, identical body     -> the stored response is
 *                                              returned verbatim; the route
 *                                              handler never runs again, so
 *                                              there is no duplicate side
 *                                              effect. Response carries
 *                                              X-Idempotent-Replay: true.
 *  - Same key+operation, different body     -> 409: a real client bug (key
 *                                              reused for a different
 *                                              payload) — rejected outright
 *                                              rather than guessing whether
 *                                              to replay the old response or
 *                                              execute the new one.
 *  - Same key+operation, still in flight    -> 409: guards concurrent
 *                                              duplicate submits (e.g. a
 *                                              double-tap racing itself),
 *                                              not just sequential retries.
 *  - Record past its expires_at             -> treated as unseen: reclaimed
 *                                              and the handler runs fresh.
 *
 * Only a response that came back from a handler that did NOT throw is
 * cached. If the handler throws, the claim is released (row deleted) before
 * the exception is rethrown, so a corrected retry with the same key gets a
 * clean re-attempt — a failed request had no side effect worth replaying.
 */
final class IdempotencyGuard
{
    private const DEFAULT_TTL_HOURS = 24;

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            return response()->json(['errors' => ['idempotency_key' => [__('wave12.idempotency_key_required')]]], 422);
        }

        $tenantId = app()->bound(TenantContext::class) ? rescue(fn () => app(TenantContext::class)->id(), null, false) : null;
        $userId = $request->user()?->id;
        $requestHash = self::hashPayload((array) $request->json()->all());

        $claim = $this->claim($tenantId, $userId, $key, $operation, $requestHash);

        if ($claim instanceof Response) {
            return $claim; // Replay or conflict — short-circuit without touching the handler.
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $claim->delete();

            throw $e;
        }

        $claim->update(['response_status' => $response->getStatusCode(), 'response_body' => json_decode($response->getContent(), true)]);

        return $response;
    }

    /**
     * Returns either the freshly-claimed (still-pending) IdempotencyKey row
     * to proceed with, or a Response to short-circuit with (replay/conflict).
     */
    private function claim(?string $tenantId, ?string $userId, string $key, string $operation, string $requestHash): IdempotencyKey|Response
    {
        $existing = IdempotencyKey::where('tenant_id', $tenantId)->where('user_id', $userId)->where('key', $key)->where('operation', $operation)->first();

        if ($existing && $existing->expires_at->isFuture()) {
            return $this->resolveExisting($existing, $requestHash);
        }

        $existing?->delete(); // Past expiry: treat as unseen.

        try {
            return IdempotencyKey::create([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'key' => $key, 'operation' => $operation,
                'request_hash' => $requestHash, 'response_status' => null, 'response_body' => null,
                'expires_at' => now()->addHours(self::DEFAULT_TTL_HOURS),
            ]);
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }

            // Lost a race to a concurrent request claiming the same key.
            $existing = IdempotencyKey::where('tenant_id', $tenantId)->where('user_id', $userId)->where('key', $key)->where('operation', $operation)->first();

            return $existing
                ? $this->resolveExisting($existing, $requestHash)
                : response()->json(['errors' => ['idempotency_key' => [__('wave12.idempotency_key_in_progress')]]], 409);
        }
    }

    private function resolveExisting(IdempotencyKey $existing, string $requestHash): Response
    {
        if ($existing->response_status === null) {
            return response()->json(['errors' => ['idempotency_key' => [__('wave12.idempotency_key_in_progress')]]], 409);
        }

        if ($existing->request_hash !== $requestHash) {
            return response()->json(['errors' => ['idempotency_key' => [__('wave12.idempotency_key_conflict')]]], 409);
        }

        return response()->json($existing->response_body, $existing->response_status)->header('X-Idempotent-Replay', 'true');
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }

    private static function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    /** Recursively sorts associative-array keys so field order in the
     * client's JSON never causes a false idempotency-key mismatch. */
    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
