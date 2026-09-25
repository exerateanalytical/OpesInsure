<?php

declare(strict_types=1);

/*
 * W2 — Batch 8 permission wiring: catalogue grants (maker vs checker), rbac:sync-role-permissions for existing
 * tenants, and the daily renewals:sweep schedule.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\RenewalCase;
use App\Models\Role;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

const W2_CATEGORIES = ['special_policies', 'policy_portfolio', 'document_governance', 'policy_lifecycle'];

it('catalogues every Batch 8 permission and grants each to the roles it suggests', function () {
    $expected = ['special_policies.view', 'special_policies.manage', 'special_policies.schedule.manage', 'cargo_declarations.declare', 'cargo_declarations.cancel',
        'life_surrender.scales.manage', 'life_surrender.scales.approve', 'life_surrender.quote', 'policies.portfolio_transfer.request', 'policies.portfolio_transfer.approve',
        'policies.portfolio_transfer.read', 'policies.portability.export', 'documents.intake.manage', 'documents.access_log.read', 'documents.retention.manage',
        'documents.retention.approve', 'documents.legal_hold.manage', 'documents.destruction.request', 'documents.destruction.approve', 'documents.signatures.manage',
        'policies.cancellation.request', 'policies.cancellation.review', 'policies.cancellation.approve', 'policies.suspend', 'policies.reinstatement.request',
        'policies.reinstatement.approve', 'policy.recovery.request', 'policy.recovery.approve', 'policy.premium.waive'];
    $catalogued = collect(W2_CATEGORIES)->flatMap(fn ($c) => array_keys(config("permissions.{$c}")))->all();
    expect($catalogued)->toEqualCanonicalizing($expected);

    foreach (W2_CATEGORIES as $category) {
        foreach (config("permissions.{$category}") as $code => $meta) {
            foreach ($meta['suggested_roles'] as $role) {
                expect(RoleCatalogue::codes())->toContain($role);
                $perms = RoleCatalogue::defaultPermissions($role);
                expect(in_array('*', $perms, true) || in_array($code, $perms, true))->toBeTrue("{$role} should hold {$code}");
            }
        }
    }
});

it('separates makers from checkers', function (string $role, array $must, array $mustNot) {
    $perms = RoleCatalogue::defaultPermissions($role);
    foreach ($must as $p) {
        expect($perms)->toContain($p);
    }
    foreach ($mustNot as $p) {
        expect($perms)->not->toContain($p);
    }
})->with([
    'carrier staff' => ['CARRIER_STAFF', ['policies.cancellation.request', 'policies.reinstatement.request', 'policy.recovery.request', 'documents.intake.manage'], ['policies.cancellation.approve', 'policies.reinstatement.approve', 'policy.recovery.approve', 'documents.destruction.approve', 'policy.premium.waive']],
    'carrier admin' => ['CARRIER_ADMIN', ['documents.retention.manage', 'documents.destruction.request', 'documents.legal_hold.manage', 'life_surrender.scales.manage', 'policies.portfolio_transfer.request'], ['documents.retention.approve', 'documents.destruction.approve', 'life_surrender.scales.approve', 'policies.portfolio_transfer.approve']],
    'carrier super admin' => ['CARRIER_SUPER_ADMIN', ['documents.retention.approve', 'documents.destruction.approve', 'life_surrender.scales.approve', 'policies.portfolio_transfer.approve', 'policies.cancellation.approve', 'policy.premium.waive'], []],
    'underwriter' => ['UNDERWRITER', ['life_surrender.scales.manage', 'policies.cancellation.review'], ['life_surrender.scales.approve', 'policies.cancellation.approve']],
    'senior underwriter' => ['SENIOR_UNDERWRITER', ['life_surrender.scales.approve', 'policies.cancellation.approve', 'policies.reinstatement.approve', 'policy.recovery.approve'], []],
    'broker staff' => ['BROKER_STAFF', ['policies.cancellation.request', 'policies.reinstatement.request', 'policies.portfolio_transfer.read'], ['policies.cancellation.approve', 'policies.portfolio_transfer.approve', 'documents.retention.manage']],
    'agent' => ['AGENT', ['policies.cancellation.request', 'policies.reinstatement.request', 'policies.portfolio_transfer.read'], ['policies.portfolio_transfer.request', 'policies.cancellation.approve']],
    'customer' => ['CUSTOMER', [], ['policies.cancellation.request', 'documents.access_log.read']],
]);

it('keeps compliance and finance manager wildcards covering the document governance and waiver permissions', function () {
    foreach (['COMPLIANCE_ADMIN', 'FINANCE_MANAGER'] as $role) {
        expect(RoleCatalogue::defaultPermissions($role))->toBe(['*']);
    }
});

it('tops existing tenant roles up with missing catalogue permissions, additive and idempotent, with a dry run', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $tenant = $f['tenant'];
    $staff = Role::create(['tenant_id' => $tenant->id, 'code' => 'CARRIER_STAFF', 'permissions' => ['carrier.dashboard.read', 'custom.grant'], 'is_system' => true]);
    $wild = Role::create(['tenant_id' => $tenant->id, 'code' => 'COMPLIANCE_ADMIN', 'permissions' => ['*'], 'is_system' => true]);
    $custom = Role::create(['tenant_id' => $tenant->id, 'code' => 'W2_CUSTOM_'.Str::random(4), 'permissions' => ['x.y'], 'is_system' => false]);

    $this->artisan('rbac:sync-role-permissions', ['--dry-run' => true])->assertSuccessful();
    expect($staff->fresh()->permissions)->toBe(['carrier.dashboard.read', 'custom.grant']);

    $this->artisan('rbac:sync-role-permissions', ['--tenant' => $tenant->id])->assertSuccessful();
    $perms = $staff->fresh()->permissions;
    expect($perms)->toContain('custom.grant')->toContain('policies.cancellation.request')->toContain('documents.intake.manage')
        ->and(array_diff(RoleCatalogue::defaultPermissions('CARRIER_STAFF'), $perms))->toBe([])
        ->and(count($perms))->toBe(count(array_unique($perms)))
        ->and($wild->fresh()->permissions)->toBe(['*'])
        ->and($custom->fresh()->permissions)->toBe(['x.y']);

    $this->artisan('rbac:sync-role-permissions')->expectsOutputToContain('Roles updated: 0. Permissions added: 0.')->assertSuccessful();
    expect($staff->fresh()->permissions)->toBe($perms);
});

it('schedules the daily renewal sweep, which opens renewal cases per tenant idempotently', function () {
    Http::fake();
    Artisan::call('list');
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'renewals:sweep'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('15 1 * * *')->and($event->timezone)->toBe('Africa/Douala');

    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, [
        'policy_number' => 'POL-W2-'.Str::random(5), 'coverage_starts_at' => now()->subYear(), 'coverage_ends_at' => now()->addDays(20),
    ]);

    $this->artisan('renewals:sweep')->assertSuccessful();
    $this->artisan('renewals:sweep')->assertSuccessful();
    expect(RenewalCase::where('policy_id', $policy->id)->count())->toBe(1);
});
