<?php

declare(strict_types=1);

/**
 * UI coverage batches 20, 25, 27 — risk-transfer workbench (admin panel): every action calls the same service method,
 * with the same permission, as its API route; maker-checker rules stay in the services.
 */

use App\Application\Capabilities\CapabilityProfileService;
use App\Application\Integrations\Developer\OAuthScopeCatalogue;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\RiskTransfer\AccumulationZones;
use App\Filament\Admin\Pages\RiskTransfer\CapabilityProfiles;
use App\Filament\Admin\Pages\RiskTransfer\CarrierConnectorMessages;
use App\Filament\Admin\Pages\RiskTransfer\CatastropheEvents;
use App\Filament\Admin\Pages\RiskTransfer\CoinsuranceArrangements;
use App\Filament\Admin\Pages\RiskTransfer\ComplianceControls;
use App\Filament\Admin\Pages\RiskTransfer\DeveloperConsents;
use App\Filament\Admin\Pages\RiskTransfer\DeveloperKeys;
use App\Filament\Admin\Pages\RiskTransfer\InsuranceChecks;
use App\Filament\Admin\Pages\RiskTransfer\LegalMatters;
use App\Filament\Admin\Pages\RiskTransfer\Reinsurers;
use App\Filament\Admin\Pages\RiskTransfer\ReinsuranceTreaties;
use App\Models\Carrier;
use App\Models\Claim;
use App\Models\IntegrationClient;
use App\Models\Party;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const RT_VIEW = ['reinsurance.treaties.view', 'reinsurance.facultative.view', 'reinsurance.recoveries.view', 'coinsurance.view', 'legal.matters.view',
    'integrations.manage', 'integrations.consent.manage', 'integrations.carrier_connectors.manage', 'accumulation.view', 'catastrophe.events.view',
    'capability_profiles.view', 'compliance.catalogue.view', 'rules.evaluate'];

