<?php

declare(strict_types=1);

/*
 | Batch 4A — party golden record. REQ-PTY-002 (bitemporal party roles; LOCK-006 policyholder ≠ insured ≠ beneficiary),
 | REQ-PTY-003 (relationships + ownership/UBO graph), REQ-PTY-004 (scored duplicate candidates, no auto-merge,
 | maker-checker merge through ApprovalService, survivorship log, unmerge).
 */

use App\Application\Approvals\ApprovalService;
use App\Application\Customers\Matching\PartyMatcher;
use App\Application\Customers\Matching\PartyMergeService;
use App\Application\Customers\PartyService;
use App\Application\Customers\Relationships\PartyRelationshipService;
use App\Application\Customers\Roles\PartyRoleService;
use App\Application\MasterData\MasterDataMergeService;
use App\Domain\Tenancy\TenantContext;
use App\Models\ApprovalRequest;
use App\Models\Parties\EntityMatchCandidate;
use App\Models\Parties\PartyRole;
use App\Models\Party;
use App\Models\TenantCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

const B4A_ALL = ['parties.manage', 'parties.roles.manage', 'parties.relationships.manage', 'parties.match.review', 'parties.merge.request', 'parties.merge.approve'];

function b4aParty(string $name, string $type = 'PERSON', array $identity = []): Party
{
    $p = Party::create(['type' => $type, 'display_name' => $name, 'legal_identity' => $identity, 'status' => 'ACTIVE']);
    TenantCustomer::create(['tenant_id' => test()->tenant->id, 'party_id' => $p->id, 'customer_number' => 'C-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE']);

    return $p;
}

beforeEach(function () {
    $this->tenant = makeAuthTestTenant('B4A');
    app(TenantContext::class)->set($this->tenant->id);
});

// ---------------------------------------------------------------- REQ-PTY-002

it('keeps policyholder, insured and beneficiary as separate explicit roles', function () {
    $svc = app(PartyRoleService::class);
    [$holder, $insured, $benef] = [b4aParty('Paul Holder'), b4aParty('Ines Insured'), b4aParty('Bea Beneficiary')];
    $policyId = (string) Str::uuid();
    $ctx = ['context_type' => 'policy', 'context_id' => $policyId];
    $svc->assign($holder, ['role_code' => 'POLICYHOLDER'] + $ctx, $this->tenant->id, null);
    $svc->assign($insured, ['role_code' => 'INSURED'] + $ctx, $this->tenant->id, null);
    $svc->assign($benef, ['role_code' => 'BENEFICIARY'] + $ctx, $this->tenant->id, null);

    $roles = $svc->forContext('policy', $policyId, $this->tenant->id);
    expect($roles->pluck('role_code', 'party_id')->all())->toBe([$benef->id => 'BENEFICIARY', $insured->id => 'INSURED', $holder->id => 'POLICYHOLDER'])
        // no role is implied: the policyholder is not the insured unless recorded
        ->and($svc->asOf($holder->id)->pluck('role_code')->all())->toBe(['POLICYHOLDER'])
        ->and(fn () => $svc->assign($insured, ['role_code' => 'POLICYHOLDER'] + $ctx, $this->tenant->id, null))->toThrow(ValidationException::class)
        ->and(fn () => $svc->assign($holder, ['role_code' => 'NOT_A_ROLE'], $this->tenant->id, null))->toThrow(ValidationException::class)
        ->and(PartyRoleService::ROLES)->toHaveCount(24); // 16 platform roles + 8 owner workflow data master roles (wm2)

    // the same person may hold two roles, but only when both are recorded
    $svc->assign($holder, ['role_code' => 'INSURED'] + $ctx, $this->tenant->id, null);
    expect($svc->asOf($holder->id)->pluck('role_code')->all())->toBe(['INSURED', 'POLICYHOLDER']);
})->group('REQ-PTY-002');

