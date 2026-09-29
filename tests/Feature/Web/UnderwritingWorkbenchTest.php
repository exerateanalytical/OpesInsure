<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\Underwriting\UnderwritingDashboard;
use App\Filament\Admin\Resources\UnderwritingCases\Pages\ListUnderwritingCases;
use App\Filament\Admin\Resources\UnderwritingCases\Pages\ViewUnderwritingCase;
use App\Filament\Admin\Resources\UnderwritingCases\UnderwritingCaseResource;
use App\Models\Claim;
use App\Models\Policy;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingDecision;
use App\Models\UnderwritingReferralTask;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Q8 underwriting workbench (docs/LAUNCH_SCREEN_GAPS_2026-09-29.md §5, UND-001..020) in /insurer and /admin:
 * case tabs, dashboard / assigned cases / performance, information requests (inspection / medical), own carrier only,
 * medical content only for documents.medical.read. Custom roles carry exactly the listed permissions.
 */
uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function uwbUser(string $tenantId, array $permissions, ?string $carrierId, string $roleCode = 'UNDERWRITER'): User
{
    $u = User::create(['full_name' => 'UWB '.$roleCode.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $roleCode, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'UWB-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function uwbAs(User $u, string $tenantId, string $panel = 'insurer'): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel($panel));
}

function uwbPolicy(string $tenantId, string $proposalId, string $carrierId, string $partyId, string $number): Policy
{
    return Policy::create(['tenant_id' => $tenantId, 'proposal_id' => $proposalId, 'carrier_id' => $carrierId, 'party_id' => $partyId, 'policy_number' => $number,
        'status' => 'ACTIVE', 'coverage_starts_at' => now()->subYear(), 'coverage_ends_at' => now()->addMonth(), 'terms_snapshot' => [], 'version' => 1,
        'currency' => 'XAF', 'premium_minor' => 250000, 'issued_at' => now()->subYear()]);
}

const UWB_UNDERWRITER = ['carrier.referrals.read', 'underwriting.decide', 'policies.read'];

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    $this->carrier = $this->f['carrier']->id;
    $this->other = makeMobileFinanceProposalChain($this->f['tenant']);
    $this->f['proposal']->update(['status' => 'UNDER_REVIEW', 'disclosures' => ['vehicle_use' => 'PRIVATE', 'medical_condition' => 'Diabetes type 2'],
        'information_request' => ['id' => (string) Str::uuid(), 'requested_at' => now()->toIso8601String(), 'message' => 'Please provide', 'items' => [
            ['code' => 'VEHICLE_INSPECTION', 'kind' => 'INSPECTION', 'description' => 'Pre-cover vehicle inspection report', 'mandatory' => true],
            ['code' => 'MEDICAL_REPORT', 'kind' => 'MEDICAL', 'description' => 'Cardiology report dated this year', 'mandatory' => true],
        ]]]);
    $this->other['proposal']->update(['status' => 'UNDER_REVIEW']);
    $this->uw = uwbUser($this->tenant, UWB_UNDERWRITER, $this->carrier);
    $this->mine = UnderwritingCase::create(['tenant_id' => $this->tenant, 'proposal_id' => $this->f['proposal']->id, 'carrier_id' => $this->carrier, 'status' => 'IN_REVIEW',
        'assigned_to' => $this->uw->id, 'priority' => 'HIGH', 'decision_due_at' => now()->subDay(), 'recommendation' => 'REFER', 'risk_score' => 45, 'risk_band' => 'MEDIUM',
        'risk_factors' => [['factor' => 'YOUNG_DRIVER', 'source' => 'rule:R1', 'weight' => 30, 'input' => ['age' => 21], 'fired' => true, 'contribution' => 30]], 'evaluated_at' => now()]);
    $this->foreign = UnderwritingCase::create(['tenant_id' => $this->tenant, 'proposal_id' => $this->other['proposal']->id, 'carrier_id' => $this->other['carrier']->id, 'status' => 'IN_REVIEW', 'assigned_to' => $this->uw->id]);
    UnderwritingReferralTask::create(['underwriting_case_id' => $this->mine->id, 'reason_code' => 'HIGH_VALUE_RISK', 'status' => 'OPEN', 'severity' => 'HIGH']);
    // Applicant history: one policy + claim with the own carrier, one policy with another insurer (hidden in /insurer).
    $own = uwbPolicy($this->tenant, $this->f['proposal']->id, $this->carrier, $this->f['party']->id, 'POL-UWB-OWN');
    uwbPolicy($this->tenant, $this->other['proposal']->id, $this->other['carrier']->id, $this->f['party']->id, 'POL-UWB-FOREIGN');
    Claim::create(['tenant_id' => $this->tenant, 'policy_id' => $own->id, 'claimant_party_id' => $this->f['party']->id, 'claim_number' => 'CLM-UWB-1', 'status' => 'CLOSED',
        'loss_occurred_at' => now()->subMonths(3), 'loss_details' => ['description' => 'Rear collision'], 'estimated_loss_minor' => 500000, 'currency' => 'XAF', 'approved_amount_minor' => 400000, 'version' => 1]);
});

