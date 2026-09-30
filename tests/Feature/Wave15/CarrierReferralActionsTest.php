<?php

declare(strict_types=1);

use App\Models\TenantMembership;
use App\Models\UnderwritingCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';

// Launch fix 2026-09-29: the carrier app gated decisions on a status the server never sends; it now reads allowed_actions.

function referralCaseFor(array $fixture, string $status): UnderwritingCase
{
    return UnderwritingCase::create(['tenant_id' => $fixture['tenant']->id, 'proposal_id' => $fixture['proposal']->id, 'carrier_id' => $fixture['carrier']->id, 'status' => $status, 'priority' => 'NORMAL', 'referral_reasons' => ['X']]);
}

it('lists the decisions a carrier user may take on an open referral', function (string $status, array $expected) {
    $a = makeMobileCustomerFixture('+237670019101');
    $case = referralCaseFor($a, $status);
    $insurer = makeMobileTenantStaffUser($a['tenant'], '+237670019102', 'CARRIER_STAFF');
    TenantMembership::where('user_id', $insurer->id)->update(['carrier_id' => $a['carrier']->id]);
    Passport::actingAs($insurer);

    $this->getJson('/api/v1/mobile/carrier/referrals/'.$case->id, tenantHeaderFor($a['tenant']))
        ->assertStatus(200)->assertJsonPath('data.allowed_actions', $expected);
})->with([
    'queued' => ['QUEUED', ['APPROVE', 'MORE_INFORMATION', 'DECLINE']],
    'in review' => ['IN_REVIEW', ['APPROVE', 'MORE_INFORMATION', 'DECLINE']],
    'awaiting information' => ['AWAITING_INFORMATION', ['APPROVE', 'DECLINE']],
    'decided' => ['DECIDED', []],
]);

it('refuses to reopen a decided referral', function () {
    $a = makeMobileCustomerFixture('+237670019111');
    $case = referralCaseFor($a, 'DECIDED');
    $insurer = makeMobileTenantStaffUser($a['tenant'], '+237670019112', 'CARRIER_STAFF');
    TenantMembership::where('user_id', $insurer->id)->update(['carrier_id' => $a['carrier']->id]);
    Passport::actingAs($insurer);

    $this->postJson('/api/v1/mobile/carrier/referrals/'.$case->id.'/decision', ['decision' => 'MORE_INFORMATION', 'note' => 'Please send more.'], tenantHeaderFor($a['tenant']))
        ->assertStatus(409);
    expect($case->fresh()->status)->toBe('DECIDED');
});

it('lets a carrier-linked user decide only its own insurer\'s bordereau', function () {
    $fx = makeMobilePartnerFixture('CARRIER', '+237670019121');
    $mine = makeMobileFinanceProposalChain($fx['tenant']);
    $theirs = makeMobileFinanceProposalChain($fx['tenant']);
    $foreign = makeMobileTestBordereau($fx['tenant'], $theirs['carrier']->id, $fx['user'], ['status' => 'SUBMITTED']);
    $own = makeMobileTestBordereau($fx['tenant'], $mine['carrier']->id, $fx['user'], ['status' => 'SUBMITTED']);
    $membership = TenantMembership::where('tenant_id', $fx['tenant']->id)->where('user_id', $fx['user']->id)->firstOrFail();
    $membership->update(['carrier_id' => $mine['carrier']->id]);
    $membership->roles()->attach(App\Models\Role::create(['tenant_id' => $fx['tenant']->id, 'code' => 'CARRIER_DECIDER_T', 'permissions' => ['carrier.bordereaux.decide'], 'is_system' => false])->id);
    Passport::actingAs($fx['user']);
    $body = ['carrier_reference' => 'CR-1', 'decision' => 'ACKNOWLEDGED', 'notes' => 'Checked against our own register of policies.'];

    $this->postJson("/api/v1/carrier/bordereaux/{$foreign->id}/decision", $body, tenantHeaderFor($fx['tenant']))->assertStatus(409);
    expect($foreign->refresh()->status)->toBe('SUBMITTED');
    $this->postJson("/api/v1/carrier/bordereaux/{$own->id}/decision", $body, tenantHeaderFor($fx['tenant']))->assertOk();
});
