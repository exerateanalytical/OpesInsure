<?php

declare(strict_types=1);

/**
 * UI coverage batch 11: document register, correspondence register, delegated authorities, own e-signatures and the
 * underwriter workspace actions call the same services / statements, with the same permissions, as the API routes.
 */

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\DocumentsUnderwriting\CorrespondenceRegister;
use App\Filament\Admin\Pages\DocumentsUnderwriting\DelegatedAuthorities;
use App\Filament\Admin\Pages\DocumentsUnderwriting\DocumentRegister;
use App\Filament\Admin\Pages\DocumentsUnderwriting\MySignatureRequests;
use App\Filament\Admin\Resources\UnderwritingCases\Pages\ViewUnderwritingCase;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingReferralTask;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function duUser(string $tenantId, array $permissions, string $roleCode = 'OPERATIONS_OFFICER'): User
{
    $u = User::create(['full_name' => 'DU '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $roleCode, 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'DU-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function duAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    app(TenantContext::class)->set($this->tenant);
    $this->f['proposal']->update(['status' => 'UNDER_REVIEW']);
    $this->case = UnderwritingCase::create(['tenant_id' => $this->tenant, 'proposal_id' => $this->f['proposal']->id, 'carrier_id' => $this->f['carrier']->id, 'status' => 'QUEUED', 'priority' => 'NORMAL']);
});

it('shows the underwriting actions with the API permissions and hides them without', function () {
    duAs(duUser($this->tenant, ['proposals.read'], 'UNDERWRITER'), $this->tenant);
    $page = Livewire::test(ViewUnderwritingCase::class, ['record' => $this->case->id])->assertOk();
    foreach (['uwAssign', 'uwStartReview', 'uwEvaluate'] as $name) {
        $page->assertActionHidden($name);
    }

    duAs(duUser($this->tenant, ['proposals.read', 'underwriting.assign', 'underwriting.decide'], 'UNDERWRITER'), $this->tenant);
    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->case->id])->assertOk()
        ->assertActionVisible('uwAssign')->assertActionVisible('uwStartReview')->assertActionVisible('uwEvaluate')->assertActionHidden('uwReadyForDecision');
});

it('assigns, starts review, resolves a referral and marks the case ready for decision through UnderwritingService', function () {
    $uw = duUser($this->tenant, ['proposals.read', 'underwriting.assign', 'underwriting.decide'], 'UNDERWRITER');
    $referral = UnderwritingReferralTask::create(['underwriting_case_id' => $this->case->id, 'reason_code' => 'HIGH_VALUE', 'status' => 'OPEN', 'severity' => 'HIGH']);
    duAs($uw, $this->tenant);

    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->case->id])->assertOk()->callAction('uwAssign', ['assignee_id' => $uw->id])->assertHasNoActionErrors()
        ->assertNotified(__('doc_uw_actions.uwAssign.done'));
    expect($this->case->fresh()->assigned_to)->toBe($uw->id);

    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->case->id])->callAction('uwStartReview')->assertNotified(__('doc_uw_actions.uwStartReview.done'));
    expect($this->case->fresh()->status)->toBe('IN_REVIEW');

    // Open referral: the service refuses "ready for decision" and the user sees why.
    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->case->id])->callAction('uwReadyForDecision', ['note' => 'Reviewed'])
        ->assertNotified(__('workflow_actions.failed'));
    expect($this->case->fresh()->status)->toBe('IN_REVIEW');

    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->case->id])
        ->callAction('uwResolveReferral', ['referral_id' => $referral->id, 'notes' => 'Vehicle value confirmed by expert appraisal report.'])
        ->assertNotified(__('doc_uw_actions.uwResolveReferral.done'));
    expect($referral->fresh()->status)->toBe('RESOLVED');

    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->case->id])->callAction('uwReadyForDecision', ['note' => 'Reviewed'])
        ->assertNotified(__('doc_uw_actions.uwReadyForDecision.done'));
    expect($this->case->fresh()->status)->toBe('DECISION_PENDING');
});

it('shows the evaluation refusal when the proposal has no submitted snapshot', function () {
    duAs(duUser($this->tenant, ['proposals.read', 'underwriting.decide'], 'UNDERWRITER'), $this->tenant);
    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->case->id])->callAction('uwEvaluate')->assertNotified(__('workflow_actions.failed'));
    expect($this->case->fresh()->evaluated_at)->toBeNull();
});

