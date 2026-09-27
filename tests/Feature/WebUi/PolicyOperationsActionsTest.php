<?php

declare(strict_types=1);

use App\Application\Certificates\CertificateService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\PolicyOperationsActions;
use App\Filament\Shared\Actions\StickerCustodyActions;
use App\Models\Policy;
use App\Models\StickerStock;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function poHarness(array $actions, ?object $record = null)
{
    WorkflowActionHarness::$actions = $actions;

    return Livewire::test(WorkflowActionHarness::class, $record ? ['model' => $record::class, 'recordId' => $record->getKey()] : []);
}

function poAs(array $f, array $permissions, string $role = 'POLICY_OFFICER'): User
{
    $u = makeAuthTestUser($f['tenant'], $permissions, $role);
    test()->actingAs($u);
    app(TenantContext::class)->set($f['tenant']->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $u;
}

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    app(TenantContext::class)->set($this->f['tenant']->id);
    $this->policy = Policy::create([
        'tenant_id' => $this->f['tenant']->id, 'proposal_id' => $this->f['proposal']->id, 'carrier_id' => $this->f['carrier']->id, 'party_id' => $this->f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subMonth(),
    ]);
});

it('suspends a policy through PolicySuspensionService; hidden without policies.suspend', function () {
    poAs($this->f, ['policies.view']);
    poHarness([fn () => PolicyOperationsActions::suspend()], $this->policy)->assertActionHidden('policySuspend');

    poAs($this->f, ['policies.suspend']);
    poHarness([fn () => PolicyOperationsActions::suspend()], $this->policy)->callAction('policySuspend', ['reason_code' => 'CUSTOMER_REQUEST'])
        ->assertNotified(__('workflow_actions.policySuspend.done'));
    expect($this->policy->refresh()->status)->toBe('SUSPENDED')
        ->and(DB::table('policy_suspensions')->where('policy_id', $this->policy->id)->value('source'))->toBe('MANUAL');
});

it('replaces beneficiaries through BeneficiaryService; refusal shown; hidden without beneficiaries.manage', function () {
    poAs($this->f, ['policies.view']);
    poHarness([fn () => PolicyOperationsActions::replaceBeneficiaries()], $this->policy)->assertActionHidden('policyReplaceBeneficiaries');

    poAs($this->f, ['beneficiaries.manage']);
    poHarness([fn () => PolicyOperationsActions::replaceBeneficiaries()], $this->policy)->callAction('policyReplaceBeneficiaries', [
        'beneficiaries' => [['designation' => 'PRIMARY', 'full_name' => 'Ada Mbarga', 'relationship' => 'SPOUSE', 'allocation_pct' => 60, 'revocable' => true]],
        'reason' => 'Customer request',
    ])->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('beneficiary_designations')->where('policy_id', $this->policy->id)->count())->toBe(0);

    poHarness([fn () => PolicyOperationsActions::replaceBeneficiaries()], $this->policy)->callAction('policyReplaceBeneficiaries', [
        'beneficiaries' => [['designation' => 'PRIMARY', 'full_name' => 'Ada Mbarga', 'relationship' => 'SPOUSE', 'allocation_pct' => 100, 'revocable' => true]],
        'reason' => 'Customer request',
    ])->assertNotified(__('workflow_actions.policyReplaceBeneficiaries.done'));
    expect(DB::table('beneficiary_designations')->where(['policy_id' => $this->policy->id, 'status' => 'ACTIVE'])->value('full_name'))->toBe('Ada Mbarga');
});

it('records premium components through PremiumComponentService; hidden without premium_components.manage', function () {
    poAs($this->f, ['policies.view']);
    poHarness([fn () => PolicyOperationsActions::premiumComponents()], $this->policy)->assertActionHidden('policyPremiumComponents');

    poAs($this->f, ['premium_components.manage']);
    poHarness([fn () => PolicyOperationsActions::premiumComponents()], $this->policy)->callAction('policyPremiumComponents', [
        'from_terms' => false, 'components' => [['line_key' => 'MOTOR', 'component' => 'NET_PREMIUM', 'amount_minor' => 90000], ['line_key' => 'MOTOR_TAX', 'component' => 'TAX', 'amount_minor' => 10000]],
    ])->assertNotified(__('workflow_actions.policyPremiumComponents.done'));
    expect(DB::table('premium_components')->where('policy_id', $this->policy->id)->count())->toBeGreaterThan(0);
});

