<?php

declare(strict_types=1);

/**
 * Agent B3 — REQ-API-006 (api:openapi) and REQ-IAM-004 (OIDC discovery, JWKS, scopes ⊆ permission catalogue).
 */

use App\Application\Identity\Rbac\PermissionCatalogue;
use App\Application\Integrations\Developer\{OAuthScopeCatalogue,OpenApiGenerator};
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

it('api:openapi documents every /api/v1 route and method', function () {
    $out = sys_get_temp_dir().'/openapi-'.uniqid().'.json';
    expect(Artisan::call('api:openapi', ['--output' => $out]))->toBe(0);
    $doc = json_decode((string) file_get_contents($out), true);
    @unlink($out);

    expect($doc['openapi'])->toBe('3.1.0');
    $routes = app(OpenApiGenerator::class)->routes();
    expect(count($routes))->toBeGreaterThan(100);
    foreach ($routes as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            expect($doc['paths']['/'.$route->uri()][strtolower($method)] ?? null)->not->toBeNull("missing {$method} /{$route->uri()}");
        }
    }
});

it('records permissions, partner scopes and inline validation rules on operations', function () {
    $doc = app(OpenApiGenerator::class)->generate();

    $op = $doc['paths']['/api/v1/developer/clients/{client}/rate-limits']['put'];
    expect($op['x-permissions'])->toBe(['integrations.manage'])
        ->and($op['requestBody']['content']['application/json']['schema']['required'])->toContain('rate_limit_per_minute', 'sandbox_rate_limit_per_minute')
        ->and($op['parameters'][0]['name'])->toBe('client');
    expect($doc['paths']['/api/v1/partner/whoami']['get']['x-partner-scopes'])->toBe(['*']);
    expect(array_keys($doc['components']['securitySchemes']['partnerClient']['flows']['clientCredentials']['scopes']))->toBe(OAuthScopeCatalogue::scopes());
});

it('keeps the committed docs/api/openapi.json covering every /api/v1 route', function () {
    $doc = json_decode((string) file_get_contents(base_path('docs/api/openapi.json')), true);
    $missing = [];
    foreach (app(OpenApiGenerator::class)->routes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            if (! isset($doc['paths']['/'.$route->uri()][strtolower($method)])) {
                $missing[] = "{$method} /{$route->uri()}";
            }
        }
    }
    expect($missing)->toBe([], 'Run php artisan api:openapi');

    $this->getJson('/api/v1/developer/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0');
});

it('maps every client-credentials scope only to catalogued permissions and registers exactly those scopes with Passport', function () {
    expect(OAuthScopeCatalogue::unknownPermissions())->toBe([]);
    $catalogue = PermissionCatalogue::all();
    foreach (OAuthScopeCatalogue::SCOPES as $scope => $def) {
        expect($def['permissions'])->not->toBeEmpty();
        foreach ($def['permissions'] as $p) {
            expect(isset($catalogue[$p]))->toBeTrue("{$scope} → {$p}");
        }
    }
    expect(Passport::scopeIds())->toEqualCanonicalizing(OAuthScopeCatalogue::scopes());

    $this->getJson('/api/v1/developer/scopes')->assertOk()->assertJsonCount(count(OAuthScopeCatalogue::SCOPES), 'data');
});

it('publishes OIDC discovery metadata', function () {
    $r = $this->getJson('/.well-known/openid-configuration')->assertOk();
    $issuer = rtrim((string) config('app.url'), '/');
    expect($r->json('issuer'))->toBe($issuer)
        ->and($r->json('token_endpoint'))->toBe($issuer.'/oauth/token')
        ->and($r->json('jwks_uri'))->toBe($issuer.'/.well-known/jwks.json')
        ->and($r->json('grant_types_supported'))->toContain('client_credentials')
        ->and($r->json('scopes_supported'))->toBe(OAuthScopeCatalogue::scopes())
        ->and($r->json('userinfo_endpoint'))->toBeNull();
});

it('serves the Passport signing key as a JWK set', function () {
    $jwk = $this->getJson('/.well-known/jwks.json')->assertOk()->json('keys.0');
    expect($jwk['kty'])->toBe('RSA')->and($jwk['alg'])->toBe('RS256')->and($jwk['use'])->toBe('sig')->and($jwk['kid'])->not->toBeEmpty();

    $configured = config('passport.public_key');
    $pem = is_string($configured) && $configured !== '' ? str_replace('\\n', "\n", $configured) : file_get_contents(Passport::keyPath('oauth-public.key'));
    $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));
    $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    expect($jwk['n'])->toBe($b64($details['rsa']['n']))->and($jwk['e'])->toBe($b64($details['rsa']['e']));
});
