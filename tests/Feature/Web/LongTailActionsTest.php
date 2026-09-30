<?php

declare(strict_types=1);

/**
 * UI coverage batches 29-33: the Configuration / Operations / Platform desks (MiscConfigActions, MiscOperationsActions,
 * MiscPlatformActions) call the same services / controllers with the same permissions as the API routes.
 */

use App\Application\Cases\Models\CaseType;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\ConfigurationDesk;
use App\Filament\Admin\Pages\OperationsDesk;
use App\Filament\Admin\Pages\PlatformDesk;
use App\Filament\Admin\Resources\ComplianceCases\Pages\ListComplianceCases;
use App\Filament\Admin\Resources\RiskAlerts\Pages\ListRiskAlerts;
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

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function ltUser(string $tenantId, array $permissions, string $roleCode = 'OPS'): User
{
    $u = User::create(['full_name' => 'LT '.$roleCode.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => 'TENANT_ADMIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => $roleCode.'-'.Str::random(6), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function ltAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

const LT_CONFIG = ['authority.types.manage', 'cases.admin', 'claims.types.manage', 'premium_cover.rules.manage', 'premium_cover.rules.view', 'fraud.rules.manage',
    'health.benefits.manage', 'communications.manage', 'life_surrender.scales.manage', 'configuration.changes.manage', 'tenant.manage', 'privacy.purposes.manage',
    'catastrophe.events.manage', 'accumulation.capacity.check', 'capability_profiles.manage',
    // S6 (2026-09-29): staff may change another customer's communication preference only with customers.manage.
    'customers.manage'];
const LT_CHECKER = ['claims.types.approve', 'premium_cover.rules.approve', 'communications.approve', 'life_surrender.scales.approve', 'provider_tariffs.approve'];
const LT_OPS = ['fulfilments.manage', 'fulfilment.transition', 'provider_networks.manage', 'fraud.alert.create', 'fraud.alert.decide', 'health.cards.issue',
    'health.members.manage', 'legal.matters.manage', 'distribution.agreements.approve', 'onboarding.private_data.review', 'special_policies.schedule.manage',
    'premium_components.close', 'cargo_declarations.cancel', 'partners.manage', 'support.manage', 'communications.manage', 'broker.marketplace.manage'];

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    DB::table('tenant_customers')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'party_id' => $this->f['party']->id,
        'customer_number' => 'C-'.Str::random(6), 'status' => 'ACTIVE', 'private_metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $this->maker = ltUser($this->tenant, LT_CONFIG, 'MAKER');
    $this->checker = ltUser($this->tenant, LT_CHECKER, 'CHECKER');
    $this->ops = ltUser($this->tenant, LT_OPS, 'OPS');
});

it('opens each desk only with one of its permissions and hides actions without their own permission', function () {
    $none = ltUser($this->tenant, ['claims.view'], 'NONE');
    ltAs($none, $this->tenant);
    expect(ConfigurationDesk::canAccess())->toBeFalse()->and(OperationsDesk::canAccess())->toBeFalse()->and(PlatformDesk::canAccess())->toBeFalse();
    $this->get(ConfigurationDesk::getUrl(panel: 'admin'))->assertForbidden();

    ltAs($this->maker, $this->tenant);
    expect(ConfigurationDesk::canAccess())->toBeTrue()->and(PlatformDesk::canAccess())->toBeFalse(); // not the platform tenant
    Livewire::test(ConfigurationDesk::class)->assertOk()
        ->assertActionVisible('authorityTypeAdd')->assertActionVisible('queueCreate')->assertActionVisible('featureFlagSet')
        ->assertActionHidden('claimTypeApprove')->assertActionHidden('premiumCoverRuleApprove')->assertActionHidden('notificationTemplateApprove');

    ltAs($this->checker, $this->tenant);
    Livewire::test(ConfigurationDesk::class)->assertOk()->assertActionVisible('claimTypeApprove')->assertActionHidden('authorityTypeAdd')->assertActionHidden('queueCreate');

    ltAs($this->ops, $this->tenant);
    Livewire::test(OperationsDesk::class)->assertOk()->assertActionVisible('supportTicketCreate')->assertActionVisible('providerNetworkCreate')
        ->assertActionHidden('providerTariffApprove')->assertActionHidden('carrierProductStatus');
});

it('keeps platform actions to platform administrators in the platform tenant', function () {
    $platform = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'Platform '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $admin = ltUser($platform->id, ['platform.settings.manage', 'tenant.manage', 'configuration.changes.manage'], 'PLATFORM_ADMIN');
    // Creating an organisation is a platform-admin act (security 2026-09-29, PlatformAuthority::isPlatformAdmin).
    TenantMembership::where('user_id', $admin->id)->update(['role_code' => 'PLATFORM_ADMIN']);
    ltAs($admin, $platform->id);
    expect(PlatformDesk::canAccess())->toBeTrue();
    Livewire::test(PlatformDesk::class)->assertOk()->assertActionVisible('tenantCreate')->assertActionVisible('platformTimezone')
        ->callAction('tenantCreate', ['type' => 'BROKER', 'legal_name' => 'New Broker SARL', 'slug' => 'new-broker-'.Str::lower(Str::random(5)), 'primary_locale' => 'fr'])
        ->assertNotified(__('misc_actions.tenantCreate.done'));
    expect(DB::table('tenants')->where(['legal_name' => 'New Broker SARL', 'status' => 'PENDING'])->exists())->toBeTrue();

    // The same permissions in an ordinary tenant do not reach the platform actions.
    $tenantAdmin = ltUser($this->tenant, ['platform.settings.manage', 'tenant.manage'], 'TADMIN');
    ltAs($tenantAdmin, $this->tenant);
    expect(PlatformDesk::canAccess())->toBeFalse();
    Livewire::test(ConfigurationDesk::class)->assertActionVisible('tenantUpdate');
});

it('runs catalogue, rule and template actions through their services with maker-checker kept', function () {
    ltAs($this->maker, $this->tenant);
    $desk = fn () => Livewire::test(ConfigurationDesk::class);

    $desk()->callAction('authorityTypeAdd', ['code' => 'LT_SIGN', 'name' => 'Long-tail signing', 'monetary' => true])->assertNotified(__('misc_actions.authorityTypeAdd.done'));
    expect(DB::table('authority_types')->where(['code' => 'LT_SIGN', 'status' => 'ACTIVE'])->exists())->toBeTrue();
    $desk()->callAction('authorityTypeRetire', ['code' => 'LT_SIGN', 'reason' => 'No longer used'])->assertNotified(__('misc_actions.authorityTypeRetire.done'));
    expect(DB::table('authority_types')->where('code', 'LT_SIGN')->value('status'))->not->toBe('ACTIVE');

    $recovery = CaseType::where('code', 'RECOVERY')->orderByDesc('version')->firstOrFail();
    $desk()->callAction('caseTypeDraft', ['code' => 'RECOVERY', 'states' => json_encode($recovery->states), 'transitions' => json_encode($recovery->transitions)])
        ->assertNotified(__('misc_actions.caseTypeDraft.done'));
    expect(CaseType::where(['code' => 'RECOVERY', 'status' => 'DRAFT'])->exists())->toBeTrue();
    $desk()->callAction('caseTypeDraft', ['code' => 'RECOVERY', 'states' => '{not json', 'transitions' => '[]'])->assertNotified(__('workflow_actions.failed'));
    $draftType = CaseType::where(['code' => 'RECOVERY', 'status' => 'DRAFT'])->firstOrFail();
    ltAs(ltUser($this->tenant, ['cases.admin'], 'CASE_CHECKER'), $this->tenant); // CaseTypeService: a different administrator approves
    Livewire::test(ConfigurationDesk::class)->callAction('caseTypeApprove', ['type' => $draftType->id])->assertNotified(__('misc_actions.caseTypeApprove.done'));
    expect($draftType->fresh()->status)->not->toBe('DRAFT');
    ltAs($this->maker, $this->tenant);

    $capability = array_key_first(\App\Application\Capabilities\CapabilityCatalogue::CAPABILITIES);
    $desk()->callAction('capabilityPin', ['subject_type' => 'quote', 'subject_id' => $this->f['quote']->id, 'carrier_id' => $this->f['carrier']->id, 'capability' => $capability])
        ->assertNotified(__('misc_actions.capabilityPin.done'));
    expect(DB::table('capability_pins')->where(['subject_id' => $this->f['quote']->id, 'capability' => $capability])->exists())->toBeTrue();

    $desk()->callAction('claimTypeDraft', ['code' => 'MOTOR_DAMAGE', 'line_code' => 'MOTOR', 'currency' => 'XAF'])->assertNotified(__('misc_actions.claimTypeDraft.done'));
    $claimType = DB::table('claim_type_versions')->where(['tenant_id' => $this->tenant, 'code' => 'MOTOR_DAMAGE'])->first();
    expect($claimType->status)->toBe('DRAFT');

    $desk()->callAction('premiumCoverRuleDraft', ['code' => 'LT_GRACE', 'name' => 'Grace 15 days', 'outcome' => 'GRACE', 'grace_days' => 15,
        'activation_rule' => 'true', 'effective_from' => now()->toDateString()])->assertNotified(__('misc_actions.premiumCoverRuleDraft.done'));
    $rule = DB::table('premium_cover_rules')->where('code', 'LT_GRACE')->first();
    expect($rule->status)->toBe('DRAFT');

    $desk()->callAction('notificationTemplateDraft', ['code' => 'LT_HELLO', 'locale' => 'en', 'purpose' => 'TRANSACTIONAL', 'channel' => 'SMS', 'body' => 'Hello {name}'])
        ->assertNotified(__('misc_actions.notificationTemplateDraft.done'));
    $template = DB::table('notification_templates')->where(['tenant_id' => $this->tenant, 'code' => 'LT_HELLO'])->first();
    expect($template->status)->toBe('DRAFT');

    $desk()->callAction('fraudRuleCreate', ['code' => 'LT_DUP', 'scope' => 'CLAIM', 'risk_points' => 40, 'conditions' => '{"duplicate_invoice": true}',
        'effective_from' => now()->toDateString()])->assertNotified(__('misc_actions.fraudRuleCreate.done'));
    expect(DB::table('fraud_rule_versions')->where(['code' => 'LT_DUP', 'status' => 'DRAFT'])->exists())->toBeTrue();

    // HealthMemberService refuses a rule without a service target; the refusal is shown.
    $desk()->callAction('healthBenefitRuleAdd', ['coverage_code' => 'HOSP', 'benefit_code' => 'ROOM'])->assertNotified(__('workflow_actions.failed'));
    $desk()->callAction('healthBenefitRuleAdd', ['service_category_code' => 'HOSPITALISATION', 'coverage_code' => 'HOSP', 'benefit_code' => 'ROOM', 'waiting_period_days' => 30])
        ->assertNotified(__('misc_actions.healthBenefitRuleAdd.done'));
    expect(DB::table('health_benefit_rules')->where(['tenant_id' => $this->tenant, 'benefit_code' => 'ROOM'])->exists())->toBeTrue();

    $desk()->callAction('premiumCoverEvaluate', ['premium_status' => 'PAID', 'effective_date' => now()->toDateString()])->assertNotified(__('misc_actions.premiumCoverEvaluate.done'));

    // Checker: a different user approves; the maker cannot hold the approve permission here, so the actions are hidden for them.
    ltAs($this->checker, $this->tenant);
    Livewire::test(ConfigurationDesk::class)->callAction('claimTypeApprove', ['version' => $claimType->id])->assertNotified(__('misc_actions.claimTypeApprove.done'));
    expect(DB::table('claim_type_versions')->where('id', $claimType->id)->value('status'))->toBe('ACTIVE');
    Livewire::test(ConfigurationDesk::class)->callAction('premiumCoverRuleApprove', ['id' => $rule->id, 'verification_status' => 'UNVERIFIED'])
        ->assertNotified(__('misc_actions.premiumCoverRuleApprove.done'));
    expect(DB::table('premium_cover_rules')->where('id', $rule->id)->value('status'))->toBe('ACTIVE');
    Livewire::test(ConfigurationDesk::class)->callAction('notificationTemplateApprove', ['template' => $template->id])->assertNotified(__('misc_actions.notificationTemplateApprove.done'));
    expect(DB::table('notification_templates')->where('id', $template->id)->value('status'))->toBe('ACTIVE');

    // Maker-checker refusal: a user holding both permissions cannot approve their own draft; the failure is shown.
    $both = ltUser($this->tenant, ['communications.manage', 'communications.approve'], 'BOTH');
    ltAs($both, $this->tenant);
    Livewire::test(ConfigurationDesk::class)->callAction('notificationTemplateDraft', ['code' => 'LT_SELF', 'locale' => 'fr', 'purpose' => 'CLAIMS', 'channel' => 'EMAIL', 'body' => 'Bonjour'])
        ->assertNotified(__('misc_actions.notificationTemplateDraft.done'));
    $own = DB::table('notification_templates')->where(['tenant_id' => $this->tenant, 'code' => 'LT_SELF'])->value('id');
    Livewire::test(ConfigurationDesk::class)->callAction('notificationTemplateApprove', ['template' => $own])->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('notification_templates')->where('id', $own)->value('status'))->toBe('DRAFT');

    ltAs($this->maker, $this->tenant);
    Livewire::test(ConfigurationDesk::class)->callAction('premiumCoverRuleRetire', ['id' => $rule->id, 'reason' => 'Superseded by new rule'])->assertNotified(__('misc_actions.premiumCoverRuleRetire.done'));
    expect(DB::table('premium_cover_rules')->where('id', $rule->id)->value('status'))->toBe('RETIRED');
});

