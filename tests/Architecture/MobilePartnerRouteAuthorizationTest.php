<?php

declare(strict_types=1);

/**
 * Security review 2026-09-27, item 1 (docs/SECURITY_REVIEW_MOBILE_2026-09-27.md).
 *
 * Every /mobile/partner/*, /mobile/agent/*, /mobile/broker/* and /mobile/carrier/* route must run behind:
 *   - auth:api (a Passport bearer token),
 *   - ResolveTenant (the X-Tenant-Id membership check that also scopes TenantContext), and
 *   - a RequirePermission:<code> gate.
 * Data scoping below that (BookScope / PortalScope / CarrierScopeResolver / AgentPartnerResolver) is covered by the
 * IDOR tests in tests/Feature/SecurityReview/MobilePartnerIdorTest.php.
 *
 * A route may only skip a check when it is listed in ALLOW_LIST below with a written justification. The list is
 * currently empty: no partner route needs an exception. A stale entry (a route that no longer exists) fails too.
 */

use App\Interfaces\Http\Middleware\RequirePermission;
use App\Interfaces\Http\Middleware\ResolveTenant;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;

const MOBILE_PARTNER_ROUTE_PATTERN = '#(^|/)mobile/(partner|agent|broker|carrier)(/|$)#';

/**
 * "METHOD uri" => ['skip' => ['auth'|'tenant'|'permission', ...], 'why' => 'justification'].
 *
 * @var array<string, array{skip: list<string>, why: string}>
 */
const MOBILE_PARTNER_ROUTE_ALLOW_LIST = [];

/** @return array<string, array{route: Route, middleware: list<string>}> */
function mobilePartnerRoutes(): array
{
    $router = app('router');
    $out = [];
    foreach ($router->getRoutes()->getRoutes() as $route) {
        if (! preg_match(MOBILE_PARTNER_ROUTE_PATTERN, $route->uri())) {
            continue;
        }
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->implode('|');
        $out[$method.' '.$route->uri()] = ['route' => $route, 'middleware' => array_values(array_map('strval', $router->gatherRouteMiddleware($route)))];
    }
    ksort($out);

    return $out;
}

it('finds the partner route surface (guards against a vacuous pass)', function () {
    expect(count(mobilePartnerRoutes()))->toBeGreaterThan(60);
});

it('puts auth:api, tenant resolution and a permission gate on every partner/agent/broker/carrier mobile route', function () {
    $failures = [];
    foreach (mobilePartnerRoutes() as $key => ['middleware' => $mw]) {
        $skip = MOBILE_PARTNER_ROUTE_ALLOW_LIST[$key]['skip'] ?? [];
        $has = [
            'auth' => in_array(Authenticate::class.':api', $mw, true),
            'tenant' => in_array(ResolveTenant::class, $mw, true),
            'permission' => collect($mw)->contains(fn (string $m) => str_starts_with($m, RequirePermission::class.':') && strlen($m) > strlen(RequirePermission::class) + 1),
        ];
        foreach ($has as $check => $present) {
            if (! $present && ! in_array($check, $skip, true)) {
                $failures[] = "$key lacks $check";
            }
        }
    }

    expect($failures)->toBe([]);
});

it('keeps the allow-list justified and free of stale entries', function () {
    $routes = mobilePartnerRoutes();
    expect(MOBILE_PARTNER_ROUTE_ALLOW_LIST)->toBeArray();
    foreach (MOBILE_PARTNER_ROUTE_ALLOW_LIST as $key => $entry) {
        expect($routes)->toHaveKey($key)
            ->and(trim($entry['why'] ?? ''))->not->toBe('')
            ->and(array_diff($entry['skip'], ['auth', 'tenant', 'permission']))->toBe([]);
    }
});

it('never exposes a partner route outside the authenticated api/v1 prefix', function () {
    $outside = collect(mobilePartnerRoutes())->keys()->reject(fn (string $k) => str_contains($k, ' api/v1/mobile/'))->values()->all();

    expect($outside)->toBe([]);
});

it('keeps every mutating partner route rate limited', function () {
    $unthrottled = collect(mobilePartnerRoutes())
        ->filter(fn ($r, string $k) => ! str_starts_with($k, 'GET'))
        ->reject(fn ($r) => collect($r['middleware'])->contains(fn (string $m) => str_contains($m, 'ThrottleRequests')))
        ->keys()->values()->all();

    expect($unthrottled)->toBe([]);
});