it('ends roles bitemporally without losing history', function () {
    $svc = app(PartyRoleService::class);
    $p = b4aParty('Tim Temporal');
    $role = $svc->assign($p, ['role_code' => 'PAYER', 'valid_from' => '2026-01-01'], $this->tenant->id, null);
    $knownBefore = now()->toIso8601String();
    $this->travel(1)->seconds();
    $ended = $svc->end($role, '2026-06-30', 'payer changed', null);

    expect($ended->supersedes_id)->toBe($role->id)->and($role->fresh()->superseded_at)->not->toBeNull()
        ->and($svc->asOf($p->id, '2026-03-01')->pluck('role_code')->all())->toBe(['PAYER'])
        ->and($svc->asOf($p->id, '2026-08-01')->all())->toBe([])
        // as known before the change, the role was open-ended
        ->and($svc->asOf($p->id, '2026-08-01', $knownBefore)->pluck('id')->all())->toBe([$role->id])
        ->and(PartyRole::where('party_id', $p->id)->count())->toBe(2)
        ->and(fn () => $svc->end($role->fresh(), '2026-05-01', 'again', null))->toThrow(ValidationException::class);
})->group('REQ-PTY-002');

it('serves roles over the API with permission checks', function () {
    $p = b4aParty('Api Person');
    $h = tenantHeader($this->tenant);
    Passport::actingAs(makeAuthTestUser($this->tenant, ['parties.manage']));
    $this->getJson('/api/v1/party-roles/catalogue', $h)->assertOk()->assertJsonCount(24, 'data');
    $this->postJson("/api/v1/parties/{$p->id}/roles", ['role_code' => 'INSURED'], $h)->assertForbidden();
    Passport::actingAs(makeAuthTestUser($this->tenant, B4A_ALL));
    $id = $this->postJson("/api/v1/parties/{$p->id}/roles", ['role_code' => 'INSURED'], $h)->assertCreated()->json('data.id');
    $this->getJson("/api/v1/parties/{$p->id}/roles", $h)->assertOk()->assertJsonPath('data.0.role_code', 'INSURED');
    $this->postJson("/api/v1/party-roles/$id/end", ['valid_to' => now()->addDay()->toDateString(), 'reason' => 'left'], $h)->assertOk();
    // parties of another tenant are invisible
    $other = Party::create(['type' => 'PERSON', 'display_name' => 'Elsewhere', 'legal_identity' => [], 'status' => 'ACTIVE']);
    $this->getJson("/api/v1/parties/{$other->id}/roles", $h)->assertNotFound();
})->group('REQ-PTY-002');

it('adds the golden-record schema additively on parties', function () {
    expect(Schema::hasTable('party_roles'))->toBeTrue()
        ->and(Schema::hasColumns('parties', ['merged_into_id', 'merged_at']))->toBeTrue();
})->group('REQ-PTY-002');

// ---------------------------------------------------------------- REQ-PTY-003

it('builds a relationship graph and computes UBOs through layered ownership', function () {
    $svc = app(PartyRelationshipService::class);
    [$alice, $bob, $carol] = [b4aParty('Alice A'), b4aParty('Bob B'), b4aParty('Carol C')];
    [$target, $holdco] = [b4aParty('Target SARL', 'ORGANIZATION'), b4aParty('Holdco SA', 'ORGANIZATION')];

    $svc->link($alice, $bob, ['type' => 'SPOUSE'], $this->tenant->id, null);
    expect(fn () => $svc->link($bob, $alice, ['type' => 'SPOUSE'], $this->tenant->id, null))->toThrow(ValidationException::class)   // symmetric
        ->and(fn () => $svc->link($target, $alice, ['type' => 'EMPLOYEE_OF'], $this->tenant->id, null))->toThrow(ValidationException::class);
    $svc->link($carol, $target, ['type' => 'EMPLOYEE_OF'], $this->tenant->id, null);

    // Target: Alice 20% direct, Holdco 80%; Holdco: Alice 50%, Bob 30%, Carol 20%
    $svc->addOwnership($alice, $target, ['percentage' => 20], $this->tenant->id, null);
    $svc->addOwnership($holdco, $target, ['percentage' => 80], $this->tenant->id, null);
    $svc->addOwnership($alice, $holdco, ['percentage' => 50], $this->tenant->id, null);
    $svc->addOwnership($bob, $holdco, ['percentage' => 30], $this->tenant->id, null);
    $svc->addOwnership($carol, $holdco, ['percentage' => 20], $this->tenant->id, null);
    expect(fn () => $svc->addOwnership($bob, $target, ['percentage' => 5], $this->tenant->id, null))->toThrow(ValidationException::class)   // >100%
        ->and(fn () => $svc->addOwnership($target, $alice, ['percentage' => 5], $this->tenant->id, null))->toThrow(ValidationException::class);

    $ubo = $svc->ultimateBeneficialOwners($target);
    // Alice 20 + 0.8*50 = 60; Bob 0.8*30 = 24 (< 25); Carol 16
    expect(collect($ubo['owners'])->pluck('effective_percentage', 'display_name')->all())->toBe(['Alice A' => 60.0])
        ->and($ubo['threshold_verified'])->toBeFalse()
        ->and(collect($svc->ultimateBeneficialOwners($target, 10)['owners'])->pluck('display_name')->all())->toBe(['Alice A', 'Bob B', 'Carol C']);

    $graph = $svc->graph($alice, 2);
    expect(collect($graph['nodes'])->pluck('id')->all())->toContain($bob->id, $target->id, $holdco->id, $carol->id)
        ->and(collect($graph['edges'])->pluck('kind')->unique()->sort()->values()->all())->toBe(['ownership', 'relationship']);
})->group('REQ-PTY-003');

