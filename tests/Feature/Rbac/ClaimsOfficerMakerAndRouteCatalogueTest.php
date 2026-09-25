<?php

declare(strict_types=1);

/*
 * Agent R — owner decisions:
 *  1. "A claims officer cannot approve a colleague's claim": CLAIMS_OFFICER holds an explicit maker set, never a checker permission.
 *  2. Every permission enforced by routes/*.php `permission:` middleware is catalogued in config/permissions.php.
 */

use App\Application\Identity\Rbac\PermissionCatalogue;
use App\Application\Identity\RoleCatalogue;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

const R_CHECKER_PERMISSIONS = [
    'claims.carrier.manual_approve', 'claims.settlement.pay', 'claims.payment.approve', 'claims.payment.execute', 'claims.payment.reverse',
    'claims.reopen.approve', 'claims.dispute.resolve', 'claims.evidence.verify', 'claims.decision.approve', 'claims.decision.supervise',
    'claims.reserve.approve', 'claims.late_report.approve', 'claims.types.approve', 'collections.write_off.approve',
    'claims.coverage.resolve', 'claims.experts.review', 'claims.assessment.review', 'claims.investigation.conclude',
];

/** @return list<string> */
function rRoutePermissions(): array
{
    $found = [];
    foreach (glob(base_path('routes/*.php')) as $file) {
        preg_match_all('/permission:([A-Za-z0-9_.\-]+)/', (string) file_get_contents($file), $m);
        array_push($found, ...$m[1]);
    }

    return array_values(array_unique($found));
}

it('gives CLAIMS_OFFICER an explicit maker set without the wildcard or any checker permission', function () {
    $perms = RoleCatalogue::defaultPermissions('CLAIMS_OFFICER');
    expect($perms)->not->toContain('*')
        ->and($perms)->toContain('claims.create', 'claims.decision.propose', 'claims.reserve.request', 'claims.payment.request', 'claims.settlement.calculate', 'claims.reopen.request');
    foreach (R_CHECKER_PERMISSIONS as $p) {
        expect($perms)->not->toContain($p);
    }
    foreach ($perms as $p) {
        expect((bool) preg_match('/\.(approve|review|supervise|conclude|resolve|verify)$/', $p))->toBeFalse("{$p} looks like a checker permission");
    }
    expect(RoleCatalogue::defaultPermissions('CLAIMS_MANAGER'))->toBe(['*']);
});

it('lets a claims officer reach maker endpoints but not checker endpoints', function () {
    $tenant = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'R Claims Org', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    Passport::actingAs(makeMobileTenantStaffUser($tenant, '+237670018801', 'CLAIMS_OFFICER'));
    $h = tenantHeaderFor($tenant);
    $id = (string) Str::uuid();
    $other = (string) Str::uuid();

    expect($this->getJson('/api/v1/claims', $h)->status())->not->toBe(403);
    expect($this->getJson("/api/v1/claims/{$id}/limits", $h)->status())->not->toBe(403);
    expect($this->getJson("/api/v1/claims/{$id}/closure/checklist", $h)->status())->not->toBe(403);
    expect($this->postJson("/api/v1/claims/{$id}/reserves", [], $h)->status())->not->toBe(403);

    expect($this->postJson("/api/v1/claims/{$id}/decisions/{$other}/approve", [], $h)->status())->toBe(403);
    expect($this->postJson("/api/v1/claims/{$id}/reserves/{$other}/approve", [], $h)->status())->toBe(403);
    expect($this->postJson("/api/v1/claims/{$id}/payments/{$other}/approve", [], $h)->status())->toBe(403);
});

it('catalogues every permission string used by route permission middleware', function () {
    $catalogued = array_keys(PermissionCatalogue::all());
    $configured = [];
    foreach ((array) config('permissions') as $category => $entries) {
        if (in_array($category, ['never_grant_to', 'business_data'], true) || ! is_array($entries)) {
            continue;
        }
        array_push($configured, ...array_filter(array_keys($entries), 'is_string'));
    }
    $routes = rRoutePermissions();
    expect(count($routes))->toBeGreaterThan(100);
    expect(array_values(array_diff($routes, $configured)))->toBe([], 'route permissions missing from config/permissions.php');
    expect(array_values(array_diff($routes, $catalogued)))->toBe([]);
});

it('only suggests roles that exist, with a description, for every catalogued permission', function () {
    foreach ((array) config('permissions') as $category => $entries) {
        if (in_array($category, ['never_grant_to', 'business_data'], true) || ! is_array($entries)) {
            continue;
        }
        foreach ($entries as $code => $meta) {
            if (! is_string($code) || ! is_array($meta)) {
                continue;
            }
            expect(array_values(array_diff((array) ($meta['suggested_roles'] ?? []), RoleCatalogue::codes())))->toBe([], "{$code} suggests an unknown role");
        }
    }
    foreach (config('permissions.legacy_catalogue') as $code => $meta) {
        expect($meta['description'])->toBeString()->not->toBeEmpty();
        foreach ($meta['suggested_roles'] as $role) {
            $perms = RoleCatalogue::defaultPermissions($role);
            expect(in_array('*', $perms, true) || in_array($code, $perms, true))->toBeTrue("{$role} is suggested {$code} but does not hold it");
        }
    }
});

it('adds the SPEC provider portal roles with their provider_portal grants (config-only permissions are allowed ahead of routes)', function () {
    $grants = [
        'PROVIDER_ADMIN' => ['profile', 'network', 'tariffs', 'assignments', 'preauth', 'claims', 'finance'],
        'FRONT_DESK' => ['profile', 'network', 'preauth', 'claims'],
        'DOCTOR' => ['profile', 'preauth', 'claims'],
        'BILLING_OFFICER' => ['profile', 'network', 'tariffs', 'claims', 'preauth', 'finance'],
        'PHARMACY_USER' => ['profile', 'tariffs', 'preauth', 'claims'],
        'LAB_USER' => ['profile', 'tariffs', 'preauth', 'claims'],
        'FINANCE_USER' => ['profile', 'network', 'tariffs', 'finance'],
        'ADJUSTER' => ['assignments', 'profile'],
    ];
    foreach ($grants as $role => $views) {
        expect(RoleCatalogue::codes())->toContain($role);
        $held = array_values(array_filter(RoleCatalogue::defaultPermissions($role), fn ($p) => str_starts_with($p, 'provider_portal.')));
        expect($held)->toEqualCanonicalizing(array_map(fn ($v) => "provider_portal.{$v}.view", $views));
        if ($role !== 'ADJUSTER') {
            expect(RoleCatalogue::PROVIDER_ROLES)->toContain($role)->and(RoleCatalogue::INVITABLE)->toContain($role);
        }
    }
    expect(array_keys(config('permissions.provider_portal')))->toHaveCount(7);
});
