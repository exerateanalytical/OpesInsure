<?php

declare(strict_types=1);

/**
 * REQ-PTY-003 REQ-KYC-002 — owner decision 26 (2026-09-25): beneficial owner = strictly more than 25% of capital OR
 * voting rights (direct or indirect), control by other means, and settlor / trustee / protector / beneficiary /
 * other controlling person.
 */

use App\Application\Customers\Relationships\PartyRelationshipService;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function boParty(string $name, string $type = 'PERSON'): Party
{
    return Party::create(['type' => $type, 'display_name' => $name, 'status' => 'ACTIVE']);
}

function boOwners(Party $org, ?string $type = null): array
{
    return collect(app(PartyRelationshipService::class)->ultimateBeneficialOwners($org, null, $type)['owners'])->keyBy('display_name')->all();
}

it('REQ-PTY-003: exactly 25% does not qualify; more than 25% does (strictly greater)', function () {
    $svc = app(PartyRelationshipService::class);
    $co = boParty('Co', 'ORGANIZATION');
    [$a, $b, $c] = [boParty('Exactly 25'), boParty('Over 25'), boParty('Rest')];
    $svc->addOwnership($a, $co, ['percentage' => 25], null, null);
    $svc->addOwnership($b, $co, ['percentage' => 25.01], null, null);
    $svc->addOwnership($c, $co, ['percentage' => 49.99], null, null);

    $r = $svc->ultimateBeneficialOwners($co);
    expect(collect($r['owners'])->pluck('display_name')->sort()->values()->all())->toBe(['Over 25', 'Rest'])
        ->and($r['threshold'])->toBe(25.0)->and($r['threshold_rule'])->toBe('GREATER_THAN')
        ->and($r['threshold_basis'])->toBe('OWNER_DECISION_2026-09-25#26');
})->group('REQ-PTY-003');

it('REQ-PTY-003: indirect exactly 25% does not qualify; capital OR voting rights qualify independently', function () {
    $svc = app(PartyRelationshipService::class);
    [$co, $hold] = [boParty('Target', 'ORGANIZATION'), boParty('Holdco', 'ORGANIZATION')];
    [$ind, $voter, $cap] = [boParty('Indirect 25'), boParty('Voter'), boParty('Capital')];
    $svc->addOwnership($hold, $co, ['percentage' => 50], null, null);
    $svc->addOwnership($ind, $hold, ['percentage' => 50], null, null);          // 0.5 * 0.5 = 25% exactly
    $svc->addOwnership($cap, $co, ['percentage' => 30], null, null);            // 30% capital, no votes
    $svc->addOwnership($voter, $co, ['percentage' => 10], null, null);          // 10% capital...
    $svc->addOwnership($voter, $co, ['percentage' => 40, 'interest_type' => 'VOTING'], null, null);   // ...but 40% of the votes

    $o = boOwners($co);
    expect(array_keys($o))->not->toContain('Indirect 25')
        ->and($o['Voter']['grounds'])->toBe(['VOTING_RIGHTS'])->and($o['Voter']['effective_by_basis'])->toBe(['VOTING_RIGHTS' => 40.0])
        ->and($o['Capital']['grounds'])->toBe(['CAPITAL']);
    // Restricting the basis still works (API ?interest_type=SHAREHOLDING).
    expect(array_keys(boOwners($co, 'SHAREHOLDING')))->toBe(['Capital']);
})->group('REQ-PTY-003');

it('REQ-PTY-003: control by other means and the settlor / trustee / protector / beneficiary / controlling roles make beneficial owners', function () {
    $svc = app(PartyRelationshipService::class);
    $trust = boParty('Family Trust', 'ORGANIZATION');
    [$settlor, $protector, $beneficiary, $ctrl, $minor] = [boParty('Settlor'), boParty('Protector'), boParty('Beneficiary'), boParty('Controller'), boParty('Minor holder')];
    $trusteeCo = boParty('Trustee Co', 'ORGANIZATION');
    $trusteeOwner = boParty('Trustee Owner');
    $svc->link($settlor, $trust, ['type' => 'SETTLOR_OF'], null, null);
    $svc->link($protector, $trust, ['type' => 'PROTECTOR_OF'], null, null);
    $svc->link($beneficiary, $trust, ['type' => 'BENEFICIARY_OF'], null, null);
    $svc->link($trusteeCo, $trust, ['type' => 'TRUSTEE_OF'], null, null);     // corporate trustee → its own beneficial owners
    $svc->addOwnership($trusteeOwner, $trusteeCo, ['percentage' => 100], null, null);
    $svc->addOwnership($minor, $trust, ['percentage' => 5], null, null);
    $svc->addOwnership($ctrl, $trust, ['percentage' => 1, 'interest_type' => 'CONTROL'], null, null);

    $o = boOwners($trust);
    expect(array_keys($o))->not->toContain('Minor holder')
        ->and($o['Settlor']['roles'])->toBe(['SETTLOR'])->and($o['Protector']['roles'])->toBe(['PROTECTOR'])
        ->and($o['Beneficiary']['roles'])->toBe(['BENEFICIARY'])
        ->and($o['Controller']['grounds'])->toBe(['CONTROL_OTHER_MEANS'])
        ->and($o['Trustee Owner']['roles'])->toContain('TRUSTEE')->and($o['Trustee Owner']['grounds'])->toContain('CONTROLLING_ROLE_INDIRECT');

    // A controlling legal person with no recorded owner is unresolved (corporate KYC blocks on it).
    $other = boParty('Other controlling co', 'ORGANIZATION');
    $svc->link($other, $trust, ['type' => 'CONTROLLING_PERSON_OF'], null, null);
    expect($svc->ultimateBeneficialOwners($trust)['unresolved'])->toBe([$other->id]);
})->group('REQ-PTY-003', 'REQ-KYC-002');
