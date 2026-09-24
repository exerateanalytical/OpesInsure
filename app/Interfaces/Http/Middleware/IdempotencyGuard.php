<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ErrorCode;
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

    /**
     * $mode 'optional' + $operation 'auto' is the global /api/v1 coverage
     * (REQ-IDM-001): every mutation that SENDS an Idempotency-Key is
     * de-duplicated even if its route never opted in, while a mutation
     * without the header behaves exactly as before (the mobile client only
     * sends the header on operations it marks idempotent). Routes that
     * already declare `idempotency:<op>` are left to that declaration.
     */
    public function handle(Request $request, Closure $next, string $operation, string $mode = 'required'): Response
    {
        $key = $request->header('Idempotency-Key');
        $optional = $mode === 'optional';

        if ($optional && (! $key || ! $this->shouldAutoGuard($request))) {
            return $next($request);
        }

        if ($operation === 'auto') {
            // Scoped to the concrete path (not the template) so one key reused
            // across different records never replays the wrong response.
            $operation = 'auto:'.sha1($request->getMethod().' '.$request->path());
        }

        if (! $key) {
            return response()->json(['message' => __('wave12.idempotency_key_required'), 'code' => ErrorCode::IDEMPOTENCY_KEY_REQUIRED, 'errors' => ['idempotency_key' => [__('wave12.idempotency_key_required')]]], 422);
        }

        if (strlen($key) > 255) {
            return response()->json(['message' => __('api_errors.idempotency_key_invalid'), 'code' => ErrorCode::VALIDATION_FAILED, 'errors' => ['idempotency_key' => [__('api_errors.idempotency_key_invalid')]]], 422);
        }

        $tenantId = app()->bound(TenantContext::class) ? rescue(fn () => app(TenantContext::class)->id(), null, false) : null;
        $userId = $request->user()?->id;
        $requestHash = self::hashPayload(self::payloadOf($request));

        $claim = $this->claim($tenantId, $userId, $key, $operation, $requestHash, $optional);

        if ($claim instanceof Response) {
            return $claim; // Replay or conflict — short-circuit without touching the handler.
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $claim->delete();

            throw $e;
        }

        // Exceptions thrown by the handler are rendered by the routing
        // pipeline before they reach here, so "threw" shows up as a status.
        // Transient / not-processed outcomes (5xx, 401, 419, 429) and
        // non-JSON bodies (file streams) are never cached: release the claim
        // so the client's same-key retry actually re-executes.
        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getContent(), true);

        if ($status >= 500 || in_array($status, [401, 419, 429], true) || ! $response instanceof \Illuminate\Http\JsonResponse) {
            $claim->delete();

            return $response;
        }

        $claim->update(['response_status' => $status, 'response_body' => $decoded]);

        return $response;
    }

    private function shouldAutoGuard(Request $request): bool
    {
        if (! in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) || ! $request->is('api/v1/*') || $request->is('api/v1/*webhook*')) {
            return false;
        }

        $route = $request->route();
        if (! is_object($route)) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && (str_starts_with($middleware, 'idempotency:') || str_starts_with($middleware, self::class.':'))) {
                return false; // Route already declares its own operation name.
            }
        }

        // Controllers that already consume Idempotency-Key themselves
        // (service-level dedup with their own replay semantics, e.g. refunds
        // returning 200 on replay) keep that behaviour untouched.
        return ! self::controllerHandlesKey($route->getControllerClass());
    }

    /** @var array<string, bool> */
    private static array $selfManaged = [];

    private static function controllerHandlesKey(?string $class): bool
    {
        if ($class === null || ! class_exists($class)) {
            return false;
        }

        return self::$selfManaged[$class] ??= (static function () use ($class): bool {
            $file = (new \ReflectionClass($class))->getFileName();
            $source = $file ? (string) @file_get_contents($file) : '';

            return (bool) preg_match('/Idempotency-Key|idempotency_key|idempotencyKey/i', $source);
        })();
    }

    /** JSON body, or form fields plus a content hash of each uploaded file
     * (a multipart retry with a different file must not replay). */
    private static function payloadOf(Request $request): array
    {
        if ($request->isJson()) {
            return (array) $request->json()->all();
        }

        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            foreach ((array) $file as $i => $f) {
                $files[$name.'.'.$i] = $f instanceof \Illuminate\Http\UploadedFile && $f->isValid() ? hash_file('sha256', $f->getRealPath()) : null;
            }
        }

        return ['input' => $request->except(array_keys($request->allFiles())), 'files' => $files];
    }

    /**
     * Returns either the freshly-claimed (still-pending) IdempotencyKey row
     * to proceed with, or a Response to short-circuit with (replay/conflict).
     */
    private function claim(?string $tenantId, ?string $userId, string $key, string $operation, string $requestHash, bool $lenient = false): IdempotencyKey|Response
    {
        $existing = IdempotencyKey::where('tenant_id', $tenantId)->where('user_id', $userId)->where('key', $key)->where('operation', $operation)->first();

        // Lenient (auto/optional coverage): a completed record with a
        // DIFFERENT body is treated as a new request, not a 409 — callers
        // that never opted in may legitimately reuse a key; only exact
        // duplicates are replayed.
        $reusedForNewBody = $lenient && $existing && $existing->response_status !== null && $existing->request_hash !== $requestHash;

        if ($existing && $existing->expires_at->isFuture() && ! $reusedForNewBody) {
            return $this->resolveExisting($existing, $requestHash);
        }

        $existing?->delete(); // Past expiry (or lenient new body): treat as unseen.

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
                : response()->json(['message' => __('wave12.idempotency_key_in_progress'), 'code' => ErrorCode::DUPLICATE_SUBMISSION, 'errors' => ['idempotency_key' => [__('wave12.idempotency_key_in_progress')]]], 409);
        }
    }

    private function resolveExisting(IdempotencyKey $existing, string $requestHash): Response
    {
        if ($existing->response_status === null) {
            return response()->json(['message' => __('wave12.idempotency_key_in_progress'), 'code' => ErrorCode::DUPLICATE_SUBMISSION, 'errors' => ['idempotency_key' => [__('wave12.idempotency_key_in_progress')]]], 409);
        }

        if ($existing->request_hash !== $requestHash) {
            return response()->json(['message' => __('wave12.idempotency_key_conflict'), 'code' => ErrorCode::IDEMPOTENCY_KEY_REUSED, 'errors' => ['idempotency_key' => [__('wave12.idempotency_key_conflict')]]], 409);
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
