<?php

declare(strict_types=1);

use App\Application\Identity\BranchStamp;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Pages\Branch\BranchScreen;
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

/**
 * S2 2026-09-29 — branch scoping: quotes / proposals / policies / claims / renewal cases / commission accruals carry
 * branch_id stamped at creation, and the BRANCH_MANAGER screens BRM-002/003/006..009/011/014..016 in /broker show the
 * manager's own branch only (another branch is invisible), gated on the API permission, EN + FR.
 */
const BMS_PERMS = ['policies.read', 'customers.read', 'quotes.read', 'renewals.manage', 'claims.view', 'commission.read', 'cashier.sessions.approve'];

function bmsUser(string $tenantId, string $role, ?string $branchId, array $permissions = BMS_PERMS, string $name = ''): User
{
    $u = User::create(['full_name' => $name ?: 'BMS '.Str::random(5), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'branch_id' => $branchId]);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'BMS-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

/** A full sales chain sold by $seller, with the customer named $customer. Only quotes get branch_id explicitly (as QuoteService does). */
function bmsChain(string $tenantId, User $seller, string $customer): array
{
    $now = now();
    $party = (string) Str::uuid();
    DB::table('parties')->insert(['id' => $party, 'type' => 'PERSON', 'display_name' => $customer, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
    $cParty = (string) Str::uuid();
    DB::table('parties')->insert(['id' => $cParty, 'type' => 'ORGANIZATION', 'display_name' => 'Carrier '.$customer, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
    $carrier = (string) Str::uuid();
    DB::table('carriers')->insert(['id' => $carrier, 'party_id' => $cParty, 'cima_code' => 'C'.Str::random(6), 'created_at' => $now, 'updated_at' => $now]);
    $product = (string) Str::uuid();
    DB::table('insurance_products')->insert(['id' => $product, 'carrier_id' => $carrier, 'line_code' => 'AUTO', 'code' => 'P'.Str::random(5), 'name' => 'Motor', 'version' => 1, 'effective_from' => $now->toDateString()]);
    $quote = (string) Str::uuid();
    DB::table('quotes')->insert(['id' => $quote, 'tenant_id' => $tenantId, 'party_id' => $party, 'line_code' => 'AUTO', 'status' => 'SUBMITTED', 'lifecycle_state' => 'DRAFT', 'risk_facts' => '{}',
        'quote_number' => 'Q-'.Str::random(6), 'currency' => 'XAF', 'branch_id' => BranchStamp::of($seller, $tenantId), 'created_at' => $now, 'updated_at' => $now]);
    $offer = (string) Str::uuid();
    DB::table('quote_offers')->insert(['id' => $offer, 'quote_id' => $quote, 'carrier_id' => $carrier, 'product_id' => $product, 'premium_minor' => 50000, 'total_minor' => 50000, 'currency' => 'XAF',
        'status' => 'ACCEPTED', 'origin' => 'MIGRATED', 'calculation_breakdown' => '{}', 'valid_until' => $now->copy()->addDays(7), 'created_at' => $now, 'updated_at' => $now]);
    $proposal = (string) Str::uuid();
    DB::table('proposals')->insert(['id' => $proposal, 'tenant_id' => $tenantId, 'quote_offer_id' => $offer, 'party_id' => $party, 'status' => 'APPROVED', 'created_by' => $seller->id, 'created_at' => $now, 'updated_at' => $now]);
    $policy = (string) Str::uuid();
    DB::table('policies')->insert(['id' => $policy, 'tenant_id' => $tenantId, 'proposal_id' => $proposal, 'carrier_id' => $carrier, 'party_id' => $party, 'status' => 'ACTIVE',
        'policy_number' => $policyNumber = 'POL-'.Str::random(6), 'premium_minor' => 50000, 'currency' => 'XAF', 'coverage_starts_at' => $now, 'coverage_ends_at' => $now->copy()->addDays(20), 'terms_snapshot' => '{}', 'issued_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $claim = (string) Str::uuid();
    DB::table('claims')->insert(['id' => $claim, 'tenant_id' => $tenantId, 'policy_id' => $policy, 'claim_number' => 'CLM-'.Str::random(6), 'status' => 'SUBMITTED', 'loss_occurred_at' => $now, 'loss_details' => '{}', 'created_at' => $now, 'updated_at' => $now]);
    $renewal = (string) Str::uuid();
    DB::table('renewal_cases')->insert(['id' => $renewal, 'tenant_id' => $tenantId, 'policy_id' => $policy, 'due_on' => $now->copy()->addDays(20)->toDateString(), 'status' => 'DUE', 'created_at' => $now, 'updated_at' => $now]);
    $accrual = (string) Str::uuid();
    DB::table('commission_accruals')->insert(['id' => $accrual, 'tenant_id' => $tenantId, 'policy_id' => $policy, 'rule_version' => 'v1', 'amount_minor' => 5000, 'currency' => 'XAF', 'status' => 'ACCRUED', 'created_at' => $now, 'updated_at' => $now]);

    return compact('policyNumber', 'quote', 'proposal', 'policy', 'claim', 'renewal', 'accrual');
}

function bmsAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('broker'));
}

beforeEach(function () {
    $this->tenant = Tenant::create(['type' => 'BROKER', 'legal_name' => 'BMS '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []])->id;
    $this->branchA = (string) Str::uuid();
    $this->branchB = (string) Str::uuid();
    foreach ([$this->branchA => 'Akwa', $this->branchB => 'Bastos'] as $id => $name) {
        DB::table('tenant_branches')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'code' => 'BR-'.Str::random(4), 'name' => $name, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->manager = bmsUser($this->tenant, 'BRANCH_MANAGER', $this->branchA);
    $this->sellerA = bmsUser($this->tenant, 'BROKER_STAFF', $this->branchA, [], 'Seller Alpha');
    $this->sellerB = bmsUser($this->tenant, 'BROKER_STAFF', $this->branchB, [], 'Seller Beta');
    $this->a = bmsChain($this->tenant, $this->sellerA, 'Customer Alpha');
    $this->b = bmsChain($this->tenant, $this->sellerB, 'Customer Beta');
    foreach ([[$this->branchA, $this->sellerA], [$this->branchB, $this->sellerB]] as [$branch, $cashier]) {
        DB::table('cashier_sessions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'branch_id' => $branch, 'cashier_user_id' => $cashier->id, 'currency' => 'XAF',
            'opening_float_minor' => 0, 'opened_at' => now()->subHours(8), 'status' => 'CLOSED', 'closed_at' => now(), 'counted_cash_minor' => 1000, 'variance_minor' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
});

it('stamps branch_id at creation down the whole chain, from the seller membership branch', function () {
    foreach (['a' => $this->branchA, 'b' => $this->branchB] as $k => $branch) {
        $ids = $this->{$k};
        foreach (['quote' => 'quotes', 'proposal' => 'proposals', 'policy' => 'policies', 'claim' => 'claims', 'renewal' => 'renewal_cases', 'accrual' => 'commission_accruals'] as $key => $table) {
            expect(DB::table($table)->where('id', $ids[$key])->value('branch_id'))->toBe($branch, "{$table} {$k}");
        }
    }
    // A user with no branch (or two branches) is not determinable → NULL; an explicit branch_id is never overwritten.
    $floating = bmsUser($this->tenant, 'BROKER_ADMIN', null);
    expect(BranchStamp::of($floating, $this->tenant))->toBeNull();
    TenantMembership::create(['tenant_id' => $this->tenant, 'user_id' => $this->sellerA->id, 'role_code' => 'AGENT', 'status' => 'ACTIVE', 'branch_id' => $this->branchB]);
    expect(BranchStamp::of($this->sellerA, $this->tenant))->toBeNull();
    DB::table('claims')->where('id', $this->b['claim'])->delete();
    DB::table('claims')->insert(['id' => $id = (string) Str::uuid(), 'tenant_id' => $this->tenant, 'policy_id' => $this->b['policy'], 'claim_number' => 'X-1', 'status' => 'SUBMITTED',
        'loss_occurred_at' => now(), 'loss_details' => '{}', 'branch_id' => $this->branchA, 'created_at' => now(), 'updated_at' => now()]);
    expect(DB::table('claims')->where('id', $id)->value('branch_id'))->toBe($this->branchA);
});

it('shows every BRM screen with the own branch only; the other branch is invisible', function () {
    bmsAs($this->manager, $this->tenant);
    foreach (BranchScreen::SCREENS as $id => $page) {
        expect($page::canAccess())->toBeTrue($id);
        $lw = Livewire::test($page)->assertOk()->assertSet('branchId', $this->branchA);
        if (in_array($id, ['BRM-002'], true)) {
            continue;
        }
        [$see, $hide] = match ($id) {
            'BRM-014', 'BRM-016' => ['Seller Alpha', 'Seller Beta'],
            'BRM-011' => [$this->a['policyNumber'], $this->b['policyNumber']],
            default => ['Customer Alpha', 'Customer Beta'],
        };
        $lw->assertSee($see)->assertDontSee($hide);
    }
    $production = Livewire::test(BranchScreen::SCREENS['BRM-002'])->instance();
    expect($production->kpis()[0]['value'])->toBe(1);
    expect(Livewire::test(BranchScreen::SCREENS['BRM-007'])->instance()->kpis()[0]['value'])->toBe(1);
});

it('opens the BRM screens over HTTP in EN and FR and refuses them without the role, branch or API permission', function () {
    $staff = bmsUser($this->tenant, 'BROKER_STAFF', $this->branchA);
    $noBranch = bmsUser($this->tenant, 'BRANCH_MANAGER', null);
    foreach (BranchScreen::SCREENS as $id => $page) {
        $url = '/broker/'.(new ReflectionProperty($page, 'slug'))->getValue();
        foreach (['en', 'fr'] as $lang) {
            $this->flushSession();
            auth()->forgetGuards();
            bmsAs($this->manager, $this->tenant);
            $html = $this->get($url.'?lang='.$lang)->assertOk()->getContent();
            expect($html)->not->toContain('branch_screens.', "{$id} {$lang} raw key")
                ->and($html)->toContain(e(trans('branch_screens.nav.'.$page::KEY, [], $lang)));
        }
        $lacking = bmsUser($this->tenant, 'BRANCH_MANAGER', $this->branchA, array_values(array_diff(BMS_PERMS, [$page::PERMISSION])));
        foreach ([$staff, $noBranch, $lacking] as $who) {
            $this->flushSession();
            auth()->forgetGuards();
            bmsAs($who, $this->tenant);
            expect($this->get($url)->status())->toBeIn([403, 404], $id);
        }
    }
});