it('creates a special profile, adds a schedule item and declares cargo; each hidden without its permission', function () {
    poAs($this->f, ['policies.view']);
    poHarness([fn () => PolicyOperationsActions::specialProfile()], $this->policy)->assertActionHidden('policySpecialProfile');
    poHarness([fn () => PolicyOperationsActions::declareCargo()], $this->policy)->assertActionHidden('policyDeclareCargo');

    poAs($this->f, ['special_policies.manage']);
    poHarness([fn () => PolicyOperationsActions::specialProfile()], $this->policy)->callAction('policySpecialProfile', [
        'kind' => 'OPEN_COVER', 'terms' => ['per_shipment_limit_minor' => '5000000', 'rate_bps' => '50', 'conveyances' => 'SEA,AIR'],
    ])->assertNotified(__('workflow_actions.policySpecialProfile.done'));
    expect(DB::table('special_policy_profiles')->where('policy_id', $this->policy->id)->value('kind'))->toBe('OPEN_COVER');

    poAs($this->f, ['cargo_declarations.declare']);
    poHarness([fn () => PolicyOperationsActions::declareCargo()], $this->policy)->callAction('policyDeclareCargo', [
        'conveyance' => 'SEA', 'goods_description' => 'Cocoa beans, 40 bags', 'origin' => 'Douala', 'destination' => 'Antwerp',
        'shipment_date' => now()->toDateString(), 'insured_value_minor' => 1000000,
    ])->assertNotified(__('workflow_actions.policyDeclareCargo.done'));
    expect(DB::table('cargo_declarations')->where('policy_id', $this->policy->id)->count())->toBe(1);

    poAs($this->f, ['policies.view']);
    poHarness([fn () => PolicyOperationsActions::addScheduleItem()], $this->policy)->assertActionHidden('policyAddScheduleItem');
});

it('adds a fleet schedule item through PolicyScheduleService', function () {
    DB::table('special_policy_profiles')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->f['tenant']->id, 'policy_id' => $this->policy->id, 'kind' => 'FLEET',
        'terms' => '{}', 'status' => 'ACTIVE', 'created_by' => makeAuthTestUser($this->f['tenant'], [])->id, 'created_at' => now(), 'updated_at' => now()]);
    poAs($this->f, ['special_policies.schedule.manage']);
    poHarness([fn () => PolicyOperationsActions::addScheduleItem()], $this->policy)->callAction('policyAddScheduleItem', [
        'item_type' => 'FLEET_VEHICLE', 'item_key' => 'LT-001-AA', 'display_name' => 'Toyota Hilux', 'category' => 'VEHICLE', 'effective_from' => now()->toDateString(), 'sum_insured_minor' => '12000000',
    ])->assertNotified(__('workflow_actions.policyAddScheduleItem.done'));
    expect(DB::table('policy_schedule_items')->where('policy_id', $this->policy->id)->value('item_key'))->toBe('LT-001-AA');
});

