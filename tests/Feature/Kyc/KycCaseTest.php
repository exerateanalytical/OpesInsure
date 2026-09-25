<?php

declare(strict_types=1);

/**
 * REQ-KYC-001 REQ-KYC-002 REQ-KYC-003 REQ-DUP-007 — KYC on the case engine: risk-based levels, required
 * documents per level (document catalogue canonical codes), MANUAL screening hook, maker-checker decisions,
 * expiry / remediation, and the bind/issue gate. Mobile /mobile/kyc/* is the thin adapter over KycService.
 */

use App\Application\Cases\Models\WorkCase;
use App\Application\Customers\Relationships\PartyRelationshipService;
use App\Application\Kyc\KycGate;
use App\Application\Kyc\KycService;
use App\Application\Kyc\Models\ScreeningCheck;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\KycSubmission;
use App\Models\Party;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';
require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

beforeEach(function () {
    $this->fx = makeMobileCustomerFixture();
    $this->tenant = $this->fx['tenant'];
    $this->maker = makeAuthTestUser($this->tenant, ['kyc.view', 'kyc.manage', 'kyc.review', 'kyc.screen'], 'KYC_MAKER');
    $this->checker = makeAuthTestUser($this->tenant, ['kyc.view', 'kyc.decide'], 'KYC_CHECKER');
});

function kycAs($user, string $method, string $uri, array $body = [])
{
    Passport::actingAs($user);

    return test()->json($method, '/api/v1/'.$uri, $body, tenantHeaderFor(test()->tenant));
}

/** Customer attaches documents (purpose => overrides) through the mobile adapter and submits. */
function kycCustomerSubmits(array $purposes): string
{
    $fx = test()->fx;
    foreach ($purposes as $purpose => $overrides) {
        $doc = makeMobileTestDocument($fx['tenant'], $fx['party'], $overrides);
        kycAs($fx['user'], 'POST', 'mobile/kyc/documents', ['document_id' => $doc->id, 'purpose' => $purpose])->assertStatus(201);
    }
    Passport::actingAs($fx['user']);
    $res = test()->postJson('/api/v1/mobile/kyc/submission', [], tenantHeaderFor($fx['tenant']) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);

    return $res->json('data.id');
}

