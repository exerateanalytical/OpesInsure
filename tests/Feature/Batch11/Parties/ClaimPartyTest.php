<?php

declare(strict_types=1);

use App\Models\Claim;
use App\Models\ClaimInvolvedParty;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

const C7_PERMS = ['claims.view', 'claims.parties.manage'];

function c7Claim(Tenant $t, string $status = 'SUBMITTED'): Claim
{
    $chain = makeMobileFinanceProposalChain($t);
    $policy = makeMobileTestPolicy($chain['proposal'], $t, $chain['carrier']->id, $chain['party']->id);

    return makeMobileTestClaim($t, $policy, $chain['party'], ['status' => $status]);
}

function c7Url(Claim $c, string $suffix = ''): string
{
    return "/api/v1/claims/{$c->id}/parties".$suffix;
}

it('REQ-CLM-006 adds, lists, updates and removes claim parties with a dated audit trail and outbox events', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, C7_PERMS));
    $claim = c7Claim($t);

    $id = $this->postJson(c7Url($claim), ['role' => 'CLAIMANT', 'party_id' => $claim->claimant_party_id], tenantHeader($t))
        ->assertCreated()->assertJsonPath('data.match_status', 'LINKED')->assertJsonPath('data.is_self', true)
        ->assertJsonPath('data.source', 'STAFF')->json('data.id');
    // a second active claimant is refused
    $this->postJson(c7Url($claim), ['role' => 'CLAIMANT', 'display_name' => 'Someone else'], tenantHeader($t))->assertStatus(422);
    $w = $this->postJson(c7Url($claim), ['role' => 'WITNESS', 'display_name' => 'Walk-by witness'], tenantHeader($t))
        ->assertCreated()->assertJsonPath('data.match_status', 'UNLINKED')->json('data.id');

    $this->getJson(c7Url($claim), tenantHeader($t))->assertOk()->assertJsonCount(2, 'data');
    $this->patchJson(c7Url($claim, "/{$w}"), ['notes' => 'Saw the collision', 'reason' => 'Statement taken'], tenantHeader($t))
        ->assertOk()->assertJsonPath('data.notes', 'Saw the collision');
    $this->deleteJson(c7Url($claim, "/{$w}"), ['reason' => 'Duplicate entry'], tenantHeader($t))
        ->assertOk()->assertJsonPath('data.effective_to', now()->toDateString());
    $this->getJson(c7Url($claim), tenantHeader($t))->assertJsonCount(1, 'data');
    $this->getJson(c7Url($claim, '?include_removed=1'), tenantHeader($t))->assertJsonCount(2, 'data');
    $this->patchJson(c7Url($claim, "/{$w}"), ['notes' => 'x', 'reason' => 'Late change'], tenantHeader($t))->assertStatus(422);

    expect(DB::table('outbox_messages')->where('aggregate_id', $claim->id)->pluck('event_name')->all())
        ->toContain('claim.party.added', 'claim.party.updated', 'claim.party.removed')
        ->and(DB::table('audit_events')->where('subject_id', $claim->id)->where('action', 'like', 'claim.party.%')->count())->toBe(4)
        ->and(ClaimInvolvedParty::find($id)->tenant_id)->toBe($t->id);
});

it('REQ-CLM-006 matches an existing golden record by contact, otherwise creates one and never merges silently', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, C7_PERMS));
    $claim = c7Claim($t);
    $existing = app(\App\Application\Customers\PartyService::class)->create(['type' => 'INDIVIDUAL', 'display_name' => 'Jean Mbarga', 'phone_e164' => '+237670111222', 'date_of_birth' => '1980-05-04']);

    $this->postJson(c7Url($claim), ['role' => 'THIRD_PARTY', 'display_name' => 'J. Mbarga', 'contact_phone' => '+237670111222', 'consent_basis' => 'LEGITIMATE_INTEREST', 'link_party' => true], tenantHeader($t))
        ->assertCreated()->assertJsonPath('data.match_status', 'MATCHED')->assertJsonPath('data.party_id', $existing->id);

    $parties = Party::count();
    $r = $this->postJson(c7Url($claim), ['role' => 'DRIVER', 'display_name' => 'Jean Mbarga', 'date_of_birth' => '1980-05-04', 'link_party' => true], tenantHeader($t))->assertCreated();
    expect(Party::count())->toBe($parties + 1)
        ->and($r->json('data.match_status'))->toBe('REVIEW_PENDING')
        ->and($r->json('data.match_candidates'))->toBeGreaterThan(0)
        ->and(Party::find($existing->id)->merged_into_id)->toBeNull()
        ->and(DB::table('party_merges')->count())->toBe(0);
});

it('REQ-CLM-006 stores payee bank details encrypted and masked and requires a consent basis', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, C7_PERMS));
    $claim = c7Claim($t);

    $this->postJson(c7Url($claim), ['role' => 'PAYEE', 'display_name' => 'Garage Akwa', 'bank_account_number' => 'CM21 1000 1234 5678'], tenantHeader($t))->assertStatus(422);
    $r = $this->postJson(c7Url($claim), ['role' => 'PAYEE', 'display_name' => 'Garage Akwa', 'bank_name' => 'Afriland', 'bank_account_number' => 'CM21 1000 1234 5678', 'consent_basis' => 'CONTRACT'], tenantHeader($t))
        ->assertCreated()->assertJsonPath('data.bank_account_masked', '••••••••••••5678')->assertJsonMissingPath('data.bank_account_encrypted');
    expect(json_encode($r->json()))->not->toContain('12345678');
    $raw = DB::table('claim_involved_parties')->where('id', $r->json('data.id'))->value('bank_account_encrypted');
    expect($raw)->not->toContain('5678')
        ->and(ClaimInvolvedParty::find($r->json('data.id'))->bank_account_encrypted)->toBe('CM21100012345678');
});

it('REQ-CLM-006 links a provider through its partner party, and scopes by tenant, permission and claim state', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, C7_PERMS));
    $claim = c7Claim($t);
    $gp = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Garage du Port', 'status' => 'ACTIVE']);
    $garage = Partner::create(['tenant_id' => $t->id, 'party_id' => $gp->id, 'type' => 'GARAGE', 'status' => 'ACTIVE', 'legal_name' => 'Garage du Port']);

    $this->postJson(c7Url($claim), ['role' => 'REPAIRER', 'partner_id' => $garage->id], tenantHeader($t))
        ->assertCreated()->assertJsonPath('data.party_id', $gp->id)->assertJsonPath('data.partner_id', $garage->id)->assertJsonPath('data.display_name', 'Garage du Port');

    $other = makeAuthTestTenant('b');
    $this->getJson(c7Url(c7Claim($other)), tenantHeader($t))->assertNotFound();
    $closed = c7Claim($t, 'CLOSED');
    $this->postJson(c7Url($closed), ['role' => 'WITNESS', 'display_name' => 'Late'], tenantHeader($t))->assertStatus(422);

    Passport::actingAs(makeAuthTestUser($t, ['claims.view']));
    $this->postJson(c7Url($claim), ['role' => 'WITNESS', 'display_name' => 'X'], tenantHeader($t))->assertForbidden();
    $this->getJson(c7Url($claim), tenantHeader($t))->assertOk();
});