it('enrols a health member and links a HEALTH network through HealthMemberService; hidden without health.members.manage', function () {
    poAs($this->f, ['policies.view']);
    poHarness([fn () => PolicyOperationsActions::enrolHealthMember()], $this->policy)->assertActionHidden('policyEnrolHealthMember');
    poHarness([fn () => PolicyOperationsActions::linkHealthNetwork()], $this->policy)->assertActionHidden('policyLinkHealthNetwork');

    poAs($this->f, ['health.members.manage']);
    poHarness([fn () => PolicyOperationsActions::enrolHealthMember()], $this->policy)->callAction('policyEnrolHealthMember', ['relationship' => 'PRINCIPAL', 'display_name' => 'Jean Principal'])
        ->assertNotified(__('workflow_actions.policyEnrolHealthMember.done'));
    expect(DB::table('health_members')->where(['policy_id' => $this->policy->id, 'relationship' => 'PRINCIPAL'])->count())->toBe(1);

    $net = (string) Str::uuid();
    DB::table('provider_networks')->insert(['id' => $net, 'tenant_id' => $this->f['tenant']->id, 'carrier_id' => $this->f['carrier']->id, 'code' => 'NET-'.Str::random(4), 'name' => 'Douala network',
        'network_type_code' => 'PREFERRED', 'category' => 'HEALTH', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    poHarness([fn () => PolicyOperationsActions::linkHealthNetwork()], $this->policy)->callAction('policyLinkHealthNetwork', ['provider_network_id' => $net])
        ->assertNotified(__('workflow_actions.policyLinkHealthNetwork.done'));
    expect(DB::table('health_policy_networks')->where(['policy_id' => $this->policy->id, 'provider_network_id' => $net])->exists())->toBeTrue();
});

it('runs a surrender quote through LifeSurrenderService (refusal shown as a notification); hidden without life_surrender.quote', function () {
    poAs($this->f, ['policies.view']);
    poHarness([fn () => PolicyOperationsActions::surrenderQuote()], $this->policy)->assertActionHidden('policySurrenderQuote');

    poAs($this->f, ['life_surrender.quote']);
    // A motor policy has no active surrender scale: the service refuses and the user sees why.
    poHarness([fn () => PolicyOperationsActions::surrenderQuote()], $this->policy)->callAction('policySurrenderQuote', ['basis_minor' => 500000])
        ->assertNotified(__('workflow_actions.failed'));
});

it('decides a portfolio transfer (checker rejects) and records consent; hidden without the permissions', function () {
    $maker = makeAuthTestUser($this->f['tenant'], ['policies.portfolio_transfer.request']);
    $mk = function (string $status, string $itemStatus) use ($maker) {
        $id = (string) Str::uuid();
        DB::table('policy_portfolio_transfers')->insert(['id' => $id, 'tenant_id' => $this->f['tenant']->id, 'scope' => 'CARRIER', 'from_id' => $this->f['carrier']->id, 'to_id' => (string) Str::uuid(),
            'status' => $status, 'reason_code' => 'MERGER', 'notice_mode' => 'CONSENT', 'effective_at' => now(), 'policy_ids' => json_encode([$this->policy->id]), 'policy_count' => 1,
            'preview_hash' => str_repeat('a', 64), 'requested_by' => $maker->id, 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('policy_portfolio_transfer_items')->insert(['id' => (string) Str::uuid(), 'transfer_id' => $id, 'policy_id' => $this->policy->id, 'party_id' => $this->f['party']->id,
            'from_id' => $this->f['carrier']->id, 'status' => $itemStatus, 'consent_status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $pending = $mk('PENDING_APPROVAL', 'PENDING');

    poAs($this->f, ['policies.portfolio_transfer.request']);
    poHarness([fn () => PolicyOperationsActions::transferDecide()], $this->policy)->assertActionHidden('policyTransferDecide');
    poAs($this->f, ['policies.portfolio_transfer.approve']);
    poHarness([fn () => PolicyOperationsActions::transferDecide()], $this->policy)->callAction('policyTransferDecide', ['transfer_id' => $pending, 'outcome' => 'REJECT', 'notes' => 'Wrong target book'])
        ->assertNotified(__('workflow_actions.policyTransferDecide.done'));
    expect(DB::table('policy_portfolio_transfers')->where('id', $pending)->value('status'))->toBe('REJECTED');

    $awaiting = $mk('AWAITING_CONSENT', 'AWAITING_CONSENT');
    poAs($this->f, ['policies.view']);
    poHarness([fn () => PolicyOperationsActions::transferConsent()], $this->policy)->assertActionHidden('policyTransferConsent');
    poAs($this->f, ['policies.portfolio_transfer.request']);
    poHarness([fn () => PolicyOperationsActions::transferConsent()], $this->policy)->callAction('policyTransferConsent', ['transfer_id' => $awaiting, 'granted' => false, 'evidence' => 'Signed refusal letter'])
        ->assertNotified(__('workflow_actions.policyTransferConsent.done'));
    expect(DB::table('policy_portfolio_transfer_items')->where('transfer_id', $awaiting)->value('consent_status'))->toBe('REFUSED');
});

it('settles and waives premium instalments and decides a recovery case through PolicyRecoveryService; hidden without the permissions', function () {
    $inst = fn (string $due) => tap((string) Str::uuid(), fn ($id) => DB::table('policy_premium_instalments')->insert(['id' => $id, 'tenant_id' => $this->f['tenant']->id, 'policy_id' => $this->policy->id,
        'sequence' => ++$this->seq, 'due_date' => $due, 'amount_minor' => 50000, 'paid_minor' => 0, 'currency' => 'XAF', 'status' => 'OVERDUE', 'created_at' => now(), 'updated_at' => now()]));
    $this->seq = 0;
    $a = $inst(now()->subMonth()->toDateString());
    $b = $inst(now()->toDateString());

    poAs($this->f, ['policies.view']);
    poHarness([fn () => PolicyOperationsActions::settleInstalment()], $this->policy)->assertActionHidden('policySettleInstalment');
    poHarness([fn () => PolicyOperationsActions::waiveInstalment()], $this->policy)->assertActionHidden('policyWaiveInstalment');

    poAs($this->f, ['policy.recovery.request']);
    poHarness([fn () => PolicyOperationsActions::settleInstalment()], $this->policy)->callAction('policySettleInstalment', ['instalment_id' => $a, 'amount_minor' => 50000])
        ->assertNotified(__('workflow_actions.policySettleInstalment.done'));
    expect(DB::table('policy_premium_instalments')->where('id', $a)->value('status'))->toBe('PAID');

    poAs($this->f, ['policy.premium.waive']);
    poHarness([fn () => PolicyOperationsActions::waiveInstalment()], $this->policy)->callAction('policyWaiveInstalment', ['instalment_id' => $b, 'reason' => 'Commercial gesture'])
        ->assertNotified(__('workflow_actions.policyWaiveInstalment.done'));
    expect(DB::table('policy_premium_instalments')->where('id', $b)->value('status'))->toBe('WAIVED');

    $this->policy->update(['status' => 'LAPSED']);
    $case = (string) Str::uuid();
    DB::table('policy_recovery_cases')->insert(['id' => $case, 'tenant_id' => $this->f['tenant']->id, 'policy_id' => $this->policy->id, 'case_number' => 'REC-'.Str::random(5), 'status' => 'OPEN',
        'policy_status_at_open' => 'LAPSED', 'reason_code' => 'ARREARS', 'arrears_minor' => 0, 'currency' => 'XAF', 'requested_by' => makeAuthTestUser($this->f['tenant'], [])->id,
        'created_at' => now(), 'updated_at' => now()]);
    poAs($this->f, ['policy.recovery.request']);
    poHarness([fn () => PolicyOperationsActions::recoveryDecide()], $this->policy->refresh())->assertActionHidden('policyRecoveryDecide');
    poAs($this->f, ['policy.recovery.approve']);
    poHarness([fn () => PolicyOperationsActions::recoveryDecide()], $this->policy)->callAction('policyRecoveryDecide', ['case_id' => $case, 'outcome' => 'REJECT', 'reason' => 'Arrears not cleared'])
        ->assertNotified(__('workflow_actions.policyRecoveryDecide.done'));
    expect(DB::table('policy_recovery_cases')->where('id', $case)->value('status'))->toBe('REJECTED');
});

it('hands stickers over the custody chain, accepts, assigns to a policy and reconciles; each hidden without its permission', function () {
    $f = $this->f;
    $carrierAdmin = makeAuthTestUser($f['tenant'], ['stickers.handover', 'stickers.allocate', 'stickers.allocate.carrier', 'stickers.reconcile', 'stickers.assign'], 'CARRIER_ADMIN');
    app(CertificateService::class)->receiveBatch(['carrier_id' => $f['carrier']->id, 'batch_number' => 'B-'.Str::random(6),
        'stickers' => array_map(fn ($i) => ['serial_number' => "STK-{$i}", 'security_code' => Str::random(24)], range(1, 3))], $carrierAdmin);

    poAs($f, ['stickers.view']);
    poHarness([fn () => StickerCustodyActions::handover()])->assertActionHidden('stickerHandover');
    poHarness([fn () => StickerCustodyActions::reconcile()])->assertActionHidden('stickerReconcile');

    // Without stickers.allocate.carrier, releasing carrier stock is refused (same check as the controller).
    poAs($f, ['stickers.handover', 'stickers.allocate']);
    poHarness([fn () => StickerCustodyActions::handover()])->callAction('stickerHandover', ['carrier_id' => $f['carrier']->id, 'from_level' => 'CARRIER', 'to_level' => 'BROKER', 'serial_numbers' => "STK-1\nSTK-2"])
        ->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('sticker_handovers')->count())->toBe(0);

    test()->actingAs($carrierAdmin);
    poHarness([fn () => StickerCustodyActions::handover()])->callAction('stickerHandover', ['carrier_id' => $f['carrier']->id, 'from_level' => 'CARRIER', 'to_level' => 'BROKER', 'serial_numbers' => "STK-1\nSTK-2"])
        ->assertNotified(__('workflow_actions.stickerHandover.done'));
    $ho = DB::table('sticker_handovers')->value('id');

    $broker = poAs($f, ['stickers.handover', 'stickers.allocate', 'stickers.assign', 'stickers.reconcile'], 'BROKER_ADMIN');
    poHarness([fn () => StickerCustodyActions::decideHandover()])->callAction('stickerHandoverDecide', ['handover_id' => $ho, 'outcome' => 'ACCEPT'])
        ->assertNotified(__('workflow_actions.stickerHandoverDecide.done'));
    expect(StickerStock::where(['custody_level' => 'BROKER', 'status' => 'IN_STOCK'])->count())->toBe(2);

    poHarness([fn () => PolicyOperationsActions::assignSticker()], $this->policy)->callAction('policyAssignSticker', ['serial_number' => 'STK-1'])
        ->assertNotified(__('workflow_actions.policyAssignSticker.done'));
    expect(StickerStock::where('serial_number', 'STK-1')->value('assigned_policy_id'))->toBe($this->policy->id);

    poHarness([fn () => StickerCustodyActions::reconcile()])->callAction('stickerReconcile', ['carrier_id' => $f['carrier']->id, 'holder_level' => 'BROKER', 'counted_serials' => 'STK-2'])
        ->assertNotified(__('workflow_actions.stickerReconcile.done'));
    expect(DB::table('sticker_reconciliations')->count())->toBe(1);

    poAs($f, ['stickers.view']);
    poHarness([fn () => PolicyOperationsActions::assignSticker()], $this->policy)->assertActionHidden('policyAssignSticker');
});

it('mounts the policy operations group on ViewPolicy (shared by the admin, insurer and broker panels) and the servicing context tab on the policy record', function () {
    $page = new \App\Filament\Admin\Resources\Policies\Pages\ViewPolicy;
    $page->record = $this->policy;
    $m = new ReflectionMethod($page, 'getHeaderActions');
    $labels = collect($m->invoke($page))->map(fn ($a) => $a->getLabel())->all();
    expect($labels)->toContain(__('workflow_actions.policy_operations_group'))
        ->and(collect(PolicyOperationsActions::all())->map->getName()->all())->toHaveCount(15)
        ->and(PolicyServicingContextProbe::tabLabel())->toBe(__('workflow_actions.policy_context.tab'))
        ->and(file_get_contents(app_path('Filament/Admin/Resources/Policies/PolicyResource.php')))->toContain('PolicyServicingContext::tab()')
        ->and(file_get_contents(app_path('Filament/Admin/Resources/StickerInventory/Pages/ListStickerInventory.php')))->toContain('StickerCustodyActions::all()');
    app()->setLocale('fr');
    expect(__('workflow_actions.policySuspend.label'))->toBe('Suspendre le contrat')->and(__('workflow_actions.policy_context.tab'))->toBe('Gestion');
    app()->setLocale('en');
});

final class PolicyServicingContextProbe
{
    public static function tabLabel(): string
    {
        return (string) \App\Filament\Shared\Components\PolicyServicingContext::tab()->getLabel();
    }
}