it('runs queue, SLA, governance, communication and organisation actions', function () {
    ltAs($this->maker, $this->tenant);
    $desk = fn () => Livewire::test(ConfigurationDesk::class);

    $desk()->callAction('queueCreate', ['code' => 'LT_Q', 'name' => 'Long-tail queue', 'routing_rule' => 'PULL'])->assertNotified(__('misc_actions.queueCreate.done'));
    $queue = DB::table('queues')->where(['tenant_id' => $this->tenant, 'code' => 'LT_Q'])->first();
    expect($queue)->not->toBeNull();
    $desk()->callAction('queueMemberAdd', ['queue' => $queue->id, 'user_id' => $this->ops->id, 'capacity' => 10])->assertNotified(__('misc_actions.queueMemberAdd.done'));
    expect(DB::table('queue_members')->where(['queue_id' => $queue->id, 'user_id' => $this->ops->id])->exists())->toBeTrue();

    $desk()->callAction('slaOverrideAdd', ['case_type_code' => 'RECOVERY', 'metric' => 'RESOLUTION', 'target_business_days' => 5])->assertNotified(__('misc_actions.slaOverrideAdd.done'));
    $override = DB::table('sla_policy_overrides')->where(['tenant_id' => $this->tenant, 'case_type_code' => 'RECOVERY', 'status' => 'ACTIVE'])->first();
    expect($override)->not->toBeNull();
    $desk()->callAction('slaOverrideRetire', ['id' => $override->id, 'reason' => 'Back to default'])->assertNotified(__('misc_actions.slaOverrideRetire.done'));
    expect(DB::table('sla_policy_overrides')->where('id', $override->id)->value('status'))->toBe('RETIRED');
    // A regulatory deadline without legal basis is refused by the controller rule.
    $desk()->callAction('slaOverrideAdd', ['case_type_code' => 'RECOVERY', 'metric' => 'RESOLUTION', 'target_business_days' => 5, 'label' => 'REGULATORY_DEADLINE'])
        ->assertNotified(__('workflow_actions.failed'));

    $desk()->callAction('configChangeDraft', ['config_type' => 'claims', 'config_key' => 'lt.limit', 'proposed_value' => '{"max": 5}', 'reason' => 'Raise the limit'])
        ->assertNotified(__('misc_actions.configChangeDraft.done'));
    $change = DB::table('configuration_change_sets')->where(['tenant_id' => $this->tenant, 'config_key' => 'lt.limit'])->first();
    expect($change->status)->toBe('DRAFT');
    $desk()->callAction('configChangeSubmit', ['change' => $change->id])->assertNotified(__('misc_actions.configChangeSubmit.done'));
    expect(DB::table('configuration_change_sets')->where('id', $change->id)->value('status'))->not->toBe('DRAFT');

    $desk()->callAction('featureFlagSet', ['key' => 'lt.flag', 'enabled' => true])->assertNotified(__('misc_actions.featureFlagSet.done'));
    expect(DB::table('feature_flags')->where(['key' => 'lt.flag', 'tenant_id' => $this->tenant])->exists())->toBeTrue();

    $desk()->callAction('communicationPreferenceSet', ['party_id' => $this->f['party']->id, 'purpose' => 'MARKETING', 'channel' => 'SMS', 'enabled' => false])
        ->assertNotified(__('misc_actions.communicationPreferenceSet.done'));
    expect(DB::table('communication_preferences')->where(['party_id' => $this->f['party']->id, 'purpose' => 'MARKETING', 'channel' => 'SMS'])->value('enabled'))->toBeFalse();
    // Security messages cannot be switched off (controller abort 422) - shown, not saved.
    $desk()->callAction('communicationPreferenceSet', ['party_id' => $this->f['party']->id, 'purpose' => 'SECURITY', 'channel' => 'SMS', 'enabled' => false])
        ->assertNotified(__('workflow_actions.failed'));

    $desk()->callAction('largeLossThreshold', ['currency' => 'XAF', 'threshold_minor' => 50000000, 'recipient_user_ids' => [$this->maker->id]])
        ->assertNotified(__('misc_actions.largeLossThreshold.done'));
    $desk()->callAction('capacityCheck', ['sum_insured_minor' => 1000000, 'currency' => 'XAF'])->assertNotified(__('misc_actions.capacityCheck.done'));

    $desk()->callAction('branchCreate', ['code' => 'LT-DLA', 'name' => 'Douala Akwa'])->assertNotified(__('misc_actions.branchCreate.done'));
    $branch = DB::table('tenant_branches')->where(['tenant_id' => $this->tenant, 'code' => 'LT-DLA'])->first();
    expect($branch)->not->toBeNull();
    $desk()->callAction('departmentCreate', ['code' => 'LT-CLAIMS', 'name' => 'Claims', 'branch_id' => $branch->id])->assertNotified(__('misc_actions.departmentCreate.done'));
    $desk()->callAction('tenantUpdate', ['trade_name' => 'LT Trade Name'])->assertNotified(__('misc_actions.tenantUpdate.done'));
    expect(DB::table('tenants')->where('id', $this->tenant)->value('trade_name'))->toBe('LT Trade Name');
});

