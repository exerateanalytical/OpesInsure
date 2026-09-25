<?php

declare(strict_types=1);

use App\Interfaces\Http\Middleware\DeprecatedRouteAlias;
use Illuminate\Support\Facades\Route;

/*
 | REQ-DUP-001, REQ-DUP-002, REQ-DUP-003, REQ-DUP-010, REQ-DUP-013, REQ-DUP-014.
 | Two routes may resolve to the same controller action only if every route
 | but one is marked with DeprecatedRouteAlias.
 */
function routeIsAlias(\Illuminate\Routing\Route $route): bool
{
    foreach ($route->gatherMiddleware() as $m) {
        if (is_string($m) && str_starts_with($m, DeprecatedRouteAlias::class)) {
            return true;
        }
    }

    return false;
}

test('no two non-alias routes map to the same controller action', function () {
    $byAction = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $action = $route->getActionName();
        if (! str_contains($action, '@') || routeIsAlias($route)) {
            continue;
        }
        $byAction[$action][] = implode('|', $route->methods()).' '.$route->uri();
    }
    $duplicates = array_filter($byAction, fn (array $uris) => count($uris) > 1);

    expect($duplicates)->toBe([]);
})->group('REQ-DUP-001', 'REQ-DUP-002', 'REQ-DUP-003', 'REQ-DUP-013', 'REQ-DUP-014');

test('every deprecated alias points at a registered canonical route', function () {
    $routes = collect(Route::getRoutes()->getRoutes());
    $uris = $routes->map(fn ($r) => $r->uri())->all();
    $aliases = $routes->filter(fn ($r) => routeIsAlias($r));

    expect($aliases->count())->toBeGreaterThanOrEqual(10);
    foreach ($aliases as $alias) {
        $mw = collect($alias->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, DeprecatedRouteAlias::class));
        [$canonical] = explode(',', substr($mw, strlen(DeprecatedRouteAlias::class) + 1));
        expect($uris)->toContain('api/v1/'.$canonical);
    }
})->group('REQ-DUP-001', 'REQ-DUP-010');