it('registers, dispatches and records delivery of correspondence through CorrespondenceService', function () {
    duAs(duUser($this->tenant, ['cases.view']), $this->tenant);
    Livewire::test(CorrespondenceRegister::class)->assertOk()->assertActionHidden(TestAction::make('corRegister')->table());

    duAs(duUser($this->tenant, ['cases.view', 'cases.manage']), $this->tenant);
    Livewire::test(CorrespondenceRegister::class)->callAction(TestAction::make('corRegister')->table(), [
        'direction' => 'OUTBOUND', 'channel' => 'LETTER', 'counterparty_type' => 'CUSTOMER', 'counterparty_name' => 'Jean Mballa', 'subject_line' => 'Claim acknowledgement',
    ])->assertNotified(__('doc_uw_actions.corRegister.done'));
    $row = DB::table('correspondence_register')->where('tenant_id', $this->tenant)->first();
    expect($row->status)->toBe('DRAFT');

    Livewire::test(CorrespondenceRegister::class)->callAction(TestAction::make('corDispatch')->table($row->id), ['proof_type' => 'REGISTERED_MAIL', 'proof_reference' => 'RR-123'])
        ->assertNotified(__('doc_uw_actions.corDispatch.done'));
    expect(DB::table('correspondence_register')->where('id', $row->id)->value('status'))->toBe('DISPATCHED');

    Livewire::test(CorrespondenceRegister::class)->callAction(TestAction::make('corOutcome')->table($row->id), ['delivered' => true])
        ->assertNotified(__('doc_uw_actions.corOutcome.done'));
    expect(DB::table('correspondence_register')->where('id', $row->id)->value('status'))->toBe('DELIVERED');
});

it('registers, reviews and opens a document with the API rules', function () {
    duAs(duUser($this->tenant, ['documents.read']), $this->tenant);
    $sha = hash('sha256', Str::random(20));
    Livewire::test(DocumentRegister::class)->callAction(TestAction::make('docRegister')->table(), [
        'category' => 'ID_CARD', 'storage_key' => 'documents/test/id.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 2048, 'sha256' => $sha,
    ])->assertNotified(__('doc_uw_actions.docRegister.done'));
    $doc = DB::table('documents')->where('sha256', $sha)->first();
    expect($doc->scan_status)->toBe('PENDING')->and(DB::table('document_versions')->where('document_id', $doc->id)->count())->toBe(1);
    Livewire::test(DocumentRegister::class)->assertActionHidden(TestAction::make('docReview')->table($doc->id));

    duAs(duUser($this->tenant, ['documents.read', 'documents.review']), $this->tenant);
    Livewire::test(DocumentRegister::class)->callAction(TestAction::make('docReview')->table($doc->id), ['scan_status' => 'INFECTED', 'verification_status' => 'VERIFIED', 'notes' => 'x'])
        ->assertNotified(__('workflow_actions.failed'));
    Livewire::test(DocumentRegister::class)->callAction(TestAction::make('docReview')->table($doc->id), ['scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'notes' => 'Matches the original.'])
        ->assertNotified(__('doc_uw_actions.docReview.done'));
    expect(DB::table('documents')->where('id', $doc->id)->value('verification_status'))->toBe('VERIFIED');

    Livewire::test(DocumentRegister::class)->callAction(TestAction::make('docAccess')->table($doc->id), ['purpose' => 'CLAIM_REVIEW'])
        ->assertNotified(__('doc_uw_actions.docAccess.done'));
    expect(DB::table('document_access_log')->where('document_id', $doc->id)->where('purpose', 'CLAIM_REVIEW')->count())->toBe(1);
});

it('creates, approves and checks a delegated authority with the API permissions', function () {
    $partner = Partner::create(['tenant_id' => $this->tenant, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'DA Broker', 'status' => 'ACTIVE'])->id,
        'type' => 'BROKER', 'status' => 'ACTIVE']);
    duAs(duUser($this->tenant, ['carrier.authority.manage']), $this->tenant);
    Livewire::test(DelegatedAuthorities::class)->callAction(TestAction::make('daCreate')->table(), [
        'carrier_id' => $this->f['carrier']->id, 'partner_id' => $partner->id, 'agreement_number' => 'DA-'.Str::random(6), 'effective_from' => now()->subDay()->toDateString(),
        'effective_until' => now()->addYear()->toDateString(), 'permitted_lines' => ['AUTOMOBILE'], 'max_policy_premium_minor' => 500000, 'max_claim_authority_minor' => 0,
        'territories' => ['CM'],
    ])->assertNotified(__('doc_uw_actions.daCreate.done'));
    $a = DB::table('delegated_authority_agreements')->where('partner_id', $partner->id)->first();
    expect($a->status)->toBe('DRAFT');
    Livewire::test(DelegatedAuthorities::class)->assertActionHidden(TestAction::make('daApprove')->table($a->id));

    duAs(duUser($this->tenant, ['carrier.authority.approve']), $this->tenant);
    Livewire::test(DelegatedAuthorities::class)->callAction(TestAction::make('daApprove')->table($a->id), ['reason' => 'Broker licence and guarantee verified by carrier.'])
        ->assertNotified(__('doc_uw_actions.daApprove.done'));
    expect(DB::table('delegated_authority_agreements')->where('id', $a->id)->value('status'))->toBe('ACTIVE');

    Livewire::test(DelegatedAuthorities::class)->callAction(TestAction::make('daCheck')->table($a->id), [
        'line_code' => 'AUTOMOBILE', 'premium_minor' => 900000, 'territory' => 'CM', 'effective_at' => now()->toDateTimeString(),
    ])->assertNotified(__('doc_uw_actions.daCheck.outside', ['reason' => 'PREMIUM_AUTHORITY_EXCEEDED']));
});

