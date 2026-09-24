<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Interfaces\Http\Errors\ProblemDetails;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uniform /api response conventions, additive only (never removes or
 * renames an existing member, so current mobile parsing is unaffected):
 *
 *  - Errors (REQ-API-003): any 4xx/5xx JSON body returned by a handler or
 *    middleware (not just thrown exceptions — those are also decorated in
 *    bootstrap/app.php) gets the ProblemDetails members + machine `code`.
 *  - Pagination (REQ-API-008): a Laravel paginator body — plain
 *    ({data, current_page, per_page, total, last_page, ...}) or resource
 *    ({data, meta: {...}}) — gains one normalized member
 *      "pagination": {page, per_page, total, last_page, has_more}
 *    and X-Total-Count when the total is known. Simple/cursor paginators
 *    get has_more from next_page_url / next_cursor.
 */
final class StandardApiEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        if ($response->getStatusCode() >= 400) {
            return ProblemDetails::decorate($response);
        }

        return self::withPagination($response);
    }

    private static function withPagination(JsonResponse $response): JsonResponse
    {
        $body = $response->getData(true);

        if (! is_array($body) || array_is_list($body) || ! isset($body['data']) || ! is_array($body['data']) || ! array_is_list($body['data']) || isset($body['pagination'])) {
            return $response;
        }

        $meta = isset($body['meta']) && is_array($body['meta']) && isset($body['meta']['per_page']) ? $body['meta'] : $body;

        if (! isset($meta['per_page'])) {
            return $response;
        }

        $links = $body['links'] ?? [];
        $nextUrl = $meta['next_page_url'] ?? (is_array($links) ? ($links['next'] ?? null) : null);
        $total = isset($meta['total']) ? (int) $meta['total'] : null;
        $page = isset($meta['current_page']) ? (int) $meta['current_page'] : null;
        $lastPage = isset($meta['last_page']) ? (int) $meta['last_page'] : null;

        $body['pagination'] = [
            'page' => $page,
            'per_page' => (int) $meta['per_page'],
            'total' => $total,
            'last_page' => $lastPage,
            'has_more' => $lastPage !== null && $page !== null ? $page < $lastPage : ($nextUrl !== null || ! empty($meta['next_cursor'])),
        ];

        $response->setData($body);

        if ($total !== null) {
            $response->headers->set('X-Total-Count', (string) $total);
        }

        return $response;
    }
}
