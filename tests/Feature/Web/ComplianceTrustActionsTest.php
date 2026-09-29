<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\GovernanceRegisters;
use App\Filament\Admin\Resources\ComplianceCases\ComplianceCaseResource;
use App\Filament\Admin\Resources\ComplianceCases\Pages\ListComplianceCases;
use App\Filament\Admin\Resources\ComplianceCases\Pages\ViewComplianceCase;
use App\Filament\Admin\Resources\DataSubjectRequests\DataSubjectRequestResource;
use App\Filament\Admin\Resources\DataSubjectRequests\Pages\ListDataSubjectRequests;
use App\Filament\Admin\Resources\PrivilegedAccess\Pages\ListPrivilegedAccessGrants;
use App\Filament\Admin\Resources\PrivilegedAccess\PrivilegedAccessGrantResource;
use App\Filament\Admin\Resources\RegulatoryReports\Pages\ListRegulatoryReports;
use App\Filament\Admin\Resources\RegulatoryReports\Pages\ViewRegulatoryReport;
use App\Filament\Admin\Resources\RegulatoryReports\RegulatoryReportRunResource;
use App\Models\ComplianceCase;
use App\Models\Party;
use App\Models\RegulatoryReportDefinition;
use App\Models\RegulatoryReportRun;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Compliance and trust desktop actions: pages render with the permissions, are refused without, and the actions change state through the services. */
const CTA_PERMISSIONS = [
    'compliance.cases.read', 'compliance.cases.create', 'compliance.evidence.link', 'compliance.findings.manage', 'compliance.actions.manage', 'compliance.actions.verify',
    'compliance.dsr.receive', 'compliance.access.grant', 'compliance.governance.read', 'compliance.governance.manage', 'compliance.governance.approve',
    'trust.fraud-alerts.create', 'fraud.alert.create' /* opens the risk alerts list, where "Raise fraud alert" lives */, 'trust.regulatory-reports.prepare', 'trust.regulatory-reports.acknowledge', 'trust.regulatory-reports.submit',
];

function ctaUser(string $tenantId, array $permissions, string $role = 'COMPLIANCE_ADMIN'): User
{
    $u = User::create(['full_name' => 'CTA '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'CTA-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function ctaAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

beforeEach(function () {
    $this->tenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'CTA '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []])->id;
    $this->user = ctaUser($this->tenant, CTA_PERMISSIONS);
    ctaAs($this->user, $this->tenant);
});

it('renders the compliance pages for a role with the permissions', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    foreach ([ComplianceCaseResource::getUrl('index'), DataSubjectRequestResource::getUrl('index'), PrivilegedAccessGrantResource::getUrl('index'),
        RegulatoryReportRunResource::getUrl('index'), GovernanceRegisters::getUrl()] as $url) {
        $this->get($url)->assertOk();
    }
    ctaAs($this->user, $this->tenant); // the HTTP requests leave the tenant context cleared
    Livewire::test(ListComplianceCases::class)->assertActionVisible('caseOpen');
    Livewire::test(\App\Filament\Admin\Resources\RiskAlerts\Pages\ListRiskAlerts::class)->assertActionVisible('fraudAlert');
    Livewire::test(ListDataSubjectRequests::class)->assertActionVisible('dsrReceive');
    Livewire::test(ListPrivilegedAccessGrants::class)->assertActionVisible('accessRequest');
    Livewire::test(ListRegulatoryReports::class)->assertActionVisible('reportPrepare');
    Livewire::test(GovernanceRegisters::class)->assertActionVisible('governanceCreate');
});

it('refuses the pages and hides the actions without the permissions', function () {
    $none = ctaUser($this->tenant, ['claims.view'], 'OPERATIONS_OFFICER');
    ctaAs($none, $this->tenant);
    $this->get(ComplianceCaseResource::getUrl('index'))->assertForbidden();
    $this->get(DataSubjectRequestResource::getUrl('index'))->assertForbidden();
    $this->get(GovernanceRegisters::getUrl())->assertForbidden();

    // Read access only (also re-sets the tenant context the HTTP requests cleared): the pages render but no write action is offered.
    ctaAs(ctaUser($this->tenant, ['compliance.cases.read', 'compliance.dsr.receive', 'compliance.governance.read']), $this->tenant);
    Livewire::test(ListComplianceCases::class)->assertActionHidden('caseOpen')->assertActionDoesNotExist('fraudAlert');
    Livewire::test(GovernanceRegisters::class)->assertActionHidden('governanceCreate');
    Livewire::test(ListPrivilegedAccessGrants::class)->assertActionHidden('accessRequest');
    Livewire::test(ListRegulatoryReports::class)->assertActionHidden('reportPrepare');
});