it('renders every workbench tab on the own carrier case in /insurer and refuses another insurer case', function () {
    $this->actingAs($this->uw)->get(UnderwritingCaseResource::getUrl('view', ['record' => $this->mine->id], panel: 'insurer'))->assertOk()
        ->assertSee(__('uw_workbench.tabs.profile'))->assertSee(__('uw_workbench.tabs.policy_history'))->assertSee(__('uw_workbench.tabs.claims_history'))
        ->assertSee(__('uw_workbench.tabs.questionnaire'))->assertSee(__('uw_workbench.tabs.documents'))->assertSee(__('uw_workbench.tabs.risk_assessment'))
        ->assertSee(__('uw_workbench.tabs.risk_score'))->assertSee(__('uw_workbench.tabs.coverage'))->assertSee(__('uw_workbench.tabs.pricing'))
        ->assertSee(__('uw_workbench.tabs.information_request'))->assertSee(__('uw_workbench.tabs.requirements'))->assertSee(__('uw_workbench.tabs.supervisor'))
        ->assertSee('POL-UWB-OWN')->assertDontSee('POL-UWB-FOREIGN')->assertSee('CLM-UWB-1')->assertSee('YOUNG_DRIVER')->assertSee('HIGH_VALUE_RISK')
        ->assertSee('VEHICLE_INSPECTION');
    $this->actingAs($this->uw)->get(UnderwritingCaseResource::getUrl('view', ['record' => $this->foreign->id], panel: 'insurer'))->assertNotFound();
});

it('withholds medical content from roles without documents.medical.read and shows it to those with it', function () {
    $this->actingAs($this->uw)->get(UnderwritingCaseResource::getUrl('view', ['record' => $this->mine->id], panel: 'insurer'))->assertOk()
        ->assertDontSee('Cardiology report dated this year')->assertDontSee('Diabetes type 2')->assertSee(__('uw_workbench.medical_withheld'));

    $senior = uwbUser($this->tenant, [...UWB_UNDERWRITER, 'documents.medical.read'], $this->carrier);
    uwbAs($senior, $this->tenant);
    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->mine->id])->assertOk()
        ->assertSee('Cardiology report dated this year')->assertSee('Diabetes type 2');
});

it('shows the underwriting dashboard with KPIs, own assigned cases and performance in /insurer', function () {
    UnderwritingDecision::create(['underwriting_case_id' => $this->mine->id, 'decision' => 'APPROVED', 'outcome' => 'APPROVED', 'reason_code' => 'STANDARD', 'notes' => 'ok',
        'decided_by' => $this->uw->id, 'decided_at' => now(), 'system_recommendation' => 'APPROVE']);
    $this->actingAs($this->uw)->get(UnderwritingDashboard::getUrl(panel: 'insurer'))->assertOk()
        ->assertSee(__('uw_workbench.kpi.open'))->assertSee(__('uw_workbench.assigned_cases'))->assertSee($this->f['proposal']->proposal_number)
        ->assertDontSee($this->other['proposal']->proposal_number)->assertSee(__('uw_workbench.perf.by_underwriter'))->assertSee($this->uw->full_name);

    // No case-read permission: no dashboard.
    uwbAs(uwbUser($this->tenant, ['policies.read'], $this->carrier, 'CARRIER_STAFF'), $this->tenant);
    expect(UnderwritingDashboard::canAccess())->toBeFalse();
});

it('lists my assigned cases (own carrier) on the assigned-to-me tab', function () {
    uwbAs($this->uw, $this->tenant);
    Livewire::test(ListUnderwritingCases::class, ['activeTab' => 'mine'])->assertCanSeeTableRecords([$this->mine])->assertCanNotSeeTableRecords([$this->foreign]);
    Livewire::test(ListUnderwritingCases::class, ['activeTab' => 'overdue'])->assertCanSeeTableRecords([$this->mine]);
});

it('requests inspection information through UnderwritingService; medical items need documents.medical.read; read-only users cannot', function () {
    uwbAs($this->uw, $this->tenant);
    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->mine->id])
        ->callAction('uwRequestInformation', ['items' => [['kind' => 'MEDICAL', 'code' => 'MEDICAL_EXAM', 'description' => 'Full medical examination', 'mandatory' => true]]])
        ->assertHasActionErrors();
    expect($this->mine->refresh()->status)->toBe('IN_REVIEW');

    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->mine->id])
        ->callAction('uwRequestInformation', ['items' => [['kind' => 'INSPECTION', 'code' => 'SITE_VISIT', 'description' => 'Physical vehicle inspection', 'mandatory' => true]], 'message' => 'Inspection needed'])
        ->assertNotified(__('uw_workbench.uwRequestInformation.done'));
    expect($this->mine->refresh()->status)->toBe('AWAITING_INFORMATION')
        ->and(collect($this->f['proposal']->refresh()->information_request['items'])->pluck('code')->all())->toBe(['SITE_VISIT']);

    uwbAs(uwbUser($this->tenant, ['carrier.referrals.read'], $this->carrier, 'CARRIER_STAFF'), $this->tenant);
    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->mine->id])->assertOk()->assertActionHidden('uwRequestInformation');
});

it('keeps the workbench working in /admin (tenant-wide, no carrier narrowing)', function () {
    $admin = uwbUser($this->tenant, [...UWB_UNDERWRITER, 'documents.medical.read'], null, 'TENANT_ADMIN');
    uwbAs($admin, $this->tenant, 'admin');
    Livewire::test(ViewUnderwritingCase::class, ['record' => $this->foreign->id])->assertOk()->assertSee(__('uw_workbench.tabs.supervisor'));
    Livewire::test(ListUnderwritingCases::class)->assertCanSeeTableRecords([$this->mine, $this->foreign]);
    Livewire::test(UnderwritingDashboard::class)->assertOk()->assertSee(__('uw_workbench.kpi.open'));
});