it('refuses the delegated authority screen without a carrier authority permission', function () {
    duAs(duUser($this->tenant, ['cases.view']), $this->tenant);
    Livewire::test(DelegatedAuthorities::class)->assertForbidden();
    Livewire::test(DocumentRegister::class)->assertForbidden();
});

it('lets the named signer sign and decline their own requests through SignatureService', function () {
    $me = duUser($this->tenant, []);
    $other = duUser($this->tenant, []);
    $make = function () use ($me) {
        $doc = makeMobileTestDocument($this->f['tenant'], $this->f['party']);
        $id = (string) Str::uuid();
        DB::table('signature_requests')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'document_id' => $doc->id, 'provider' => 'MANUAL', 'status' => 'PENDING',
            'document_sha256' => $doc->sha256, 'consent_text' => 'I agree to sign electronically.', 'requested_by' => $me->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('signature_request_signers')->insert(['id' => (string) Str::uuid(), 'signature_request_id' => $id, 'signer_user_id' => $me->id, 'signer_name' => 'Me',
            'signer_role' => 'POLICYHOLDER', 'signing_order' => 1, 'status' => 'PENDING', 'evidence' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $a = $make();
    $b = $make();

    duAs($other, $this->tenant);
    Livewire::test(MySignatureRequests::class)->assertOk()->assertDontSee('POLICYHOLDER');

    duAs($me, $this->tenant);
    Livewire::test(MySignatureRequests::class)->assertSee('POLICYHOLDER')
        ->callAction(TestAction::make('sigSign')->table($a), ['consent_accepted' => true])->assertNotified(__('doc_uw_actions.sigSign.done'));
    expect(DB::table('signature_requests')->where('id', $a)->value('status'))->toBe('COMPLETED');

    Livewire::test(MySignatureRequests::class)->callAction(TestAction::make('sigDecline')->table($b), ['reason' => 'Wrong premium'])
        ->assertNotified(__('doc_uw_actions.sigDecline.done'));
    expect(DB::table('signature_requests')->where('id', $b)->value('status'))->toBe('DECLINED');
});

it('refuses the delegated authority approval to its creator (four-eyes) and lets a second user approve', function () {
    $partner = Partner::create(['tenant_id' => $this->tenant, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'DA Broker 2', 'status' => 'ACTIVE'])->id,
        'type' => 'BROKER', 'status' => 'ACTIVE']);
    $maker = duUser($this->tenant, ['carrier.authority.manage', 'carrier.authority.approve']);
    duAs($maker, $this->tenant);
    Livewire::test(DelegatedAuthorities::class)->callAction(TestAction::make('daCreate')->table(), [
        'carrier_id' => $this->f['carrier']->id, 'partner_id' => $partner->id, 'agreement_number' => 'DA-'.Str::random(6), 'effective_from' => now()->subDay()->toDateString(),
        'effective_until' => now()->addYear()->toDateString(), 'permitted_lines' => ['HEALTH'], 'max_policy_premium_minor' => 100000, 'max_claim_authority_minor' => 0, 'territories' => ['CM'],
    ])->assertNotified(__('doc_uw_actions.daCreate.done'));
    $a = DB::table('delegated_authority_agreements')->where('partner_id', $partner->id)->first();
    expect($a->created_by)->toBe($maker->id);

    Livewire::test(DelegatedAuthorities::class)->callAction(TestAction::make('daApprove')->table($a->id), ['reason' => 'Self approval must be refused by the service.'])
        ->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('delegated_authority_agreements')->where('id', $a->id)->value('status'))->toBe('DRAFT');

    duAs(duUser($this->tenant, ['carrier.authority.approve']), $this->tenant);
    Livewire::test(DelegatedAuthorities::class)->callAction(TestAction::make('daApprove')->table($a->id), ['reason' => 'Independent carrier approver reviewed the terms.'])
        ->assertNotified(__('doc_uw_actions.daApprove.done'));
    expect(DB::table('delegated_authority_agreements')->where('id', $a->id)->value('status'))->toBe('ACTIVE');
});

