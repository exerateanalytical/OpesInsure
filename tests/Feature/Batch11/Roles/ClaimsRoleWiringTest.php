<?php

declare(strict_types=1);

/*
 * Agent CR — Batch 11/12 claims / legal / collections permission wiring: catalogue entries, maker vs checker grants.
 */

use App\Application\Identity\Rbac\PermissionCatalogue;
use App\Application\Identity\RoleCatalogue;

const CR_CHECKERS = ['claims.coverage.resolve', 'claims.experts.review', 'claims.assessment.review', 'claims.investigation.conclude', 'claims.carrier.manual_approve',
    'claims.decision.supervise', 'claims.settlement.pay', 'claims.reopen.approve', 'collections.write_off.approve'];

it('catalogues every Batch 11/12 claims permission with a description and existing suggested roles, each granted', function () {
    $expected = ['claims.parties.manage', 'claims.coverage.check', 'claims.coverage.resolve', 'claims.experts.assign', 'claims.experts.review', 'claims.experts.work',
        'claims.assessment.record', 'claims.assessment.review', 'claims.investigation.manage', 'claims.investigation.conclude', 'claims.carrier.manual_entry',
        'claims.carrier.manual_approve', 'claims.carrier.keys', 'claims.decision.appeal', 'claims.decision.supervise', 'claims.settlement.calculate',
        'claims.settlement.offer', 'claims.settlement.respond', 'claims.settlement.discharge', 'claims.settlement.pay', 'claims.close', 'claims.reopen.request',
        'claims.reopen.approve', 'legal.matters.view', 'legal.matters.manage', 'collections.view', 'collections.manage', 'collections.write_off.approve', 'fraud.sod.report'];
    expect(array_keys(config('permissions.claims_batch11_12')))->toEqualCanonicalizing($expected);

    foreach (config('permissions.claims_batch11_12') as $code => $meta) {
        expect($code)->toMatch(PermissionCatalogue::NAME_PATTERN);
        expect($meta['description'])->toBeString()->not->toBeEmpty();
        expect($meta['suggested_roles'])->not->toBeEmpty();
        foreach ($meta['suggested_roles'] as $role) {
            expect(RoleCatalogue::codes())->toContain($role);
            $perms = RoleCatalogue::defaultPermissions($role);
            expect(in_array('*', $perms, true) || in_array($code, $perms, true))->toBeTrue("{$role} should hold {$code}");
        }
        // Business data: SYSTEM_ADMIN's platform wildcard must not reach these.
        expect(PermissionCatalogue::isBusinessData($code))->toBeTrue("{$code} should be business data");
    }
});

it('suggests checker permissions only to manager / senior roles', function () {
    $makerRoles = ['CLAIMS_OFFICER', 'ADJUSTER', 'CARRIER_STAFF', 'CARRIER_ADMIN', 'FINANCE_OFFICER', 'CUSTOMER_SERVICE', 'CUSTOMER'];
    foreach (CR_CHECKERS as $code) {
        expect(array_values(array_intersect(config("permissions.claims_batch11_12")[$code]["suggested_roles"], $makerRoles)))->toBe([], "{$code} suggested to a maker");
    }
});

it('separates makers from checkers in explicit role grants', function (string $role, array $must) {
    $perms = RoleCatalogue::defaultPermissions($role);
    foreach ($must as $p) {
        expect($perms)->toContain($p);
    }
    foreach (CR_CHECKERS as $p) {
        expect($perms)->not->toContain($p);
    }
})->with([
    'adjuster' => ['ADJUSTER', ['claims.experts.work', 'claims.assessment.record']],
    'carrier staff' => ['CARRIER_STAFF', ['claims.carrier.manual_entry']],
    'carrier admin' => ['CARRIER_ADMIN', ['claims.carrier.manual_entry', 'claims.coverage.check']],
    'finance officer' => ['FINANCE_OFFICER', ['collections.view', 'collections.manage']],
    'customer service' => ['CUSTOMER_SERVICE', ['claims.decision.appeal']],
    'customer' => ['CUSTOMER', []],
    'agent' => ['AGENT', []],
]);

it('grants the senior checkers to the carrier super admin', function () {
    $perms = RoleCatalogue::defaultPermissions('CARRIER_SUPER_ADMIN');
    foreach (['claims.coverage.resolve', 'claims.carrier.manual_approve', 'claims.carrier.keys', 'collections.write_off.approve'] as $p) {
        expect($perms)->toContain($p);
    }
});

it('never grants a Batch 11/12 claims permission to customer, agent or broker staff', function () {
    foreach (['CUSTOMER', 'AGENT', 'BROKER_STAFF'] as $role) {
        expect(array_values(array_intersect(RoleCatalogue::defaultPermissions($role), array_keys(config('permissions.claims_batch11_12')))))->toBe([]);
    }
});