it('opens a case, adds a finding, plans and completes a corrective action through ComplianceCaseService', function () {
    Livewire::test(ListComplianceCases::class)->callAction('caseOpen', [
        'type' => 'AML_REVIEW', 'subject_type' => 'PARTY', 'subject_id' => (string) Str::uuid(), 'severity' => 'HIGH',
    ])->assertHasNoActionErrors()->assertNotified(__('compliance_actions.caseOpen.done'));
    $case = ComplianceCase::where('tenant_id', $this->tenant)->firstOrFail();
    expect($case->status)->toBe('OPEN');

    Livewire::test(ViewComplianceCase::class, ['record' => $case->id])
        ->callAction('caseAddFinding', ['title' => 'Missing source of funds', 'severity' => 'MEDIUM'])->assertNotified(__('compliance_actions.caseAddFinding.done'));
    $finding = DB::table('compliance_findings')->where('compliance_case_id', $case->id)->first();
    expect($finding->status)->toBe('OPEN');

    Livewire::test(ViewComplianceCase::class, ['record' => $case->id])->callAction('findingPlanAction', [
        'finding_id' => $finding->id, 'description' => 'Collect source of funds declaration', 'owner_user_id' => $this->user->id, 'due_on' => now()->addWeek()->toDateString(),
    ])->assertNotified(__('compliance_actions.findingPlanAction.done'));
    $action = DB::table('compliance_corrective_actions')->where('finding_id', $finding->id)->first();
    expect($action->status)->toBe('PLANNED')
        ->and(DB::table('compliance_findings')->where('id', $finding->id)->value('status'))->toBe('REMEDIATING');

    Livewire::test(ViewComplianceCase::class, ['record' => $case->id])
        ->callAction('actionEvent', ['corrective_action_id' => $action->id, 'event' => 'complete', 'notes' => 'Declaration on file'])->assertNotified(__('compliance_actions.actionEvent.done'));
    expect(DB::table('compliance_corrective_actions')->where('id', $action->id)->value('status'))->toBe('COMPLETED');

    // Maker-checker: the completer cannot verify; a different verifier can.
    Livewire::test(ViewComplianceCase::class, ['record' => $case->id])
        ->callAction('actionVerify', ['corrective_action_id' => $action->id, 'accepted' => true, 'notes' => 'Checked'])->assertNotified(__('workflow_actions.failed'));
    ctaAs(ctaUser($this->tenant, CTA_PERMISSIONS), $this->tenant);
    Livewire::test(ViewComplianceCase::class, ['record' => $case->id])
        ->callAction('actionVerify', ['corrective_action_id' => $action->id, 'accepted' => true, 'notes' => 'Checked'])->assertNotified(__('compliance_actions.actionVerify.done'));
    expect(DB::table('compliance_corrective_actions')->where('id', $action->id)->value('status'))->toBe('VERIFIED');

    Livewire::test(ViewComplianceCase::class, ['record' => $case->id])
        ->callAction('caseLinkEvidence', ['description' => 'Bank letter', 'external_reference' => 'EXT-42'])->assertNotified(__('compliance_actions.caseLinkEvidence.done'));
    expect(DB::table('compliance_evidence_links')->where('compliance_case_id', $case->id)->count())->toBe(1);

    // The detail page lists the findings and corrective actions.
    $this->get(ComplianceCaseResource::getUrl('view', ['record' => $case]))->assertOk()->assertSee('Missing source of funds')->assertSee('Collect source of funds declaration');
});

it('records a data subject request through DataSubjectRequestService', function () {
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Data Subject', 'status' => 'ACTIVE']);
    Livewire::test(ListDataSubjectRequests::class)->callAction('dsrReceive', ['party_id' => $party->id, 'type' => 'ACCESS', 'due_on' => now()->addDays(30)->toDateString()])
        ->assertNotified(__('compliance_actions.dsrReceive.done'));
    expect(DB::table('data_subject_requests')->where(['tenant_id' => $this->tenant, 'party_id' => $party->id, 'status' => 'RECEIVED'])->count())->toBe(1);
});

