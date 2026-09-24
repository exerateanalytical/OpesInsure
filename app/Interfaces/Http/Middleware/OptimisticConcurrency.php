<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Interfaces\Http\Errors\ApiProblemException;
use App\Interfaces\Http\Errors\ErrorCode;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP If-Match / ETag optimistic concurrency (REQ-API-002).
 *
 * Runs on every /api route AFTER route-model binding. It acts on the
 * route's bound Eloquent model (the last bound model = the most specific
 * resource, e.g. {claim}/documents/{document} -> document; or the one
 * named by the middleware parameter):
 *
 *  - GET/HEAD 2xx: adds `ETag: "<version>"`.
 *  - PUT/PATCH/DELETE/POST with `If-Match`: compares against the CURRENT
 *    row; mismatch -> 412 STALE_RECORD (errors.version/current_version,
 *    same shape as the existing EnforcesOptimisticConcurrency 409) and the
 *    handler never runs. On success the response carries the new ETag.
 *  - No If-Match: passes through unchanged (optional, backward compatible)
 *    unless the route opts into `if-match:required` -> 428 PRECONDITION_REQUIRED.
 *
 * <version> is the model's integer `lock_version` column when it
 * has one, otherwise `updated_at` as ISO-8601 — the exact token the
 * existing body-level `version` field uses, so either mechanism can be fed
 * the same value. Note: updated_at has second precision on most tables, so
 * two writes inside the same second are indistinguishable; tables that need
 * stricter guarantees should add lock_version.
 *
 * Usage: global (no params); per route `->middleware('if-match:required')`
 * or `'if-match:required,claim'` / `'if-match:optional,claim'`.
 */
final class OptimisticConcurrency
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next, string $mode = 'optional', ?string $parameter = null): Response
    {
        $model = self::boundModel($request, $parameter);
        $method = $request->getMethod();
        $ifMatch = $request->headers->get('If-Match');

        if (in_array($method, self::MUTATING, true)) {
            if ($ifMatch === null || trim($ifMatch) === '') {
                if ($mode === 'required') {
                    throw new ApiProblemException(ErrorCode::PRECONDITION_REQUIRED, 428, __('api_errors.precondition_required'));
                }

                return $next($request);
            }

            if ($model !== null) {
                $current = self::version($model->newQuery()->whereKey($model->getKey())->first() ?? $model);

                if ($current !== null && ! self::matches($ifMatch, $current)) {
                    throw ApiProblemException::staleRecord($current);
                }
            }

            $response = $next($request);

            if ($model !== null && $response->isSuccessful() && $method !== 'DELETE' && ($fresh = $model->fresh()) && ($v = self::version($fresh))) {
                $response->headers->set('ETag', '"'.$v.'"');
            }

            return $response;
        }

        $response = $next($request);

        if ($model !== null && in_array($method, ['GET', 'HEAD'], true) && $response->isSuccessful() && ! $response->headers->has('ETag') && ($v = self::version($model))) {
            $response->headers->set('ETag', '"'.$v.'"');
        }

        return $response;
    }

    public static function version(Model $model): ?string
    {
        foreach (['lock_version'] as $column) {
            $value = $model->getAttribute($column);
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return (string) $value;
            }
        }

        $updated = $model->getAttribute($model->getUpdatedAtColumn() ?? 'updated_at');

        return $updated instanceof \DateTimeInterface ? \Illuminate\Support\Carbon::instance($updated)->toIso8601String() : null;
    }

    private static function matches(string $header, string $current): bool
    {
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            if ($tag === '*') {
                return true;
            }
            $tag = trim(preg_replace('/^W\//', '', $tag) ?? $tag, '"');
            if ($tag === $current) {
                return true;
            }
            // Accept any equivalent timestamp rendering (e.g. Z vs +00:00).
            if (! ctype_digit($current)) {
                try {
                    if (\Illuminate\Support\Carbon::parse($tag)->equalTo(\Illuminate\Support\Carbon::parse($current))) {
                        return true;
                    }
                } catch (\Throwable) {
                    // not a timestamp -> no match
                }
            }
        }

        return false;
    }

    private static function boundModel(Request $request, ?string $parameter): ?Model
    {
        $route = $request->route();
        if (! is_object($route)) {
            return null;
        }

        if ($parameter !== null) {
            $value = $route->parameter($parameter);

            return $value instanceof Model ? $value : null;
        }

        $models = array_filter($route->parameters(), fn ($v) => $v instanceof Model);

        return $models === [] ? null : end($models);
    }
}