it('exposes relationships, ownership, UBO and graph over the API', function () {
    Passport::actingAs(makeAuthTestUser($this->tenant, B4A_ALL));
    $h = tenantHeader($this->tenant);
    [$p, $org] = [b4aParty('Owner Person'), b4aParty('Owned Co', 'ORGANIZATION')];
    $this->postJson("/api/v1/parties/{$p->id}/relationships", ['to_party_id' => $org->id, 'type' => 'DIRECTOR_OF'], $h)->assertCreated();
    $oi = $this->postJson("/api/v1/parties/{$org->id}/ownership", ['owner_party_id' => $p->id, 'percentage' => 40], $h)->assertCreated()->json('data.id');
    $this->getJson("/api/v1/parties/{$org->id}/ubo", $h)->assertOk()->assertJsonPath('data.owners.0.party_id', $p->id);
    $this->getJson("/api/v1/parties/{$p->id}/graph", $h)->assertOk()->assertJsonCount(2, 'data.edges');
    $this->postJson("/api/v1/ownership-interests/$oi/end", ['reason' => 'sold'], $h)->assertOk()->assertJsonPath('data.status', 'ENDED');
    $this->getJson("/api/v1/parties/{$org->id}/ubo", $h)->assertOk()->assertJsonCount(0, 'data.owners');
})->group('REQ-PTY-003');

// ---------------------------------------------------------------- REQ-PTY-004

it('scores probable duplicates with reasons and never merges automatically', function () {
    $a = b4aParty('Marie Ngono', 'PERSON', ['date_of_birth' => '1990-04-01']);
    $b = b4aParty('NGONO Marie', 'PERSON', ['date_of_birth' => '1990-04-01']);
    $c = b4aParty('Marie Ngono', 'PERSON', ['date_of_birth' => '1970-01-01']);
    b4aParty('Jean Dupont');
    app(PartyService::class)->addIdentifier($a, 'NATIONAL_ID', '123456789');

    $found = app(PartyMatcher::class)->scan($a, $this->tenant->id);
    $byOther = collect($found)->keyBy(fn ($x) => $x->party_a_id === $a->id ? $x->party_b_id : $x->party_a_id);
    expect($byOther)->toHaveCount(1)   // same name but different DOB (0.35) stays below the 0.50 floor
        ->and($byOther[$b->id]->score)->toBe(0.55)->and($byOther[$b->id]->band)->toBe('LOW')
        ->and(collect($byOther[$b->id]->reasons)->pluck('rule')->all())->toBe(['NAME', 'DATE_OF_BIRTH']);
    // a shared identifier (e.g. legacy / imported row under another country code) dominates the score
    app(PartyService::class)->addIdentifier($c, 'PASSPORT', 'P1234567');
    DB::table('party_identifiers')->where('party_id', $c->id)->update(['value_hash' => DB::table('party_identifiers')->where('party_id', $a->id)->value('value_hash'), 'type' => 'NATIONAL_ID', 'country_code' => 'GA']);
    [$score] = app(PartyMatcher::class)->score($a, $c);
    expect($score)->toBe(1.0)->and(PartyMatcher::band($score))->toBe('HIGH');
    expect($a->fresh()->merged_into_id)->toBeNull()->and($b->fresh()->status)->toBe('ACTIVE')
        ->and($byOther[$b->id]->case_id)->not->toBeNull()
        ->and(DB::table('cases')->where('id', $byOther[$b->id]->case_id)->value('case_type_code'))->toBe('DATA_STEWARD');

    // rescan is idempotent; dismissed pairs are not reopened
    app(PartyMatcher::class)->scan($a, $this->tenant->id);
    expect(EntityMatchCandidate::count())->toBe(2);   // b + c (c now shares an identifier)
    app(PartyMatcher::class)->dismiss($byOther[$b->id], makeAuthTestUser($this->tenant, [])->id, 'different people');
    app(PartyMatcher::class)->scan($b, $this->tenant->id);
    expect($byOther[$b->id]->fresh()->status)->toBe('DISMISSED');
})->group('REQ-PTY-004');

