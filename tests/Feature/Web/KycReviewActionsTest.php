<?php

declare(strict_types=1);

/**
 * REQ-KYC-001..003 staff KYC review screen (admin panel): KycSubmissionResource + KycActions call the same
 * KycService methods, with the same permissions, as routes/kyc.php.
 */

use App\Application\Kyc\KycService;
use App\Application\Kyc\Models\ScreeningCheck;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\KycSubmissions\KycSubmissionResource;
use App\Filament\Admin\Resources\KycSubmissions\Pages\ListKycSubmissions;
use App\Filament\Admin\Resources\KycSubmissions\Pages\ViewKycSubmission;
use App\Models\KycSubmission;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function kycUiUser(string $tenantId, array $permissions, string $roleCode): User
{
    $u = User::create(['full_name' => 'KYC '.$roleCode.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    // COMPLIANCE_ADMIN membership opens the admin panel; what the user may do comes only from the role's permissions.
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => 'COMPLIANCE_ADMIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => $roleCode.'-'.Str::random(6), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function kycUiAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

/** Customer submission (ID + proof of address) through KycService, as the mobile adapter does. */
function kycUiSubmitted(array $f): KycSubmission
{
    $kyc = app(KycService::class);
    $s = $kyc->draftFor($f['party'], $f['tenant']->id);
    foreach (['ID_FRONT', 'PROOF_OF_ADDRESS'] as $purpose) {
        $s = $kyc->attachDocument($s, makeMobileTestDocument($f['tenant'], $f['party']), $purpose, $f['user']);
    }

    return $kyc->submit($s->fresh(), null, $f['user'])->fresh();
}

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    app(TenantContext::class)->set($this->tenant);
    $this->maker = kycUiUser($this->tenant, ['kyc.view', 'kyc.manage', 'kyc.review', 'kyc.screen'], 'KYC_MAKER');
    $this->checker = kycUiUser($this->tenant, ['kyc.view', 'kyc.decide'], 'KYC_CHECKER');
    $this->s = kycUiSubmitted($this->f);
});

it('renders the KYC review list and detail for kyc.view and refuses users without it', function () {
    kycUiAs(kycUiUser($this->tenant, ['claims.view'], 'CLAIMS'), $this->tenant);
    expect(KycSubmissionResource::canViewAny())->toBeFalse();
    $this->get(KycSubmissionResource::getUrl('index', panel: 'admin'))->assertForbidden();
    $this->get(KycSubmissionResource::getUrl('view', ['record' => $this->s->id], panel: 'admin'))->assertForbidden();
    kycUiAs(kycUiUser($this->tenant, ['claims.view'], 'CLAIMS'), $this->tenant); // the HTTP requests above reset the tenant context
    Livewire::test(ListKycSubmissions::class)->assertForbidden();

    kycUiAs($this->maker, $this->tenant);
    expect(KycSubmissionResource::canViewAny())->toBeTrue()->and(KycSubmissionResource::canCreate())->toBeFalse();
    Livewire::test(ListKycSubmissions::class)->assertOk()->assertCanSeeTableRecords([$this->s]);
    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])->assertOk()->assertActionVisible('kycStartReview');
});

it('hides every review action from a kyc.view-only user', function () {
    kycUiAs(kycUiUser($this->tenant, ['kyc.view'], 'KYC_READER'), $this->tenant);
    $page = Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])->assertOk();
    foreach (['kycStartReview', 'kycSetLevel', 'kycAssessRisk', 'kycRecordScreening', 'kycRequestInformation', 'kycDeclareSources'] as $name) {
        $page->assertActionHidden($name);
    }
    expect($this->s->fresh()->status)->toBe('SUBMITTED');
});

it('drives start review, screening, recommend and a maker-checker decision through KycService', function () {
    kycUiAs($this->maker, $this->tenant);
    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])
        ->assertActionHidden('kycRecommend')->assertActionHidden('kycDecide')
        ->callAction('kycStartReview')->assertNotified(__('kyc_actions.kycStartReview.done'));
    expect($this->s->fresh()->status)->toBe('REVIEWING');

    $checks = ScreeningCheck::where('subject_type', 'kyc_submission')->where('subject_id', $this->s->id)->get();
    expect($checks)->not->toBeEmpty();
    foreach ($checks as $c) {
        Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])
            ->callAction('kycRecordScreening', ['check_id' => $c->id, 'status' => 'CLEAR', 'list_reference' => 'Manual list check (test)'])
            ->assertNotified(__('kyc_actions.kycRecordScreening.done'));
    }
    expect(ScreeningCheck::whereKey($checks->pluck('id'))->where('status', 'CLEAR')->count())->toBe($checks->count());

    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])
        ->callAction('kycRecommend', ['outcome' => 'APPROVE', 'rationale' => 'Documents match.'])->assertNotified(__('kyc_actions.kycRecommend.done'));
    $s = $this->s->fresh();
    expect($s->status)->toBe('PENDING_APPROVAL')->and($s->recommended_by)->toBe($this->maker->id);

    // The maker cannot hold kyc.decide here, so the decision action is hidden for them.
    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])->assertActionHidden('kycDecide');

    kycUiAs($this->checker, $this->tenant);
    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])
        ->assertActionHidden('kycRequestInformation')
        ->callAction('kycDecide', ['confirm' => true, 'reason' => 'Confirmed.'])->assertNotified(__('kyc_actions.kycDecide.done'));
    $s = $this->s->fresh();
    expect($s->status)->toBe('APPROVED')->and($s->approved_at)->not->toBeNull();

    kycUiAs($this->maker, $this->tenant);
    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])
        ->callAction('kycRemediate', ['reason' => 'Periodic refresh'])->assertNotified(__('kyc_actions.kycRemediate.done'));
    expect($this->s->fresh()->superseded_by_submission_id)->not->toBeNull();
});

it('requests information and raises the level; service refusals are shown, not swallowed', function () {
    kycUiAs($this->maker, $this->tenant);
    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])
        ->callAction('kycSetLevel', ['kyc_level' => 'ENHANCED', 'reason' => 'Complex ownership'])->assertNotified(__('kyc_actions.kycSetLevel.done'));
    expect($this->s->fresh()->kyc_level)->toBe('ENHANCED');

    // Lowering below the risk-based level is refused by KycService and the status is unchanged.
    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])
        ->callAction('kycSetLevel', ['kyc_level' => 'SIMPLIFIED', 'reason' => 'Try lower'])->assertNotified(__('workflow_actions.failed'));

    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])
        ->callAction('kycRequestInformation', ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])
        ->callAction('kycRequestInformation', ['reason' => 'Proof of address is older than 3 months.'])->assertNotified(__('kyc_actions.kycRequestInformation.done'));
    expect($this->s->fresh()->status)->toBe('MORE_INFO_REQUIRED');

    Livewire::test(ViewKycSubmission::class, ['record' => $this->s->id])
        ->assertActionHidden('kycStartReview')->assertActionVisible('kycAttachDocument')
        ->callAction('kycDeclareSources', ['source_of_funds' => ['description' => 'Salary', 'origin' => 'Employment'], 'reason' => 'Declared at interview'])
        ->assertNotified(__('kyc_actions.kycDeclareSources.done'));
});
