<?php

declare(strict_types=1);

/**
 * UI coverage batches 22-23: regulatory returns/rules/inspections, insurer onboarding + carrier-broker agreements,
 * CRM leads, rule sets and collections on the staff desktop. Every action calls the SAME service method with the SAME
 * permission as the API route (see the action classes' docblocks); maker-checker stays inside the services.
 */

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Rules\Models\RuleSet;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\RegulatoryCrm\Collections;
use App\Filament\Admin\Pages\RegulatoryCrm\CrmLeads;
use App\Filament\Admin\Pages\RegulatoryCrm\InsurerOnboarding;
use App\Filament\Admin\Pages\RegulatoryCrm\RegulatoryInspections;
use App\Filament\Admin\Pages\RegulatoryCrm\RegulatoryReturnDefinitions;
use App\Filament\Admin\Pages\RegulatoryCrm\RegulatoryRules;
use App\Filament\Admin\Pages\RegulatoryCrm\RuleSets;
use App\Filament\Admin\Resources\CarrierBrokerAgreements\Pages\ListCarrierBrokerAgreements;
use App\Filament\Admin\Resources\CarrierBrokerAgreements\Pages\ViewCarrierBrokerAgreement;
use App\Models\Carrier;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function rcUser(Tenant $t, array $permissions, string $code = 'RC'): User
{
    $u = User::create(['full_name' => 'RC '.$code.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    // COMPLIANCE_ADMIN membership opens the admin panel; what the user may do comes only from the role's permissions.
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'COMPLIANCE_ADMIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => $code.'-'.Str::random(6), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function rcAs(User $u, Tenant $t): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($t->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function rcCarrier(): Carrier
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'RC Assurances '.Str::random(4), 'status' => 'ACTIVE']);

    return Carrier::create(['party_id' => $party->id, 'cima_code' => 'RC-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => []]);
}

function rcPartner(Tenant $t): Partner
{
    return Partner::create(['tenant_id' => $t->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'RC Broker '.Str::random(4), 'status' => 'ACTIVE'])->id,
        'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => []]);
}

beforeEach(function () {
    $this->t = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'RC Tenant '.Str::random(6), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    app(TenantContext::class)->set($this->t->id);
});

it('opens each screen only with its GET permission and hides every action without the action permission', function () {
    rcAs(rcUser($this->t, ['claims.view'], 'NONE'), $this->t);
    foreach ([RegulatoryReturnDefinitions::class, RegulatoryRules::class, RegulatoryInspections::class, InsurerOnboarding::class, CrmLeads::class, RuleSets::class, Collections::class] as $page) {
        expect($page::canAccess())->toBeFalse($page);
    }
    Livewire::test(RuleSets::class)->assertForbidden();

    $viewer = rcUser($this->t, ['regulatory.returns.view', 'regulatory.rules.view', 'regulatory.inspections.view', 'carrier_setup.view', 'crm.leads.read', 'rules.view', 'collections.view', 'distribution.agreements.view'], 'VIEW');
    rcAs($viewer, $this->t);
    Livewire::test(RegulatoryReturnDefinitions::class)->assertOk()->assertActionHidden(TestAction::make('returnDefine')->table());
    Livewire::test(RegulatoryRules::class)->assertOk()->assertActionHidden(TestAction::make('ruleDraft')->table());
    Livewire::test(RegulatoryInspections::class)->assertOk()->assertActionHidden(TestAction::make('inspectionOpen')->table());
    Livewire::test(CrmLeads::class)->assertOk()->assertActionHidden(TestAction::make('leadCreate')->table())->assertActionHidden(TestAction::make('portfolioTransfer')->table());
    Livewire::test(RuleSets::class)->assertOk()->assertActionHidden(TestAction::make('ruleSetCreate')->table())->assertActionHidden(TestAction::make('ruleSetValidate')->table());
    Livewire::test(Collections::class)->assertOk()->assertActionHidden(TestAction::make('collectionsRun')->table());
    Livewire::test(ListCarrierBrokerAgreements::class)->assertOk()->assertActionHidden('agreementCreate');
    $carrier = rcCarrier();
    Livewire::test(InsurerOnboarding::class)->assertOk()->assertActionHidden(TestAction::make('setupOpen')->table($carrier->id))
        ->assertActionHidden(TestAction::make('signingKeyRegister')->table($carrier->id))->assertActionHidden(TestAction::make('capabilityDraft')->table($carrier->id));

    rcAs(rcUser($this->t, ['rules.view', 'rules.manage', 'collections.view', 'collections.manage'], 'MAKER'), $this->t);
    Livewire::test(RuleSets::class)->assertActionVisible(TestAction::make('ruleSetCreate')->table());
    Livewire::test(Collections::class)->assertActionVisible(TestAction::make('collectionsRun')->table());
});

it('runs a regulatory change rule draft → review → approve → activate with separation of duties; refusals are shown', function () {
    $perms = ['regulatory.rules.view', 'regulatory.rules.draft', 'regulatory.rules.review', 'regulatory.rules.approve'];
    [$author, $reviewer, $approver] = [rcUser($this->t, $perms, 'A'), rcUser($this->t, $perms, 'R'), rcUser($this->t, $perms, 'P')];

    rcAs($author, $this->t);
    Livewire::test(RegulatoryRules::class)->callAction(TestAction::make('ruleDraft')->table(), ['code' => 'AUTO_MIN_LIMIT', 'title' => 'Motor minimum limit', 'rule_type' => 'LIMIT',
        'scope' => '{"line_codes":["AUTO"]}', 'content' => '{"configured":true}', 'effective_from' => '2025-01-01'])->assertNotified(__('regulatory_crm_actions.ruleDraft.done'));
    $rule = DB::table('regulatory_rules')->where('code', 'AUTO_MIN_LIMIT')->first();
    expect($rule->status)->toBe('DRAFT');
    // The author cannot review their own rule: RegulatoryRuleService refuses and the failure is shown.
    Livewire::test(RegulatoryRules::class)->callAction(TestAction::make('ruleReview')->table($rule->id), ['notes' => 'self'])->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('regulatory_rules')->where('id', $rule->id)->value('status'))->toBe('DRAFT');

    rcAs($reviewer, $this->t);
    Livewire::test(RegulatoryRules::class)->callAction(TestAction::make('ruleReview')->table($rule->id), ['notes' => 'ok'])->assertNotified(__('regulatory_crm_actions.ruleReview.done'));
    expect(DB::table('regulatory_rules')->where('id', $rule->id)->value('status'))->toBe('REVIEWED');

    rcAs($approver, $this->t);
    Livewire::test(RegulatoryRules::class)->callAction(TestAction::make('ruleApprove')->table($rule->id))->assertNotified(__('regulatory_crm_actions.ruleApprove.done'));
    expect(DB::table('regulatory_rules')->where('id', $rule->id)->value('status'))->toBe('APPROVED');
    Livewire::test(RegulatoryRules::class)->callAction(TestAction::make('ruleActivate')->table($rule->id))->assertNotified(__('regulatory_crm_actions.ruleActivate.done'));
    expect(DB::table('regulatory_rules')->where('id', $rule->id)->value('status'))->toBe('EFFECTIVE');
});

it('defines, approves (four eyes) and generates a regulatory return', function () {
    $perms = ['regulatory.returns.view', 'regulatory.returns.define', 'regulatory.returns.approve', 'trust.regulatory-reports.prepare'];
    [$maker, $checker] = [rcUser($this->t, $perms, 'M'), rcUser($this->t, $perms, 'C')];
    $schema = json_encode(['dataset' => 'policies', 'group_by' => ['line_code'], 'columns' => [['key' => 'line', 'field' => 'line_code'], ['key' => 'n', 'aggregate' => 'count']]]);

    rcAs($maker, $this->t);
    Livewire::test(RegulatoryReturnDefinitions::class)->callAction(TestAction::make('returnDefine')->table(), ['code' => 'X', 'version' => 1, 'jurisdiction' => 'CM', 'report_type' => 'T',
        'schema' => '{"dataset":"users","columns":[{"key":"a","field":"id"}]}', 'effective_from' => '2025-01-01'])->assertNotified(__('workflow_actions.failed'));
    Livewire::test(RegulatoryReturnDefinitions::class)->callAction(TestAction::make('returnDefine')->table(), ['code' => 'PREMIUM_BY_LINE', 'version' => 1, 'jurisdiction' => 'CM',
        'report_type' => 'PREMIUM_SUMMARY', 'schema' => $schema, 'effective_from' => '2025-01-01'])->assertNotified(__('regulatory_crm_actions.returnDefine.done'));
    $def = DB::table('regulatory_report_definitions')->where('code', 'PREMIUM_BY_LINE')->first();
    expect($def->status)->toBe('DRAFT')->and(DB::table('regulatory_report_definitions')->count())->toBe(1);
    Livewire::test(RegulatoryReturnDefinitions::class)->callAction(TestAction::make('returnApprove')->table($def->id))->assertNotified(__('workflow_actions.failed'));

    rcAs($checker, $this->t);
    Livewire::test(RegulatoryReturnDefinitions::class)->callAction(TestAction::make('returnApprove')->table($def->id))->assertNotified(__('regulatory_crm_actions.returnApprove.done'));
    expect(DB::table('regulatory_report_definitions')->where('id', $def->id)->value('status'))->toBe('ACTIVE');

    rcAs($maker, $this->t);
    Livewire::test(RegulatoryReturnDefinitions::class)->callAction(TestAction::make('returnGenerate')->table($def->id), ['period_key' => '2025-Q1', 'period_from' => '2025-01-01', 'period_to' => '2025-03-31'])
        ->assertNotified(__('regulatory_crm_actions.returnGenerate.done'));
    $run = DB::table('regulatory_report_runs')->where(['tenant_id' => $this->t->id, 'definition_id' => $def->id])->first();
    expect($run->status)->toBe('DRAFT')->and($run->source_version)->toStartWith('PREMIUM_BY_LINE@v1#');
});

it('opens, approves (four eyes) and closes an inspection workspace', function () {
    $perms = ['regulatory.inspections.view', 'regulatory.inspections.manage', 'regulatory.inspections.approve'];
    [$requester, $approver] = [rcUser($this->t, $perms, 'Q'), rcUser($this->t, $perms, 'P')];
    $inspector = rcUser($this->t, ['regulatory.inspections.access'], 'I');

    rcAs($requester, $this->t);
    Livewire::test(RegulatoryInspections::class)->callAction(TestAction::make('inspectionOpen')->table(), ['inspector_user_id' => $inspector->id, 'authority' => 'Regulator', 'reference' => 'INS-1',
        'justification' => 'On-site inspection', 'resources' => ['policies', 'audit'], 'starts_at' => now()->subMinute()->toDateTimeString(), 'expires_at' => now()->addDay()->toDateTimeString()])
        ->assertNotified(__('regulatory_crm_actions.inspectionOpen.done'));
    $ins = DB::table('regulatory_inspections')->where('tenant_id', $this->t->id)->first();
    expect($ins->status)->toBe('REQUESTED');
    Livewire::test(RegulatoryInspections::class)->callAction(TestAction::make('inspectionApprove')->table($ins->id))->assertNotified(__('workflow_actions.failed'));

    rcAs($approver, $this->t);
    Livewire::test(RegulatoryInspections::class)->callAction(TestAction::make('inspectionApprove')->table($ins->id))->assertNotified(__('regulatory_crm_actions.inspectionApprove.done'));
    expect(DB::table('regulatory_inspections')->where('id', $ins->id)->value('status'))->toBe('ACTIVE');
    Livewire::test(RegulatoryInspections::class)->callAction(TestAction::make('inspectionClose')->table($ins->id), ['reason' => 'Inspection finished'])
        ->assertNotified(__('regulatory_crm_actions.inspectionClose.done'));
    expect(DB::table('regulatory_inspections')->where('id', $ins->id)->value('status'))->toBe('CLOSED');
});

it('authors, submits, approves (four eyes), simulates and retires a rule set', function () {
    $maker = rcUser($this->t, ['rules.view', 'rules.manage'], 'RM');
    $checker = rcUser($this->t, ['rules.view', 'rules.approve'], 'RC');
    $rules = json_encode([['code' => 'VEHICLE_TOO_OLD', 'priority' => 10, 'stop_processing' => true,
        'condition' => ['op' => 'GT', 'left' => ['fact' => 'vehicle_age'], 'right' => ['value' => 25]],
        'outcome' => ['result' => 'INELIGIBLE', 'reason_code' => 'VEHICLE_TOO_OLD', 'message_key' => 'elig.vehicle_too_old']]]);

    rcAs($maker, $this->t);
    Livewire::test(RuleSets::class)->callAction(TestAction::make('ruleSetValidate')->table(), ['condition' => '{"op":"GT","left":{"fact":"a"},"right":{"value":1}}'])
        ->assertNotified(__('regulatory_crm_actions.ruleSetValidate.valid'));
    Livewire::test(RuleSets::class)->callAction(TestAction::make('ruleSetCreate')->table(), ['code' => 'MOTOR_ELIG_UI', 'domain' => 'ELIGIBILITY', 'line_code' => 'MOTOR',
        'effective_from' => '2026-01-01', 'rules' => $rules])->assertNotified(__('regulatory_crm_actions.ruleSetCreate.done'));
    $set = RuleSet::where('code', 'MOTOR_ELIG_UI')->firstOrFail();
    expect($set->status)->toBe('DRAFT');
    Livewire::test(RuleSets::class)->assertActionHidden(TestAction::make('ruleSetApprove')->table($set->id))
        ->callAction(TestAction::make('ruleSetSubmit')->table($set->id))->assertNotified(__('regulatory_crm_actions.ruleSetSubmit.done'));
    expect($set->fresh()->status)->toBe('IN_REVIEW');

    rcAs($checker, $this->t);
    Livewire::test(RuleSets::class)->callAction(TestAction::make('ruleSetApprove')->table($set->id), ['note' => 'Reviewed thresholds.'])->assertNotified(__('regulatory_crm_actions.ruleSetApprove.done'));
    expect($set->fresh()->status)->toBe('APPROVED');
    Livewire::test(RuleSets::class)->callAction(TestAction::make('ruleSetSimulate')->table($set->id), ['facts' => '{"vehicle_age":30}'])
        ->assertNotified(__('regulatory_crm_actions.ruleSetSimulate.done'));
    Livewire::test(RuleSets::class)->callAction(TestAction::make('ruleSetRetire')->table($set->id), ['reason' => 'Superseded by tariff review'])
        ->assertNotified(__('regulatory_crm_actions.ruleSetRetire.done'));
    expect($set->fresh()->status)->toBe('RETIRED');
});

it('runs collections, records a promise, escalates and writes off with maker-checker', function () {
    $maker = rcUser($this->t, ['collections.view', 'collections.manage'], 'CM');
    $checker = rcUser($this->t, ['collections.view', 'collections.write_off.approve'], 'CC');
    $mk = fn (int $amount, int $daysOverdue) => app(ObligationService::class)->create(['tenant_id' => $this->t->id, 'kind' => 'RECEIVABLE', 'type' => 'PREMIUM', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(), 'currency' => 'XAF', 'amount_minor' => $amount, 'due_at' => now()->subDays($daysOverdue)->toDateString()]);
    $a = $mk(100000, 3);
    $b = $mk(5000, 20);

    rcAs($maker, $this->t);
    Livewire::test(Collections::class)->callAction(TestAction::make('collectionsRun')->table())->assertNotified(__('regulatory_crm_actions.collectionsRun.done'));
    expect(DB::table('collection_accounts')->where('financial_obligation_id', $a->id)->value('stage'))->toBe('REMINDER_1');

    Livewire::test(Collections::class)->assertCanSeeTableRecords([$a->id, $b->id])
        ->callAction(TestAction::make('collectionPromise')->table($a->id), ['amount_minor' => 50000, 'promised_for' => now()->addDays(5)->toDateString(), 'notes' => 'Will pay'])
        ->assertNotified(__('regulatory_crm_actions.collectionPromise.done'));
    expect(DB::table('collection_promises')->where('status', 'PENDING')->count())->toBe(1);
    Livewire::test(Collections::class)->callAction(TestAction::make('collectionEscalate')->table($a->id), ['reason' => 'No contact'])
        ->assertNotified(__('regulatory_crm_actions.collectionEscalate.done'));
    expect(DB::table('collection_accounts')->where('financial_obligation_id', $a->id)->value('case_id'))->not->toBeNull();

    Livewire::test(Collections::class)->callAction(TestAction::make('writeOffRequest')->table($b->id), ['reason' => 'Debtor insolvent'])
        ->assertNotified(__('regulatory_crm_actions.writeOffRequest.done'))
        ->assertActionHidden(TestAction::make('writeOffApprove')->table($b->id));

    rcAs($checker, $this->t);
    Livewire::test(Collections::class)->callAction(TestAction::make('writeOffApprove')->table($b->id), ['note' => 'OK'])->assertNotified(__('regulatory_crm_actions.writeOffApprove.done'));
    expect(DB::table('collection_write_off_requests')->where('financial_obligation_id', $b->id)->value('status'))->toBe('APPROVED')
        ->and(DB::table('financial_obligations')->where('id', $b->id)->value('status'))->toBe('WRITTEN_OFF');
});

it('creates, logs, moves and assigns CRM leads through LeadDirectoryService', function () {
    $staff = rcUser($this->t, ['crm.leads.read', 'crm.leads.manage', 'crm.leads.assign'], 'CRM');
    $colleague = rcUser($this->t, ['crm.leads.read'], 'COL');

    rcAs($staff, $this->t);
    Livewire::test(CrmLeads::class)->callAction(TestAction::make('leadCreate')->table(), ['full_name' => 'Awa Prospect', 'phone_e164' => '+237670000111', 'city' => 'Douala', 'product_interest' => 'motor'])
        ->assertNotified(__('regulatory_crm_actions.leadCreate.done'));
    $lead = DB::table('partner_leads')->where('tenant_id', $this->t->id)->where('full_name', 'Awa Prospect')->first();
    expect($lead->status)->toBe('NEW')->and($lead->product_interest)->toBe('MOTOR');

    Livewire::test(CrmLeads::class)->assertCanSeeTableRecords([$lead->id])
        ->callAction(TestAction::make('leadTransition')->table($lead->id), ['status' => 'CONTACTED'])->assertNotified(__('regulatory_crm_actions.leadTransition.done'));
    expect(DB::table('partner_leads')->where('id', $lead->id)->value('status'))->toBe('CONTACTED');
    Livewire::test(CrmLeads::class)->callAction(TestAction::make('leadActivity')->table($lead->id), ['entry_type' => 'CALL', 'body' => 'Called, interested in third-party cover.'])
        ->assertNotified(__('regulatory_crm_actions.leadActivity.done'));
    Livewire::test(CrmLeads::class)->callAction(TestAction::make('leadAssign')->table($lead->id), ['assigned_user_id' => $colleague->id, 'reason' => 'Territory owner'])
        ->assertNotified(__('regulatory_crm_actions.leadAssign.done'));
    expect(DB::table('partner_leads')->where('id', $lead->id)->value('assigned_user_id'))->toBe($colleague->id);
});

it('onboards an insurer (setup, checklist, capability profile, signing keys) and drafts a carrier-broker agreement', function () {
    $admin = rcUser($this->t, ['carrier_setup.view', 'carrier_setup.manage', 'capability_profiles.view', 'capability_profiles.manage', 'claims.carrier.keys',
        'distribution.agreements.view', 'distribution.agreements.manage'], 'ONB');
    $carrier = rcCarrier();
    $partner = rcPartner($this->t);

    rcAs($admin, $this->t);
    Livewire::test(InsurerOnboarding::class)->assertCanSeeTableRecords([$carrier->id])
        ->assertActionHidden(TestAction::make('setupAttest')->table($carrier->id))
        ->callAction(TestAction::make('setupOpen')->table($carrier->id), ['notes' => 'Kick-off'])->assertNotified(__('regulatory_crm_actions.setupOpen.done'));
    expect(DB::table('carrier_setups')->where('carrier_id', $carrier->id)->value('status'))->toBe('DRAFT');
    Livewire::test(InsurerOnboarding::class)->assertActionHidden(TestAction::make('setupOpen')->table($carrier->id));
    Livewire::test(InsurerOnboarding::class)->callAction(TestAction::make('setupAttest')->table($carrier->id), ['item' => 'LEGAL_INFORMATION', 'status' => 'COMPLETE', 'evidence_reference' => 'RCCM-123'])
        ->assertNotified(__('regulatory_crm_actions.setupAttest.done'));
    Livewire::test(InsurerOnboarding::class)->callAction(TestAction::make('capabilityDraft')->table($carrier->id), ['modes' => '[{"capability":"RATING","mode":"MANUAL_PREMIUM"}]'])
        ->assertNotified(__('regulatory_crm_actions.capabilityDraft.done'));
    expect(DB::table('carrier_capability_profiles')->where(['carrier_id' => $carrier->id, 'status' => 'DRAFT'])->exists())->toBeTrue();
    // A second draft while one is open is refused by CapabilityProfileService and shown.
    Livewire::test(InsurerOnboarding::class)->callAction(TestAction::make('capabilityDraft')->table($carrier->id), ['modes' => '[]'])->assertNotified(__('workflow_actions.failed'));

    Livewire::test(InsurerOnboarding::class)->callAction(TestAction::make('signingKeyRegister')->table($carrier->id), ['key_id' => 'ck_ui_test_1'])
        ->assertNotified(__('regulatory_crm_actions.signingKeyRegister.done'));
    expect(DB::table('claim_carrier_signing_keys')->where(['carrier_id' => $carrier->id, 'key_id' => 'ck_ui_test_1'])->value('status'))->toBe('ACTIVE');
    Livewire::test(InsurerOnboarding::class)->callAction(TestAction::make('signingKeyRevoke')->table($carrier->id), ['key_id' => 'ck_ui_test_1'])
        ->assertNotified(__('regulatory_crm_actions.signingKeyRevoke.done'));
    expect(DB::table('claim_carrier_signing_keys')->where(['carrier_id' => $carrier->id, 'key_id' => 'ck_ui_test_1'])->value('status'))->toBe('REVOKED');

    Livewire::test(ListCarrierBrokerAgreements::class)->callAction('agreementCreate', ['carrier_id' => $carrier->id, 'partner_id' => $partner->id, 'agreement_number' => 'UI-B22-1',
        'effective_from' => now()->toDateString()])->assertNotified(__('regulatory_crm_actions.agreementCreate.done'));
    $agreement = DB::table('carrier_broker_agreements')->where('agreement_number', 'UI-B22-1')->first();
    expect($agreement->status)->toBe('DRAFT');
    Livewire::test(ViewCarrierBrokerAgreement::class, ['record' => $agreement->id])->assertActionHidden('agreementTransition')
        ->callAction('agreementSetProduct', ['line_code' => 'MOTOR', 'can_quote' => true, 'can_bind' => true, 'commission_basis_points' => 1000])
        ->assertNotified(__('regulatory_crm_actions.agreementSetProduct.done'));

    rcAs(rcUser($this->t, ['distribution.agreements.view', 'distribution.agreements.approve'], 'APP'), $this->t);
    Livewire::test(ViewCarrierBrokerAgreement::class, ['record' => $agreement->id])->callAction('agreementTransition', ['action' => 'activate', 'reason' => 'Carrier signed'])
        ->assertNotified(__('regulatory_crm_actions.agreementTransition.done'));
    expect(DB::table('carrier_broker_agreements')->where('id', $agreement->id)->value('status'))->toBe('ACTIVE');
});