it('merges only after a different checker approves, logs survivorship and can unmerge', function () {
    $survivor = b4aParty('Paul Mbarga', 'PERSON', ['date_of_birth' => '1985-02-02']);
    $dupe = b4aParty('MBARGA Paul', 'PERSON', ['date_of_birth' => '1985-02-02', 'nationality' => 'CM']);
    $friend = b4aParty('Friend F');
    $roles = app(PartyRoleService::class);
    $links = app(PartyRelationshipService::class);
    $dupeRole = $roles->assign($dupe, ['role_code' => 'BENEFICIARY', 'context_type' => 'policy', 'context_id' => (string) Str::uuid()], $this->tenant->id, null);
    $rel = $links->link($dupe, $friend, ['type' => 'HOUSEHOLD_MEMBER'], $this->tenant->id, null);
    $cand = collect(app(PartyMatcher::class)->scan($survivor))->first(fn ($c) => in_array($dupe->id, [$c->party_a_id, $c->party_b_id], true));
    expect($cand)->not->toBeNull();

    $maker = makeAuthTestUser($this->tenant, B4A_ALL);
    $checker = makeAuthTestUser($this->tenant, B4A_ALL);
    $svc = app(PartyMergeService::class);
    $m = $svc->request($survivor, $dupe, $maker, ['display_name' => 'MERGED'], 'same person', $cand);
    expect($m->status)->toBe('PENDING')->and($dupe->fresh()->merged_into_id)->toBeNull()
        ->and($cand->fresh()->status)->toBe('MERGE_REQUESTED')
        ->and(ApprovalRequest::find($m->approval_request_id)->action_code)->toBe('entity.merge')
        ->and(fn () => $svc->request($survivor, $dupe, $maker))->toThrow(ValidationException::class)
        ->and(fn () => $svc->approveMerge($m, $maker))->toThrow(ValidationException::class);   // maker ≠ checker

    // decided through the generic approval inbox → routed to the party handler
    app(ApprovalService::class)->approve(ApprovalRequest::find($m->approval_request_id), $checker, 'verified ID');
    $m->refresh();
    $s = $survivor->fresh();
    expect($m->status)->toBe('MERGED')
        ->and($dupe->fresh()->merged_into_id)->toBe($survivor->id)->and($dupe->fresh()->status)->toBe('MERGED')
        ->and(Party::whereKey($dupe->id)->exists())->toBeTrue()                   // non-destructive
        ->and($s->display_name)->toBe('MBARGA Paul')                               // explicit rule MERGED
        ->and($s->legal_identity['nationality'])->toBe('CM')                       // blank filled from merged
        ->and(collect($m->survivorship_log)->firstWhere('field', 'display_name')['chosen_from'])->toBe('MERGED')
        ->and($dupeRole->fresh()->party_id)->toBe($survivor->id)
        ->and($rel->fresh()->from_party_id === $survivor->id || $rel->fresh()->to_party_id === $survivor->id)->toBeTrue()
        ->and($svc->resolve($dupe->id)->id)->toBe($survivor->id)
        ->and($cand->fresh()->status)->toBe('MERGED')
        ->and(DB::table('audit_log')->where('action', 'party.merged')->exists())->toBeTrue();

    $svc->unmerge($m, $checker, 'wrong person after all');
    expect($m->fresh()->status)->toBe('UNMERGED')->and($dupe->fresh()->merged_into_id)->toBeNull()->and($dupe->fresh()->status)->toBe('ACTIVE')
        ->and($survivor->fresh()->display_name)->toBe('Paul Mbarga')
        ->and($survivor->fresh()->legal_identity)->not->toHaveKey('nationality')
        ->and($dupeRole->fresh()->party_id)->toBe($dupe->id)
        ->and(fn () => $svc->unmerge($m->fresh(), $checker, 'again'))->toThrow(ValidationException::class);
})->group('REQ-PTY-004');