it('keeps the delegated authority API responses and enforces four-eyes there too', function () {
    $partner = Partner::create(['tenant_id' => $this->tenant, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'DA API Broker', 'status' => 'ACTIVE'])->id,
        'type' => 'BROKER', 'status' => 'ACTIVE']);
    $h = ['X-Tenant-Id' => $this->tenant];
    $maker = duUser($this->tenant, ['carrier.authority.manage', 'carrier.authority.approve']);
    Passport::actingAs($maker);
    $id = $this->postJson('/api/v1/carrier/delegated-authorities', ['carrier_id' => $this->f['carrier']->id, 'partner_id' => $partner->id, 'agreement_number' => 'DA-'.Str::random(6),
        'effective_from' => now()->subDay()->toDateString(), 'effective_until' => now()->addYear()->toDateString(), 'permitted_lines' => ['AUTOMOBILE'],
        'max_policy_premium_minor' => 500000, 'max_claim_authority_minor' => 0, 'territories' => ['CM']], $h)->assertCreated()->assertJsonPath('data.status', 'DRAFT')->json('data.id');
    $this->postJson("/api/v1/carrier/delegated-authorities/{$id}/approve", ['reason' => 'Self approval must be refused by the service.'], $h)
        ->assertStatus(403)->assertSee('MAKER_CHECKER_VIOLATION');

    Passport::actingAs(duUser($this->tenant, ['carrier.authority.approve']));
    $this->postJson("/api/v1/carrier/delegated-authorities/{$id}/approve", ['reason' => 'Independent carrier approver reviewed the terms.'], $h)
        ->assertOk()->assertExactJson(['data' => ['id' => $id, 'status' => 'ACTIVE']]);
    $this->postJson("/api/v1/carrier/delegated-authorities/{$id}/approve", ['reason' => 'Second approval of an active agreement.'], $h)->assertStatus(409);
    $this->postJson("/api/v1/carrier/delegated-authorities/{$id}/check", ['line_code' => 'AUTOMOBILE', 'premium_minor' => 1000, 'territory' => 'CM', 'effective_at' => now()->toIso8601String()], $h)
        ->assertOk()->assertExactJson(['data' => ['allowed' => true, 'reason' => 'WITHIN_AUTHORITY']]);
});

it('keeps stored OCR data on review unless the caller sends it (API and desktop)', function () {
    $h = ['X-Tenant-Id' => $this->tenant];
    $u = duUser($this->tenant, ['documents.read', 'documents.review']);
    Passport::actingAs($u);
    $sha = hash('sha256', Str::random(20));
    $id = $this->postJson('/api/v1/documents', ['category' => 'ID_CARD', 'storage_key' => 'documents/test/x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => $sha], $h)
        ->assertCreated()->assertExactJson(['data' => ['id' => DB::table('documents')->where('sha256', $sha)->value('id'), 'scan_status' => 'PENDING']])->json('data.id');
    $this->postJson('/api/v1/documents', ['category' => 'ID_CARD', 'storage_key' => 'documents/test/y.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => $sha], $h)->assertStatus(409);
    DB::table('documents')->where('id', $id)->update(['ocr_data' => json_encode(['name' => 'MBALLA'])]);

    $this->postJson("/api/v1/documents/{$id}/review", ['scan_status' => 'CLEAN', 'verification_status' => 'NEEDS_REVIEW', 'notes' => 'Scan ok'], $h)
        ->assertOk()->assertExactJson(['data' => ['id' => $id, 'scan_status' => 'CLEAN', 'verification_status' => 'NEEDS_REVIEW']]);
    $this->postJson("/api/v1/documents/{$id}/review", ['scan_status' => 'INFECTED', 'verification_status' => 'VERIFIED', 'notes' => 'x'], $h)->assertStatus(422);
    expect(json_decode(DB::table('documents')->where('id', $id)->value('ocr_data'), true))->toBe(['name' => 'MBALLA']);

    duAs($u, $this->tenant);
    Livewire::test(DocumentRegister::class)->callAction(TestAction::make('docReview')->table($id), ['scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'notes' => 'Matches.'])
        ->assertNotified(__('doc_uw_actions.docReview.done'));
    expect(json_decode(DB::table('documents')->where('id', $id)->value('ocr_data'), true))->toBe(['name' => 'MBALLA']);

    Passport::actingAs($u);
    $this->postJson("/api/v1/documents/{$id}/review", ['scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'notes' => 'OCR corrected', 'ocr_data' => ['name' => 'MBALLA JEAN']], $h)->assertOk();
    expect(json_decode(DB::table('documents')->where('id', $id)->value('ocr_data'), true))->toBe(['name' => 'MBALLA JEAN']);
});
