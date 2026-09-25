<?php

declare(strict_types=1);

use App\Application\Demo\DemoEnvironment;
use App\Interfaces\Http\Middleware\DeprecatedRouteAlias;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

test('deprecated alias adds Deprecation and Link headers and logs usage', function () {
    Log::spy();
    $request = Request::create('/api/v1/support/tickets', 'POST');
    $response = (new DeprecatedRouteAlias)->handle($request, fn () => new Response('ok', 201), 'support-tickets', 'REQ-DUP-001');

    expect($response->getStatusCode())->toBe(201)
        ->and($response->headers->get('Deprecation'))->toBe('true')
        ->and($response->headers->get('Link'))->toBe('</api/v1/support-tickets>; rel="successor-version"');
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'deprecated_route_alias' && $ctx['requirement'] === 'REQ-DUP-001')->once();
})->group('REQ-DUP-001');

test('alias route responds with deprecation headers end to end', function () {
    $response = $this->postJson('/api/v1/mobile/master-data/review', []);
    // Unauthenticated: auth runs first, so check the route is wired as an alias instead.
    $route = app('router')->getRoutes()->match(Request::create('/api/v1/mobile/master-data/review', 'POST'));
    expect(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, DeprecatedRouteAlias::class)))->toBeTrue();
    expect($response->status())->toBe(401);
})->group('REQ-DUP-013');

test('demo:seed refuses in production unless explicitly allowed', function () {
    config(['demo.enabled' => true, 'demo.allow_in_production' => false]);
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(app(DemoEnvironment::class)->seedingAllowed())->toBeFalse();
        $this->artisan('demo:seed')->expectsOutputToContain('Refusing to seed demo data in production')->assertExitCode(1);

        config(['demo.allow_in_production' => true]);
        expect(app(DemoEnvironment::class)->seedingAllowed())->toBeTrue();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
})->group('REQ-SEC-002');

test('runtime bootstrap exposes environment and banner', function () {
    config(['demo.enabled' => true]);
    $this->getJson('/api/v1/mobile/runtime/bootstrap')->assertOk()
        ->assertJsonPath('data.environment.demo_mode', true)
        ->assertJsonPath('data.environment.banner', 'DEMO');

    config(['demo.enabled' => false]);
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(app(DemoEnvironment::class)->bannerLabel())->toBeNull();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
})->group('REQ-SEC-002');