it('rejects a merge and still routes master-data merges through the same entity.merge action', function () {
    [$a, $b] = [b4aParty('Rex One'), b4aParty('Rex One')];
    $maker = makeAuthTestUser($this->tenant, B4A_ALL);
    $checker = makeAuthTestUser($this->tenant, B4A_ALL);
    $m = app(PartyMergeService::class)->request($a, $b, $maker);
    app(ApprovalService::class)->reject(ApprovalRequest::find($m->approval_request_id), $checker, 'not the same');
    expect($m->fresh()->status)->toBe('REJECTED')->and($b->fresh()->merged_into_id)->toBeNull();

    $domainId = (string) Str::uuid();
    DB::table('master_data_domains')->insert(['id' => $domainId, 'code' => 'b4a', 'label_en' => 'B4A', 'label_fr' => 'B4A']);
    $list = App\Models\MasterData\MasterDataList::create(['domain_id' => $domainId, 'domain_code' => 'b4a', 'code' => 'x', 'label_en' => 'X', 'label_fr' => 'X']);
    $mk = fn ($code) => App\Models\MasterData\MasterDataValue::create(['list_id' => $list->id, 'domain_code' => 'b4a', 'list_code' => 'x', 'code' => $code, 'label_en' => 'Same', 'label_fr' => 'Same']);
    [$v1, $v2] = [$mk('A'), $mk('B')];
    $mr = app(MasterDataMergeService::class)->request($v2, $v1, $maker);
    app(ApprovalService::class)->approve(ApprovalRequest::find($mr->approval_request_id), $checker);
    expect($mr->fresh()->status)->toBe('MERGED');
})->group('REQ-PTY-004');

it('runs scan → request → decide → unmerge over the API', function () {
    $h = tenantHeader($this->tenant);
    $a = b4aParty('Zoe Kamga', 'PERSON', ['date_of_birth' => '2000-01-01']);
    $b = b4aParty('Zoe Kamga', 'PERSON', ['date_of_birth' => '2000-01-01']);
    Passport::actingAs(makeAuthTestUser($this->tenant, ['parties.match.review', 'parties.merge.request']));
    $this->postJson("/api/v1/parties/{$a->id}/match-scan", [], $h)->assertOk()->assertJsonPath('data.0.band', 'LOW');
    $cid = $this->getJson('/api/v1/party-match-candidates', $h)->assertOk()->assertJsonPath('data.data.0.party_a_name', 'Zoe Kamga')->json('data.data.0.id');
    $mid = $this->postJson('/api/v1/party-merges', ['survivor_party_id' => $a->id, 'merged_party_id' => $b->id, 'candidate_id' => $cid, 'reason' => 'dup'], $h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/party-merges/$mid/decision", ['decision' => 'APPROVED'], $h)->assertForbidden();
    Passport::actingAs(makeAuthTestUser($this->tenant, ['parties.match.review', 'parties.merge.approve']));
    $this->postJson("/api/v1/party-merges/$mid/decision", ['decision' => 'APPROVED'], $h)->assertOk()->assertJsonPath('data.status', 'MERGED');
    $this->postJson("/api/v1/party-merges/$mid/unmerge", ['reason' => 'mistake'], $h)->assertOk()->assertJsonPath('data.status', 'UNMERGED');
})->group('REQ-PTY-004');

// ---------------------------------------------------------------- REQ-CRM-002

it('serves a data-scoped Customer 360 overview', function () {
    $h = tenantHeader($this->tenant);
    $p = b4aParty('Carla Customer');
    app(PartyRoleService::class)->assign($p, ['role_code' => 'CUSTOMER'], $this->tenant->id, null);
    DB::table('kyc_submissions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'party_id' => $p->id, 'status' => 'SUBMITTED', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    Passport::actingAs(makeAuthTestUser($this->tenant, [], 'COMPLIANCE_ADMIN'));
    $this->getJson("/api/v1/customers/{$p->id}/overview", $h)->assertOk()
        ->assertJsonPath('data.party.display_name', 'Carla Customer')->assertJsonPath('data.roles.0.role_code', 'CUSTOMER')
        ->assertJsonPath('data.kyc.0.status', 'SUBMITTED')->assertJsonPath('data.timeline.0.kind', 'kyc')->assertJsonPath('data.counts.policies', 0);

    // a customer-role user only sees its own record
    Passport::actingAs(makeAuthTestUser($this->tenant, [], 'CUSTOMER'));
    $this->getJson("/api/v1/customers/{$p->id}/overview", $h)->assertNotFound();
})->group('REQ-CRM-002');