function kycTenantCustomer(string $type, string $name): Party
{
    $p = Party::create(['type' => $type, 'display_name' => $name, 'status' => 'ACTIVE']);
    DB::table('tenant_customers')->insert(['id' => (string) Str::uuid(), 'tenant_id' => test()->tenant->id, 'party_id' => $p->id, 'customer_number' => 'C-'.Str::random(8),
        'status' => 'ACTIVE', 'private_metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    return $p;
}

function kycClearScreenings(string $id): void
{
    foreach (ScreeningCheck::where('subject_id', $id)->get() as $c) {
        kycAs(test()->maker, 'POST', "kyc/submissions/{$id}/screenings/{$c->id}", ['status' => 'CLEAR', 'list_reference' => 'Reviewer manual check (test)'])->assertOk();
    }
}

function kycApprove(string $id): void
{
    kycAs(test()->maker, 'POST', "kyc/submissions/{$id}/start-review")->assertOk();
    kycClearScreenings($id);
    kycAs(test()->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'APPROVE', 'rationale' => 'Documents match.'])->assertOk();
    kycAs(test()->checker, 'POST', "kyc/submissions/{$id}/decision", ['confirm' => true, 'reason' => 'Confirmed.'])->assertOk();
}

it('REQ-KYC-001 REQ-DUP-007: mobile submit opens a KYC_REVIEW case with a risk-based level, requirements and MANUAL screening', function () {
    $id = kycCustomerSubmits(['ID_FRONT' => [], 'PROOF_OF_ADDRESS' => []]);
    $s = KycSubmission::find($id);

    expect($s->status)->toBe('SUBMITTED')->and($s->kyc_level)->toBe('STANDARD')->and($s->subject_kind)->toBe('INDIVIDUAL')
        ->and($s->screening_status)->toBe('NOT_SCREENED');
    $case = WorkCase::withoutGlobalScopes()->find($s->case_id);
    expect($case->case_type_code)->toBe('KYC_REVIEW')->and($case->status)->toBe('SUBMITTED')->and($case->subject_id)->toBe($id);
    expect(ScreeningCheck::where('subject_id', $id)->pluck('status', 'check_type')->all())->toBe(['SANCTIONS' => 'PENDING', 'PEP' => 'PENDING'])
        ->and(ScreeningCheck::where('subject_id', $id)->value('provider'))->toBe('MANUAL_AUDITED');

    $show = kycAs($this->maker, 'GET', "kyc/submissions/{$id}")->assertOk();
    expect($show->json('data.missing_requirements'))->toBe([])
        ->and(collect($show->json('data.requirements'))->pluck('requirement_code')->sort()->values()->all())->toBe(['ADDRESS', 'IDENTITY']);

    // Mobile profile exposes the same canonical read model (thin adapter).
    $profile = kycAs($this->fx['user'], 'GET', 'mobile/kyc/profile')->assertOk();
    expect($profile->json('data.submission.kyc_level'))->toBe('STANDARD')->and($profile->json('data.submission.status'))->toBe('SUBMITTED');
});

it('REQ-KYC-001: maker-checker approval records an append-only case decision and closes the case', function () {
    $id = kycCustomerSubmits(['ID_FRONT' => [], 'PROOF_OF_ADDRESS' => []]);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/start-review")->assertOk()->assertJsonPath('data.status', 'REVIEWING');

    // Screening still PENDING blocks an APPROVE recommendation.
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'APPROVE', 'rationale' => 'ok'])
        ->assertStatus(422)->assertJsonPath('code', 'KYC_NOT_APPROVABLE')->assertJsonPath('blocking', ['SCREENING_PENDING']);
    kycClearScreenings($id);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'APPROVE', 'rationale' => 'ok'])->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');

    // The maker cannot be the checker, even holding kyc.decide.
    $both = makeAuthTestUser($this->tenant, ['kyc.view', 'kyc.review', 'kyc.decide'], 'KYC_BOTH');
    $id2 = $id;
    DB::table('kyc_submissions')->where('id', $id2)->update(['recommended_by' => $both->id]);
    kycAs($both, 'POST', "kyc/submissions/{$id2}/decision", ['confirm' => true, 'reason' => 'self'])->assertStatus(403)->assertJsonPath('code', 'MAKER_CHECKER');

    kycAs($this->checker, 'POST', "kyc/submissions/{$id}/decision", ['confirm' => true, 'reason' => 'Confirmed.'])->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $s = KycSubmission::find($id);
    $case = WorkCase::withoutGlobalScopes()->find($s->case_id);
    expect($case->status)->toBe('APPROVED')->and($case->closed_at)->not->toBeNull()
        ->and(DB::table('case_decisions')->where('case_id', $case->id)->where('outcome', 'APPROVED')->where('decided_by', $this->checker->id)->count())->toBe(1)
        ->and($s->reviewed_by)->toBe($this->checker->id)->and($s->expiry_basis)->toBe('NONE')
        ->and(DB::table('outbox_messages')->where('event_name', 'kyc_submission.approved')->where('aggregate_id', $id)->exists())->toBeTrue();
    expect(app(KycGate::class)->status($this->tenant->id, $this->fx['party']->id)['verified'])->toBeTrue();
});

it('REQ-KYC-001: a reviewer without kyc.decide cannot decide and a customer cannot reach the staff API', function () {
    $id = kycCustomerSubmits(['ID_FRONT' => [], 'PROOF_OF_ADDRESS' => []]);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/decision", ['confirm' => true, 'reason' => 'x'])->assertStatus(403);
    kycAs($this->fx['user'], 'GET', 'kyc/submissions')->assertStatus(403);
});

it('REQ-KYC-001: missing required documents block approval; a checker can return the file', function () {
    $id = kycCustomerSubmits(['ID_FRONT' => []]);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/start-review")->assertOk();
    kycClearScreenings($id);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'APPROVE', 'rationale' => 'ok'])
        ->assertStatus(422)->assertJsonPath('blocking', ['ADDRESS']);

    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'REJECT', 'rationale' => 'No proof of address.'])->assertOk();
    kycAs($this->checker, 'POST', "kyc/submissions/{$id}/decision", ['confirm' => false, 'reason' => 'Ask the customer instead.'])->assertOk()->assertJsonPath('data.status', 'REVIEWING');
});