it('prepares a regulatory report run and records acknowledgement', function () {
    $definition = RegulatoryReportDefinition::create([
        'code' => 'CIMA-'.Str::random(6), 'version' => 1, 'status' => 'ACTIVE', 'jurisdiction' => 'CM', 'report_type' => 'SOLVENCY',
        'schema' => ['fields' => []], 'schema_hash' => hash('sha256', '{}'), 'effective_from' => now()->subDay(), 'created_by' => $this->user->id,
    ]);
    Livewire::test(ListRegulatoryReports::class)->callAction('reportPrepare', ['definition_id' => $definition->id, 'period_key' => '2026-Q3', 'payload' => ['premiums' => '1000']])
        ->assertNotified(__('compliance_actions.reportPrepare.done'));
    $run = RegulatoryReportRun::where('tenant_id', $this->tenant)->firstOrFail();
    expect($run->status)->toBe('DRAFT');

    $run->update(['status' => 'SUBMITTING']);
    Livewire::test(ViewRegulatoryReport::class, ['record' => $run->id])->callAction('reportAcknowledge', ['external_reference' => 'CIMA-ACK-1'])
        ->assertNotified(__('compliance_actions.reportAcknowledge.done'));
    expect($run->refresh()->status)->toBe('ACKNOWLEDGED');
});

it('requests privileged access and raises a manual fraud alert', function () {
    $target = ctaUser($this->tenant, []);
    Livewire::test(ListPrivilegedAccessGrants::class)->callAction('accessRequest', [
        'user_id' => $target->id, 'purpose' => 'INCIDENT', 'justification' => 'Investigate the reported data incident on claims.',
        'starts_at' => now()->addHour()->toDateTimeString(), 'expires_at' => now()->addHours(5)->toDateTimeString(), 'scope' => ['claims.read'],
    ])->assertNotified(__('compliance_actions.accessRequest.done'));
    expect(DB::table('privileged_access_grants')->where(['tenant_id' => $this->tenant, 'user_id' => $target->id, 'status' => 'REQUESTED'])->count())->toBe(1);

    Livewire::test(\App\Filament\Admin\Resources\RiskAlerts\Pages\ListRiskAlerts::class)->callAction('fraudAlert', [
        'subject_type' => 'CLAIM', 'subject_id' => (string) Str::uuid(), 'alert_type' => 'DUPLICATE_INVOICE', 'risk_score' => 70, 'signals' => ['invoice' => 'duplicate'],
    ])->assertNotified(__('compliance_actions.fraudAlert.done'));
    expect(DB::table('risk_alerts')->where(['tenant_id' => $this->tenant, 'severity' => 'HIGH', 'status' => 'OPEN'])->count())->toBe(1);
});

it('adds, edits governance register entries and approves an exit plan with a different approver', function () {
    Livewire::test(GovernanceRegisters::class)->callAction('governanceCreate', ['vendor_code' => 'V-1', 'name' => 'Cloud host', 'is_outsourcing' => true])
        ->assertNotified(__('compliance_actions.governanceCreate.done'));
    $vendor = DB::table('governance_vendors')->where('tenant_id', $this->tenant)->first();
    expect($vendor->name)->toBe('Cloud host');

    Livewire::test(GovernanceRegisters::class)->callTableAction('governanceUpdate', $vendor->id, ['name' => 'Cloud host SA'])->assertNotified(__('compliance_actions.governanceUpdate.done'));
    expect(DB::table('governance_vendors')->where('id', $vendor->id)->value('name'))->toBe('Cloud host SA');

    Livewire::withQueryParams(['register' => 'outsourcing-contracts'])->test(GovernanceRegisters::class)
        ->callAction('governanceCreate', ['vendor_id' => $vendor->id, 'contract_reference' => 'C-9', 'service_description' => 'Hosting', 'start_on' => '2026-01-01']);
    $contract = DB::table('governance_outsourcing_contracts')->where('tenant_id', $this->tenant)->first();
    Livewire::withQueryParams(['register' => 'exit-plans'])->test(GovernanceRegisters::class)
        ->callAction('governanceCreate', ['contract_id' => $contract->id, 'summary' => 'Migrate to secondary host within 90 days.']);
    $plan = DB::table('governance_exit_plans')->where('tenant_id', $this->tenant)->first();
    expect($plan->status)->toBe('DRAFT');

    ctaAs(ctaUser($this->tenant, CTA_PERMISSIONS), $this->tenant);
    Livewire::withQueryParams(['register' => 'exit-plans'])->test(GovernanceRegisters::class)
        ->callTableAction('exitPlanApprove', $plan->id)->assertNotified(__('compliance_actions.exitPlanApprove.done'));
    expect(DB::table('governance_exit_plans')->where('id', $plan->id)->value('status'))->toBe('APPROVED');
});