it('runs support, communication, provider network and distribution actions from the operations desk', function () {
    ltAs($this->ops, $this->tenant);
    $desk = fn () => Livewire::test(OperationsDesk::class);

    $desk()->callAction('supportTicketCreate', ['party_id' => $this->f['party']->id, 'type' => 'COMPLAINT', 'category' => 'BILLING', 'priority' => 'HIGH',
        'subject' => 'Charged twice', 'description' => 'The customer reports a double debit on the renewal premium.'])->assertNotified(__('misc_actions.supportTicketCreate.done'));
    $ticket = DB::table('support_tickets')->where(['tenant_id' => $this->tenant, 'subject' => 'Charged twice'])->first();
    expect($ticket->status)->toBe('OPEN');
    $desk()->callAction('supportTicketTransition', ['ticket' => $ticket->id, 'to_status' => 'TRIAGED', 'message' => 'Checked the ledger'])->assertNotified(__('misc_actions.supportTicketTransition.done'));
    expect(DB::table('support_tickets')->where('id', $ticket->id)->value('status'))->toBe('TRIAGED');
    // An illegal jump is refused by TicketStateMachine and nothing changes.
    $desk()->callAction('supportTicketTransition', ['ticket' => $ticket->id, 'to_status' => 'REOPENED', 'message' => 'Try reopen'])->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('support_tickets')->where('id', $ticket->id)->value('status'))->toBe('TRIAGED');

    $desk()->callAction('communicationLog', ['party_id' => $this->f['party']->id, 'direction' => 'INBOUND', 'channel' => 'PHONE', 'purpose' => 'COMPLAINT',
        'counterparty' => 'Customer', 'summary' => 'Called about the double debit.', 'ticket_id' => $ticket->id])->assertNotified(__('misc_actions.communicationLog.done'));

    $desk()->callAction('providerNetworkCreate', ['code' => 'LT_NET', 'name' => 'Douala hospitals', 'network_type_code' => 'PREFERRED', 'category' => 'HEALTH'])
        ->assertNotified(__('misc_actions.providerNetworkCreate.done'));
    expect(DB::table('provider_networks')->where(['tenant_id' => $this->tenant, 'code' => 'LT_NET'])->exists())->toBeTrue();

    $pub = (string) Str::uuid();
    DB::table('marketplace_publications')->insert(array_intersect_key(['id' => $pub, 'tenant_id' => $this->tenant, 'status' => 'APPROVED', 'approved_at' => now(), 'version' => 1, 'created_by' => $this->ops->id,
        'product_id' => $this->f['product']->id, 'carrier_id' => $this->f['carrier']->id, 'created_at' => now(), 'updated_at' => now()],
        array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('marketplace_publications'))));
    $desk()->callAction('marketplaceToggle', ['id' => $pub, 'enabled' => false])->assertNotified(__('misc_actions.marketplaceToggle.done'));
    expect(DB::table('marketplace_publications')->where('id', $pub)->value('status'))->toBe('PAUSED');
});

it('offers raise fraud alert on the risk alerts list, not on the compliance case list', function () {
    ltAs(ltUser($this->tenant, ['trust.fraud-alerts.create', 'fraud.alert.create', 'compliance.cases.read', 'compliance.cases.manage'], 'FRAUD'), $this->tenant);
    Livewire::test(ListRiskAlerts::class)->assertActionVisible('fraudAlert');
    Livewire::test(ListComplianceCases::class)->assertActionDoesNotExist('fraudAlert');
});