it('REQ-KYC-001: request information, customer adds a document and resubmits via mobile; level cannot drop below risk level', function () {
    $id = kycCustomerSubmits(['ID_FRONT' => []]);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/start-review")->assertOk();
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/level", ['kyc_level' => 'SIMPLIFIED', 'reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'KYC_LEVEL_TOO_LOW');
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/request-information", ['reason' => 'Proof of address needed.'])->assertOk()->assertJsonPath('data.status', 'MORE_INFO_REQUIRED');

    $doc = makeMobileTestDocument($this->tenant, $this->fx['party']);
    kycAs($this->fx['user'], 'POST', 'mobile/kyc/documents', ['document_id' => $doc->id, 'purpose' => 'PROOF_OF_ADDRESS'])->assertStatus(201)->assertJsonPath('data.id', $id);
    Passport::actingAs($this->fx['user']);
    $this->postJson('/api/v1/mobile/kyc/submission', [], tenantHeaderFor($this->tenant) + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(201)->assertJsonPath('data.status', 'REVIEWING')->assertJsonPath('data.missing_requirements', []);
    expect(WorkCase::withoutGlobalScopes()->find(KycSubmission::find($id)->case_id)->status)->toBe('REVIEWING')
        ->and(DB::table('outbox_messages')->where('event_name', 'kyc_submission.information_requested')->exists())->toBeTrue();
});

it('REQ-KYC-001: a possible screening match raises the level to ENHANCED and its extra documents', function () {
    $id = kycCustomerSubmits(['ID_FRONT' => [], 'PROOF_OF_ADDRESS' => []]);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/start-review")->assertOk();
    $pep = ScreeningCheck::where('subject_id', $id)->where('check_type', 'PEP')->first();
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/screenings/{$pep->id}", ['status' => 'POSSIBLE_MATCH', 'list_reference' => 'Reviewer note'])
        ->assertOk()->assertJsonPath('data.kyc_level', 'ENHANCED')->assertJsonPath('data.screening_status', 'POSSIBLE_MATCH');
    expect(KycSubmission::find($id)->risk_factors)->toContain('PEP_POSSIBLE_MATCH')
        ->and(kycAs($this->maker, 'GET', "kyc/submissions/{$id}")->json('data.missing_requirements'))->toBe(['TAX_ID']);
});

it('REQ-KYC-002: corporate KYC needs registration, tax id, representative identity and identified UBOs with KYC', function () {
    $company = kycTenantCustomer('ORGANIZATION', 'Acme SARL');

    $open = kycAs($this->maker, 'POST', "kyc/parties/{$company->id}/submissions")->assertStatus(201);
    $id = $open->json('data.id');
    expect($open->json('data.subject_kind'))->toBe('CORPORATE');
    foreach (['RCCM', 'NIU', 'REPRESENTATIVE_NATIONAL_ID'] as $purpose) {
        $doc = makeMobileTestDocument($this->tenant, $company);
        kycAs($this->maker, 'POST', "kyc/submissions/{$id}/documents", ['document_id' => $doc->id, 'purpose' => $purpose])->assertStatus(201);
    }
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/submit")->assertOk()->assertJsonPath('data.kyc_level', 'STANDARD')->assertJsonPath('data.missing_requirements', []);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/start-review")->assertOk();
    kycClearScreenings($id);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'APPROVE', 'rationale' => 'ok'])
        ->assertStatus(422)->assertJsonPath('blocking', ['UBO_NOT_IDENTIFIED']);

    // UBO = a natural person owning 60% (PartyRelationshipService treats type PERSON as natural person):
    // blocked until that person's own KYC is approved.
    $ubo = kycTenantCustomer('PERSON', 'Jane Owner');
    app(PartyRelationshipService::class)->addOwnership($ubo, $company, ['percentage' => 60], $this->tenant->id, $this->maker->id);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'APPROVE', 'rationale' => 'ok'])
        ->assertStatus(422)->assertJsonPath('blocking', ['UBO_KYC_MISSING:'.$ubo->id]);

    $uboKyc = kycAs($this->maker, 'POST', "kyc/parties/{$ubo->id}/submissions")->assertStatus(201)->json('data.id');
    foreach (['NATIONAL_ID', 'PROOF_OF_ADDRESS'] as $purpose) {
        kycAs($this->maker, 'POST', "kyc/submissions/{$uboKyc}/documents", ['document_id' => makeMobileTestDocument($this->tenant, $ubo)->id, 'purpose' => $purpose])->assertStatus(201);
    }
    kycAs($this->maker, 'POST', "kyc/submissions/{$uboKyc}/submit")->assertOk();
    kycApprove($uboKyc);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'APPROVE', 'rationale' => 'ok'])->assertOk();
    kycAs($this->checker, 'POST', "kyc/submissions/{$id}/decision", ['confirm' => true, 'reason' => 'ok'])->assertOk()
        ->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.corporate.ubos.0.kyc_status', 'APPROVED');
})->skip(fn () => ! class_exists(PartyRelationshipService::class), 'UBO register (REQ-PTY-003) not present');