function rtUser(string $tenantId, array $permissions): User
{
    $u = User::create(['full_name' => 'RT '.Str::random(5), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => 'COMPLIANCE_ADMIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'RT-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function rtAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    app(TenantContext::class)->set($this->tenant);
});

it('renders every workbench screen for the read permission and 403s without it', function () {
    $urls = ['/admin/risk-transfer/reinsurers', '/admin/risk-transfer/treaties', '/admin/risk-transfer/facultative', '/admin/risk-transfer/recoveries',
        '/admin/risk-transfer/coinsurance', '/admin/risk-transfer/legal-matters', '/admin/risk-transfer/developer-keys', '/admin/risk-transfer/developer-consents',
        '/admin/risk-transfer/carrier-connectors', '/admin/risk-transfer/accumulation', '/admin/risk-transfer/catastrophe-events',
        '/admin/risk-transfer/capability-profiles', '/admin/risk-transfer/compliance-controls', '/admin/risk-transfer/insurance-checks'];
    $this->actingAs(rtUser($this->tenant, RT_VIEW));
    foreach ($urls as $url) {
        $this->get($url)->assertOk();
    }
    $this->flushSession();
    $this->actingAs(rtUser($this->tenant, ['claims.view']));
    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('shows write actions only to holders of the API permission', function () {
    rtAs(rtUser($this->tenant, ['reinsurance.treaties.view', 'coinsurance.view', 'legal.matters.view', 'accumulation.view']), $this->tenant);
    Livewire::test(ReinsuranceTreaties::class)->assertOk()->assertActionHidden(TestAction::make('treatyCreate')->table())->assertActionHidden(TestAction::make('cessionCede')->table());
    Livewire::test(Reinsurers::class)->assertActionHidden(TestAction::make('reinsurerCreate')->table());
    Livewire::test(CoinsuranceArrangements::class)->assertActionHidden(TestAction::make('coCreate')->table());
    Livewire::test(LegalMatters::class)->assertActionHidden(TestAction::make('legalOpen')->table());
    Livewire::test(AccumulationZones::class)->assertActionHidden(TestAction::make('zoneCreate')->table());

    rtAs(rtUser($this->tenant, ['reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.reinsurers.manage', 'reinsurance.cessions.calculate']), $this->tenant);
    Livewire::test(ReinsuranceTreaties::class)->assertActionVisible(TestAction::make('treatyCreate')->table())->assertActionVisible(TestAction::make('cessionCede')->table());
    Livewire::test(Reinsurers::class)->assertActionVisible(TestAction::make('reinsurerCreate')->table());
});

it('runs the treaty lifecycle through TreatyService / RecoveryService with four eyes on activation', function () {
    $maker = rtUser($this->tenant, ['reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.reinsurers.manage', 'reinsurance.treaties.approve']);
    $checker = rtUser($this->tenant, ['reinsurance.treaties.view', 'reinsurance.treaties.approve', 'reinsurance.reinsurers.approve_security']);

    rtAs($maker, $this->tenant);
    $page = Livewire::test(Reinsurers::class);
    $page->callAction(TestAction::make('reinsurerCreate')->table(), ['code' => 'SCOR', 'name' => 'Scor Re', 'role' => 'REINSURER'])->assertHasNoFormErrors()->assertNotified(__('risk_transfer_actions.reinsurerCreate.done'));
    $page->callAction(TestAction::make('reinsurerCreate')->table(), ['code' => 'AFRE', 'name' => 'Africa Re', 'role' => 'REINSURER'])->assertNotified(__('risk_transfer_actions.reinsurerCreate.done'));
    $scor = DB::table('reinsurers')->where('tenant_id', $this->tenant)->where('code', 'SCOR')->first();
    $afre = DB::table('reinsurers')->where('tenant_id', $this->tenant)->where('code', 'AFRE')->first();
    expect($scor->approved_security_status)->toBe('PENDING_VERIFICATION');
    Livewire::test(Reinsurers::class)->callAction(TestAction::make('reinsurerStatus')->table($afre->id), ['status' => 'SUSPENDED', 'reason' => 'Rating watch'])
        ->assertNotified(__('risk_transfer_actions.reinsurerStatus.done'));
    expect(DB::table('reinsurers')->where('id', $afre->id)->value('status'))->toBe('SUSPENDED');
    Livewire::test(Reinsurers::class)->callAction(TestAction::make('reinsurerStatus')->table($afre->id), ['status' => 'ACTIVE', 'reason' => 'Watch lifted']);

    rtAs($checker, $this->tenant);
    foreach ([$scor->id, $afre->id] as $id) {
        Livewire::test(Reinsurers::class)->callAction(TestAction::make('reinsurerSecurity')->table($id), ['approved_security_status' => 'TENANT_APPROVED', 'reason' => 'Board approved list'])
            ->assertNotified(__('risk_transfer_actions.reinsurerSecurity.done'));
    }
    expect(DB::table('reinsurers')->where('id', $scor->id)->value('approved_security_status'))->toBe('TENANT_APPROVED');

    rtAs($maker, $this->tenant);
    Livewire::test(ReinsuranceTreaties::class)->callAction(TestAction::make('treatyCreate')->table(), ['code' => 'QS-2026', 'name' => 'Quota share 2026', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF', 'underwriting_year' => 2026])
        ->assertNotified(__('risk_transfer_actions.treatyCreate.done'));
    $treaty = DB::table('reinsurance_treaties')->where('tenant_id', $this->tenant)->where('code', 'QS-2026')->first();
    expect($treaty->status)->toBe('DRAFT');

    Livewire::test(ReinsuranceTreaties::class)->callAction(TestAction::make('treatyAddVersion')->table($treaty->id), [
        'effective_from' => now()->toDateString(), 'cession_percent' => 40,
        'participants' => [['reinsurer_id' => $scor->id, 'share_percent' => 60, 'is_lead' => true], ['reinsurer_id' => $afre->id, 'share_percent' => 40, 'is_lead' => false]],
    ])->assertNotified(__('risk_transfer_actions.treatyAddVersion.done'));
    $version = DB::table('reinsurance_treaty_versions')->where('treaty_id', $treaty->id)->first();
    expect($version->status)->toBe('DRAFT');

    Livewire::test(ReinsuranceTreaties::class)->callAction(TestAction::make('treatyThreshold')->table($treaty->id), ['threshold_minor' => 50000000, 'reason' => 'Large-loss notification'])
        ->assertNotified(__('risk_transfer_actions.treatyThreshold.done'));
    expect((int) DB::table('reinsurance_treaties')->where('id', $treaty->id)->value('large_loss_threshold_minor'))->toBe(50000000);

    // Maker-checker: the version's creator cannot activate it — refusal shown, nothing changes.
    Livewire::test(ReinsuranceTreaties::class)->callAction(TestAction::make('treatyActivate')->table($treaty->id), ['version_id' => $version->id, 'reason' => 'Signed'])
        ->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('reinsurance_treaty_versions')->where('id', $version->id)->value('status'))->toBe('DRAFT');

    rtAs($checker, $this->tenant);
    Livewire::test(ReinsuranceTreaties::class)->callAction(TestAction::make('treatyActivate')->table($treaty->id), ['version_id' => $version->id, 'reason' => 'Signed slip on file'])
        ->assertNotified(__('risk_transfer_actions.treatyActivate.done'));
    expect(DB::table('reinsurance_treaty_versions')->where('id', $version->id)->value('status'))->toBe('ACTIVE')
        ->and(DB::table('reinsurance_treaties')->where('id', $treaty->id)->value('status'))->toBe('ACTIVE');
});

it('creates, activates (four eyes), apportions and terminates a co-insurance arrangement', function () {
    $other = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Follower Assurances', 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
    $maker = rtUser($this->tenant, ['coinsurance.view', 'coinsurance.manage', 'coinsurance.approve']);
    $checker = rtUser($this->tenant, ['coinsurance.view', 'coinsurance.approve', 'coinsurance.apportion']);

    rtAs($maker, $this->tenant);
    Livewire::test(CoinsuranceArrangements::class)->callAction(TestAction::make('coCreate')->table(), [
        'reference' => 'COI-001', 'effective_from' => now()->toDateString(),
        'participants' => [['carrier_id' => $this->f['carrier']->id, 'role' => 'LEAD', 'share_bps' => 6000], ['carrier_id' => $other->id, 'role' => 'FOLLOWER', 'share_bps' => 4000]],
    ])->assertNotified(__('risk_transfer_actions.coCreate.done'));
    $a = DB::table('coinsurance_arrangements')->where('tenant_id', $this->tenant)->where('reference', 'COI-001')->first();
    expect($a->status)->toBe('DRAFT');

    Livewire::test(CoinsuranceArrangements::class)->callAction(TestAction::make('coPreview')->table($a->id), ['basis' => 'PREMIUM', 'total_minor' => 100000])
        ->assertNotified(__('risk_transfer_actions.coPreview.done'));
    Livewire::test(CoinsuranceArrangements::class)->callAction(TestAction::make('coActivate')->table($a->id))->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('coinsurance_arrangements')->where('id', $a->id)->value('status'))->toBe('DRAFT');

    rtAs($checker, $this->tenant);
    Livewire::test(CoinsuranceArrangements::class)->callAction(TestAction::make('coActivate')->table($a->id))->assertNotified(__('risk_transfer_actions.coActivate.done'));
    expect(DB::table('coinsurance_arrangements')->where('id', $a->id)->value('status'))->toBe('ACTIVE');
    Livewire::test(CoinsuranceArrangements::class)->callAction(TestAction::make('coApportion')->table($a->id), ['basis' => 'CLAIM', 'total_minor' => 250000, 'source_type' => 'manual'])
        ->assertNotified(__('risk_transfer_actions.coApportion.done'));
    expect(DB::table('coinsurance_apportionments')->where('arrangement_id', $a->id)->count())->toBe(1);
    Livewire::test(CoinsuranceArrangements::class)->callAction(TestAction::make('coTerminate')->table($a->id), ['reason' => 'Policy cancelled at renewal date'])
        ->assertNotified(__('risk_transfer_actions.coTerminate.done'));
    expect(DB::table('coinsurance_arrangements')->where('id', $a->id)->value('status'))->toBe('TERMINATED');
});

it('opens a legal matter and records hearing, deadline, cost and outcome', function () {
    rtAs(rtUser($this->tenant, ['legal.matters.view', 'legal.matters.manage']), $this->tenant);
    Livewire::test(LegalMatters::class)->callAction(TestAction::make('legalOpen')->table(), ['role' => 'DEFENDANT', 'court' => 'Tribunal de Première Instance de Douala', 'currency' => 'XAF', 'claimed_amount_minor' => 5000000])
        ->assertNotified(__('risk_transfer_actions.legalOpen.done'));
    $m = DB::table('legal_matters')->where('tenant_id', $this->tenant)->first();
    expect($m->status)->toBe('ACTIVE');

    $p = fn () => Livewire::test(LegalMatters::class);
    $p()->callAction(TestAction::make('legalHearing')->table($m->id), ['scheduled_at' => now()->addWeek()->toDateTimeString(), 'location' => 'Salle 2'])->assertNotified(__('risk_transfer_actions.legalHearing.done'));
    $p()->callAction(TestAction::make('legalDeadline')->table($m->id), ['description' => 'Conclusions', 'due_at' => now()->addDays(10)->toDateTimeString()])->assertNotified(__('risk_transfer_actions.legalDeadline.done'));
    $p()->callAction(TestAction::make('legalCost')->table($m->id), ['cost_type' => 'LAWYER_FEE', 'amount_minor' => 150000])->assertNotified(__('risk_transfer_actions.legalCost.done'));
    expect(DB::table('legal_hearings')->where('legal_matter_id', $m->id)->count())->toBe(1)
        ->and(DB::table('legal_deadlines')->where('legal_matter_id', $m->id)->count())->toBe(1);
    $p()->callAction(TestAction::make('legalOutcome')->table($m->id), ['outcome' => 'WON', 'notes' => 'Claim dismissed'])->assertNotified(__('risk_transfer_actions.legalOutcome.done'));
    expect(DB::table('legal_matters')->where('id', $m->id)->value('status'))->toBe('CONCLUDED');
});

it('manages zones, capacity, snapshots and a catastrophe event', function () {
    rtAs(rtUser($this->tenant, ['accumulation.view', 'accumulation.manage', 'catastrophe.events.view', 'catastrophe.events.manage']), $this->tenant);
    Livewire::test(AccumulationZones::class)->callAction(TestAction::make('zoneCreate')->table(), ['code' => 'LIT', 'name' => 'Littoral', 'country_code' => 'CM', 'geography_codes' => ['CM-LT']])
        ->assertNotified(__('risk_transfer_actions.zoneCreate.done'));
    $zone = DB::table('accumulation_zones')->where('tenant_id', $this->tenant)->where('code', 'LIT')->first();
    expect($zone)->not->toBeNull();
    Livewire::test(AccumulationZones::class)->callAction(TestAction::make('capacitySetLimit')->table(), ['zone_id' => $zone->id, 'peril_code' => 'FLOOD', 'currency' => 'XAF', 'gross_limit_minor' => 900000000])
        ->assertNotified(__('risk_transfer_actions.capacitySetLimit.done'));
    expect(DB::table('accumulation_capacity_limits')->where('tenant_id', $this->tenant)->count())->toBe(1);
    Livewire::test(AccumulationZones::class)->callAction(TestAction::make('locationsRebuild')->table())->assertNotified(__('risk_transfer_actions.locationsRebuild.done'));
    Livewire::test(AccumulationZones::class)->callAction(TestAction::make('snapshotTake')->table(), ['currency' => 'XAF'])->assertNotified(__('risk_transfer_actions.snapshotTake.done'));
    expect(DB::table('accumulation_snapshots')->where('tenant_id', $this->tenant)->count())->toBe(1);

    Livewire::test(CatastropheEvents::class)->callAction(TestAction::make('catDeclare')->table(), ['code' => 'FLOOD-2026-09', 'name' => 'Douala floods', 'peril_code' => 'FLOOD', 'zone_ids' => [$zone->id],
        'starts_at' => now()->subDays(3)->toDateTimeString(), 'ends_at' => now()->subDay()->toDateTimeString(), 'currency' => 'XAF'])->assertNotified(__('risk_transfer_actions.catDeclare.done'));
    $event = DB::table('catastrophe_events')->where('tenant_id', $this->tenant)->first();
    Livewire::test(CatastropheEvents::class)->callAction(TestAction::make('catAggregate')->table($event->id))->assertNotified(__('risk_transfer_actions.catAggregate.done'));
    Livewire::test(CatastropheEvents::class)->callAction(TestAction::make('catClose')->table($event->id), ['reason' => 'Event over'])->assertNotified(__('risk_transfer_actions.catClose.done'));
    expect(DB::table('catastrophe_events')->where('id', $event->id)->value('status'))->toBe('CLOSED');
});

it('grants and revokes an API consent and sets rate limits, never listing a secret', function () {
    $scope = OAuthScopeCatalogue::scopes()[0];
    $client = IntegrationClient::create(['name' => 'Bank connector', 'client_id' => 'bank-'.Str::random(6), 'status' => 'ACTIVE', 'scopes' => [$scope], 'environment' => 'production',
        'rate_limit_per_minute' => 60, 'sandbox_rate_limit_per_minute' => 30]);
    rtAs(rtUser($this->tenant, ['integrations.manage', 'integrations.revoke', 'integrations.consent.manage']), $this->tenant);

    Livewire::test(DeveloperKeys::class)->callAction(TestAction::make('devRateLimits')->table(), ['integration_client_id' => $client->id, 'rate_limit_per_minute' => 120, 'sandbox_rate_limit_per_minute' => 20])
        ->assertNotified(__('risk_transfer_actions.devRateLimits.done'));
    expect($client->refresh()->rate_limit_per_minute)->toBe(120);

    Livewire::test(DeveloperConsents::class)->callAction(TestAction::make('devGrantConsent')->table(), ['integration_client_id' => $client->id, 'scopes' => [$scope]])
        ->assertNotified(__('risk_transfer_actions.devGrantConsent.done'));
    $consent = DB::table('integration_client_consents')->where('tenant_id', $this->tenant)->first();
    expect($consent->status)->toBe('GRANTED');
    Livewire::test(DeveloperConsents::class)->callAction(TestAction::make('devRevokeConsent')->table($consent->id), ['reason' => 'Contract ended'])
        ->assertNotified(__('risk_transfer_actions.devRevokeConsent.done'));
    expect(DB::table('integration_client_consents')->where('id', $consent->id)->value('status'))->toBe('REVOKED');

    // A key cannot be issued for a suspended connection: the service refusal is shown and no key (nor secret) appears.
    $client->update(['status' => 'SUSPENDED']);
    Livewire::test(DeveloperKeys::class)->callAction(TestAction::make('devIssueKey')->table(), ['integration_client_id' => $client->id, 'environment' => 'production'])
        ->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('integration_client_keys')->count())->toBe(0);
});

it('configures a manual connector, dispatches to the fallback queue and resolves it', function () {
    rtAs(rtUser($this->tenant, ['integrations.carrier_connectors.manage']), $this->tenant);
    $carrier = $this->f['carrier'];
    Livewire::test(CarrierConnectorMessages::class)->callAction(TestAction::make('connectorConfigure')->table(), ['carrier_id' => $carrier->id, 'transport' => 'MANUAL'])
        ->assertNotified(__('risk_transfer_actions.connectorConfigure.done'));
    expect(DB::table('carrier_connector_configs')->where('carrier_id', $carrier->id)->value('transport'))->toBe('MANUAL');

    $id = (string) Str::uuid();
    DB::table('carrier_exchange_messages')->insert(['id' => $id, 'carrier_id' => $carrier->id, 'direction' => 'OUTBOUND', 'message_type' => 'CLAIM_NOTICE', 'correlation_id' => 'corr-'.Str::random(6),
        'status' => 'QUEUED', 'payload' => json_encode(['x' => 1]), 'payload_hash' => hash('sha256', 'x'), 'attempt_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
    Livewire::test(CarrierConnectorMessages::class)->callAction(TestAction::make('connectorDispatch')->table($id))->assertNotified(__('risk_transfer_actions.connectorDispatch.done'));
    expect(DB::table('carrier_exchange_messages')->where('id', $id)->value('status'))->toBe('MANUAL_FALLBACK');
    Livewire::test(CarrierConnectorMessages::class)->callAction(TestAction::make('connectorResolveFallback')->table($id), ['resolution' => 'SENT_MANUALLY', 'note' => 'Emailed to the carrier desk'])
        ->assertNotified(__('risk_transfer_actions.connectorResolveFallback.done'));
    expect(DB::table('carrier_exchange_messages')->where('id', $id)->value('fallback_resolution'))->toBe('SENT_MANUALLY');
});

it('edits, submits and rejects a capability profile (checker only)', function () {
    $maker = rtUser($this->tenant, ['capability_profiles.view', 'capability_profiles.manage', 'capability_profiles.approve']);
    $checker = rtUser($this->tenant, ['capability_profiles.view', 'capability_profiles.approve']);
    $profile = app(CapabilityProfileService::class)->draft($this->f['carrier'], [], $maker);

    rtAs($maker, $this->tenant);
    Livewire::test(CapabilityProfiles::class)->callAction(TestAction::make('capabilityReplaceModes')->table($profile), ['modes' => [['capability' => 'PAYMENT', 'mode' => 'BROKER_COLLECTION']]])
        ->assertNotified(__('risk_transfer_actions.capabilityReplaceModes.done'));
    expect($profile->modes()->count())->toBe(1);
    Livewire::test(CapabilityProfiles::class)->callAction(TestAction::make('capabilitySubmit')->table($profile))->assertNotified(__('risk_transfer_actions.capabilitySubmit.done'));
    expect($profile->refresh()->status)->toBe('SUBMITTED');
    // Maker-checker: the maker holds capability_profiles.approve but the service refuses.
    Livewire::test(CapabilityProfiles::class)->callAction(TestAction::make('capabilityApprove')->table($profile))->assertNotified(__('workflow_actions.failed'));
    rtAs($checker, $this->tenant);
    Livewire::test(CapabilityProfiles::class)->callAction(TestAction::make('capabilityReject')->table($profile), ['reason' => 'Payment mode unconfirmed'])
        ->assertNotified(__('risk_transfer_actions.capabilityReject.done'));
    expect($profile->refresh()->status)->toBe('REJECTED');
});

it('configures and approves a KYC refresh policy and assesses a control within the verified-text rule', function () {
    $maker = rtUser($this->tenant, ['compliance.catalogue.view', 'compliance.catalogue.configure', 'compliance.catalogue.approve', 'compliance.controls.assess', 'fraud.indicators.flag']);
    $checker = rtUser($this->tenant, ['compliance.catalogue.view', 'compliance.catalogue.approve']);
    $control = (string) Str::uuid();
    DB::table('compliance_controls')->insert(['id' => $control, 'framework' => 'ICT', 'control_code' => 'ICT-UI-1', 'domain' => 'Access', 'data_status' => 'UNVERIFIED', 'source' => 'UI_TEST', 'created_at' => now(), 'updated_at' => now()]);

    rtAs($maker, $this->tenant);
    Livewire::test(ComplianceControls::class)->callAction(TestAction::make('refreshConfigure')->table(), ['level' => 'HIGH', 'refresh_months' => 12, 'source_policy_id' => 'KYC-POL-7', 'trigger_events' => ['ONBOARDING']])
        ->assertNotified(__('risk_transfer_actions.refreshConfigure.done'));
    $policy = DB::table('kyc_refresh_policies')->where('tenant_id', $this->tenant)->first();
    Livewire::test(ComplianceControls::class)->callAction(TestAction::make('refreshApprove')->table(), ['policy_id' => $policy->id])->assertNotified(__('workflow_actions.failed'));
    // Rated status on an unverified control is refused; NOT_APPLICABLE is recorded.
    Livewire::test(ComplianceControls::class)->callAction(TestAction::make('controlAssess')->table($control), ['status' => 'COMPLIANT'])->assertNotified(__('workflow_actions.failed'));
    Livewire::test(ComplianceControls::class)->callAction(TestAction::make('controlAssess')->table($control), ['status' => 'NOT_APPLICABLE', 'notes' => 'No ICT scope'])
        ->assertNotified(__('risk_transfer_actions.controlAssess.done'));
    expect(DB::table('compliance_control_assessments')->where('control_id', $control)->count())->toBe(1);

    rtAs($checker, $this->tenant);
    Livewire::test(ComplianceControls::class)->callAction(TestAction::make('refreshApprove')->table(), ['policy_id' => $policy->id])->assertNotified(__('risk_transfer_actions.refreshApprove.done'));
    expect(DB::table('kyc_refresh_policies')->where('id', $policy->id)->value('data_status'))->toBe('VERIFIED');
});

it('links a claim to a catastrophe event and flags a fraud indicator on it', function () {
    $policy = makeMobileTestPolicy($this->f['proposal'], $this->f['tenant'], $this->f['carrier']->id, $this->f['party']->id);
    $claim = Claim::create(['tenant_id' => $this->tenant, 'policy_id' => $policy->id, 'claimant_party_id' => $this->f['party']->id, 'claim_number' => 'CLM-'.Str::random(8),
        'status' => 'SUBMITTED', 'loss_occurred_at' => now()->subDays(2), 'loss_details' => ['description' => 'Flood damage'], 'currency' => 'XAF']);
    DB::table('fraud_indicators')->insert(['id' => (string) Str::uuid(), 'code' => 'UI_LATE_NOTICE', 'category' => 'CLAIM', 'severity' => 'MEDIUM', 'data_status' => 'UNVERIFIED', 'source' => 'UI_TEST',
        'created_at' => now(), 'updated_at' => now()]);
    rtAs(rtUser($this->tenant, ['catastrophe.events.view', 'catastrophe.events.manage', 'compliance.catalogue.view', 'fraud.indicators.flag']), $this->tenant);

    Livewire::test(CatastropheEvents::class)->callAction(TestAction::make('catDeclare')->table(), ['code' => 'FLOOD-X', 'name' => 'Floods', 'peril_code' => 'FLOOD',
        'starts_at' => now()->subDays(4)->toDateTimeString(), 'ends_at' => now()->toDateTimeString(), 'currency' => 'XAF']);
    $event = DB::table('catastrophe_events')->where('tenant_id', $this->tenant)->first();
    Livewire::test(CatastropheEvents::class)->callAction(TestAction::make('catLinkClaim')->table($event->id), ['claim_id' => $claim->id])->assertNotified(__('risk_transfer_actions.catLinkClaim.done'));
    expect($claim->refresh()->catastrophe_event_id)->toBe($event->id);

    Livewire::test(ComplianceControls::class)->callAction(TestAction::make('indicatorFlag')->table(), ['code' => 'UI_LATE_NOTICE', 'subject_type' => 'claim', 'subject_id' => $claim->id])
        ->assertNotified(__('risk_transfer_actions.indicatorFlag.done'));
    expect(DB::table('risk_alerts')->where('tenant_id', $this->tenant)->where('subject_id', $claim->id)->count())->toBe(1);
});

it('syncs a carrier record mapping and resolves the resulting conflict', function () {
    $client = IntegrationClient::create(['name' => 'Carrier link', 'client_id' => 'car-'.Str::random(6), 'status' => 'ACTIVE', 'scopes' => [], 'environment' => 'production',
        'rate_limit_per_minute' => 60, 'sandbox_rate_limit_per_minute' => 30]);
    rtAs(rtUser($this->tenant, ['integrations.carrier_connectors.manage']), $this->tenant);
    $carrier = $this->f['carrier']->id;
    Livewire::test(CarrierConnectorMessages::class)->callAction(TestAction::make('connectorConfigure')->table(), ['carrier_id' => $carrier, 'transport' => 'MANUAL', 'integration_client_id' => $client->id]);
    $record = (string) Str::uuid();
    Livewire::test(CarrierConnectorMessages::class)->callAction(TestAction::make('connectorSync')->table(), ['carrier_id' => $carrier, 'record_type' => 'policy', 'external_record_id' => 'EXT-1',
        'opesinsure_record_id' => $record, 'external_version' => 'v1'])->assertNotified(__('risk_transfer_actions.connectorSync.done'));
    $mapping = DB::table('external_record_mappings')->where('external_record_id', 'EXT-1')->first();
    expect($mapping)->not->toBeNull();

    Livewire::test(CarrierConnectorMessages::class)->callAction(TestAction::make('connectorSync')->table(), ['carrier_id' => $carrier, 'record_type' => 'policy', 'external_record_id' => 'EXT-1',
        'opesinsure_record_id' => $record, 'external_version' => 'v2']);
    // OpesInsure owns the record: a newer external version is a conflict for a human, never an overwrite.
    expect(DB::table('external_record_mappings')->where('id', $mapping->id)->value('synchronization_status'))->toBe('CONFLICT');
    Livewire::test(CarrierConnectorMessages::class)->callAction(TestAction::make('connectorResolveConflict')->table(), ['mapping_id' => $mapping->id, 'resolution' => 'KEEP_OPESINSURE', 'note' => 'Ours is authoritative'])
        ->assertNotified(__('risk_transfer_actions.connectorResolveConflict.done'));
    expect(DB::table('external_record_mappings')->where('id', $mapping->id)->value('synchronization_status'))->toBe('SYNCED');
});

it('runs a stateless eligibility check for rules.evaluate only', function () {
    rtAs(rtUser($this->tenant, ['quotes.rate']), $this->tenant);
    Livewire::test(InsuranceChecks::class)->assertActionHidden(TestAction::make('checkEligibility')->table())->assertActionVisible(TestAction::make('checkRate')->table());
    rtAs(rtUser($this->tenant, ['rules.evaluate']), $this->tenant);
    Livewire::test(InsuranceChecks::class)->callAction(TestAction::make('checkEligibility')->table(), ['insurance_product_id' => $this->f['product']->id, 'facts' => ['driver_age' => '30']])
        ->assertNotified(__('risk_transfer_actions.checkEligibility.done'));
});