it('REQ-KYC-003: expiry from document validity, kyc:expire, remediation keeps the original, gate follows', function () {
    $id = kycCustomerSubmits(['ID_FRONT' => ['valid_until' => now()->addDays(10)], 'PROOF_OF_ADDRESS' => []]);
    kycApprove($id);
    $s = KycSubmission::find($id);
    expect($s->expiry_basis)->toBe('DOCUMENT_VALID_UNTIL')->and($s->expires_at->isSameDay(now()->addDays(10)))->toBeTrue();
    expect(collect(kycAs($this->maker, 'GET', 'kyc/expiring')->assertOk()->json('data'))->pluck('id')->all())->toBe([$id]);

    $this->travel(11)->days();
    $this->artisan('kyc:expire')->assertSuccessful();
    expect(KycSubmission::find($id)->status)->toBe('EXPIRED')
        ->and(app(KycGate::class)->status($this->tenant->id, $this->fx['party']->id))->toMatchArray(['verified' => false, 'reason' => 'EXPIRED']);

    $new = kycAs($this->maker, 'POST', "kyc/submissions/{$id}/remediate", ['reason' => 'ID card expired.'])->assertStatus(201);
    expect($new->json('data.status'))->toBe('DRAFT')->and($new->json('data.supersedes_submission_id'))->toBe($id);
    $orig = KycSubmission::find($id);
    expect($orig->status)->toBe('EXPIRED')->and($orig->superseded_by_submission_id)->toBe($new->json('data.id'))
        ->and($orig->documents()->count())->toBe(2);
    kycAs($this->maker, 'POST', "kyc/submissions/{$id}/remediate", ['reason' => 'again'])->assertStatus(409);

    // The customer's mobile draft is the remediation submission.
    $doc = makeMobileTestDocument($this->tenant, $this->fx['party']);
    kycAs($this->fx['user'], 'POST', 'mobile/kyc/documents', ['document_id' => $doc->id, 'purpose' => 'PASSPORT'])->assertStatus(201)->assertJsonPath('data.id', $new->json('data.id'));
});

it('REQ-KYC-001: bind/issue gate is OFF by default, WARN audits, ENFORCE refuses until KYC is approved', function () {
    $gate = app(KycGate::class);
    $party = $this->fx['party']->id;
    expect($gate->mode($this->tenant->id))->toBe('OFF');
    $gate->assertMayProceed($this->tenant->id, $party, 'BIND', 'proposal', (string) Str::uuid());

    Tenant::whereKey($this->tenant->id)->update(['settings' => ['kyc' => ['gate_mode' => 'WARN']]]);
    $gate->assertMayProceed($this->tenant->id, $party, 'BIND', 'proposal', (string) Str::uuid());
    expect(DB::table('audit_log')->where('action', 'kyc.gate.warned')->exists())->toBeTrue();

    Tenant::whereKey($this->tenant->id)->update(['settings' => ['kyc' => ['gate_mode' => 'ENFORCE']]]);
    expect(fn () => $gate->assertMayProceed($this->tenant->id, $party, 'ISSUE', 'policy_issuance_request', (string) Str::uuid()))
        ->toThrow(ApiProblemException::class, 'NOT_STARTED');

    kycApprove(kycCustomerSubmits(['ID_FRONT' => [], 'PROOF_OF_ADDRESS' => []]));
    $gate->assertMayProceed($this->tenant->id, $party, 'ISSUE', 'policy_issuance_request', (string) Str::uuid());
    kycAs($this->maker, 'GET', "kyc/parties/{$party}/status")->assertOk()->assertJsonPath('data.verified', true)->assertJsonPath('data.gate_mode', 'ENFORCE');
});

it('REQ-KYC-001: requirements endpoint resolves catalogue codes and marks the platform default UNVERIFIED', function () {
    $r = kycAs($this->maker, 'GET', 'kyc/requirements?subject_kind=INDIVIDUAL&kyc_level=ENHANCED')->assertOk();
    expect(collect($r->json('data'))->pluck('requirement_code')->sort()->values()->all())->toBe(['ADDRESS', 'IDENTITY', 'TAX_ID'])
        ->and($r->json('data.0.source'))->toBe('PLATFORM_DEFAULT_UNVERIFIED');
});

it('REQ-KYC-001: a second submission cannot enter review while one is in progress', function () {
    kycCustomerSubmits(['ID_FRONT' => [], 'PROOF_OF_ADDRESS' => []]);
    $doc = makeMobileTestDocument($this->tenant, $this->fx['party']);
    kycAs($this->fx['user'], 'POST', 'mobile/kyc/documents', ['document_id' => $doc->id, 'purpose' => 'PASSPORT'])->assertStatus(201);
    Passport::actingAs($this->fx['user']);
    $this->postJson('/api/v1/mobile/kyc/submission', [], tenantHeaderFor($this->tenant) + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(409)->assertJsonPath('code', 'KYC_REVIEW_IN_PROGRESS');
    expect(app(KycService::class))->toBeInstanceOf(KycService::class);
});
