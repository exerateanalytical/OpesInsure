<?php

declare(strict_types=1);

/**
 * S5 (insurer E2E review, 2026-09-29): two insurers in ONE organisation never see or act on each other's reinsurers,
 * treaties, facultative slips or co-insurance arrangements — in /insurer (lists, record actions) and over the API
 * (carrier-linked users). NULL carrier rows (tenant-wide legacy) are visible to tenant-wide users only.
 */

use App\Application\Reinsurance\RiskTransferCarrierScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\RiskTransfer\{CoinsuranceArrangements, FacultativePlacements, ReinsuranceTreaties, Reinsurers};
use App\Models\{Carrier, Party, Policy, Role, Tenant, TenantMembership, User};
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

const S5_PERMS = ['reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.reinsurers.manage', 'reinsurance.treaties.approve',
    'reinsurance.cessions.view', 'reinsurance.facultative.view', 'reinsurance.facultative.manage', 'coinsurance.view', 'coinsurance.manage', 'coinsurance.approve'];

function s5User(Tenant $tenant, ?string $carrierId, string $role = 'REINSURANCE_OFFICER'): User
{
    $u = User::create(['full_name' => 'S5 '.Str::random(5), 'email' => Str::lower(Str::random(10)).'@s5.test', 'phone_e164' => '+2376'.random_int(10000000, 99999999),
        'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->attach(Role::create(['tenant_id' => $tenant->id, 'code' => 'S5-'.Str::random(8), 'permissions' => S5_PERMS, 'is_system' => false])->id);

    return $u;
}

function s5Panel(User $u, string $tenantId): void
{
    test()->actingAs($u);
    Filament::setCurrentPanel(Filament::getPanel('insurer'));
    app(TenantContext::class)->set($tenantId);
    \App\Application\Identity\Rbac\RequestMemo::flush();
}

function s5Policy(array $f, string $carrierId): Policy
{
    return Policy::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $carrierId, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => '2026-11-01', 'coverage_ends_at' => '2027-10-31',
        'terms_snapshot' => ['line_code' => 'PROPERTY', 'sum_insured_minor' => 100_000_000], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 1_000_000, 'issued_at' => now()]);
}

beforeEach(function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->f = $f;
    $this->tenant = $f['tenant'];
    $this->h = ['X-Tenant-Id' => $this->tenant->id];
    $this->a = $f['carrier'];
    $this->b = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'S5 Other', 'status' => 'ACTIVE'])->id,
        'cima_code' => 'S5-'.Str::random(6), 'status' => 'ACTIVE']);
    $this->ua = s5User($this->tenant, $this->a->id);
    $this->ub = s5User($this->tenant, $this->b->id);
    $this->admin = makeAuthTestUser($this->tenant, S5_PERMS);

    // Carrier B's book, created by B's own user over the API (carrier_id stamped from the caller).
    Passport::actingAs($this->ub, [], 'api');
    $this->bRe = $this->postJson('/api/v1/reinsurance/reinsurers', ['code' => 'B-RE', 'name' => 'B Re'], $this->h)->assertCreated()->json('data');
    $this->bTreaty = $this->postJson('/api/v1/reinsurance/treaties', ['code' => 'B-QS', 'name' => 'B quota share', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF'], $this->h)
        ->assertCreated()->json('data');
    $this->bPolicy = s5Policy($f, $this->b->id);
    $this->bFac = $this->postJson('/api/v1/reinsurance/facultative', ['policy_id' => $this->bPolicy->id, 'risk_description' => 'B plant', 'placed_share_percent' => 50,
        'period_from' => '2026-11-01', 'period_to' => '2027-10-31', 'participants' => [['reinsurer_id' => $this->bRe['id'], 'offered_percent' => 100]]], $this->h)->assertCreated()->json('data');
    $this->bCo = $this->postJson('/api/v1/coinsurance/arrangements', ['reference' => 'B-CO', 'policy_id' => $this->bPolicy->id, 'effective_from' => '2026-11-01',
        'participants' => [['carrier_id' => $this->b->id, 'role' => 'LEAD', 'share_bps' => 6000], ['carrier_id' => $this->a->id, 'role' => 'FOLLOWER', 'share_bps' => 4000]]], $this->h)
        ->assertCreated()->json('data');
    // Tenant-wide legacy row (no carrier).
    DB::table('reinsurance_treaties')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'code' => 'LEGACY-QS', 'name' => 'Legacy', 'reinsurance_type' => 'TREATY',
        'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF', 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);
});

it('stamps carrier_id from the acting user on create', function () {
    expect($this->bRe['carrier_id'])->toBe($this->b->id)
        ->and($this->bTreaty['carrier_id'])->toBe($this->b->id)
        ->and(DB::table('facultative_placements')->where('id', $this->bFac['id'])->value('carrier_id'))->toBe($this->b->id)
        ->and(DB::table('coinsurance_arrangements')->where('id', $this->bCo['id'])->value('carrier_id'))->toBe($this->b->id);

    // A carrier user cannot stamp another carrier; platform staff may name one explicitly.
    Passport::actingAs($this->ua, [], 'api');
    $this->postJson('/api/v1/reinsurance/treaties', ['code' => 'X', 'name' => 'X', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF', 'carrier_id' => $this->b->id], $this->h)
        ->assertUnprocessable();
    Passport::actingAs($this->admin, [], 'api');
    expect($this->postJson('/api/v1/reinsurance/treaties', ['code' => 'P', 'name' => 'P', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF', 'carrier_id' => $this->a->id], $this->h)
        ->assertCreated()->json('data.carrier_id'))->toBe($this->a->id);
});

it('isolates the API: lists, views and actions of another carrier are hidden / 404', function () {
    Passport::actingAs($this->ua, [], 'api');
    expect(collect($this->getJson('/api/v1/reinsurance/treaties', $this->h)->assertOk()->json('data'))->pluck('code')->all())->toBe([])
        ->and($this->getJson('/api/v1/reinsurance/reinsurers', $this->h)->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/reinsurance/directory', $this->h)->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/reinsurance/facultative', $this->h)->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/coinsurance/arrangements', $this->h)->assertOk()->json('data'))->toBe([]);

    $this->getJson("/api/v1/reinsurance/treaties/{$this->bTreaty['id']}", $this->h)->assertNotFound();
    $this->getJson("/api/v1/reinsurance/facultative/{$this->bFac['id']}", $this->h)->assertNotFound();
    $this->getJson("/api/v1/coinsurance/arrangements/{$this->bCo['id']}", $this->h)->assertNotFound();
    $this->postJson("/api/v1/reinsurance/reinsurers/{$this->bRe['id']}/status", ['status' => 'SUSPENDED', 'reason' => 'x'], $this->h)->assertNotFound();
    $this->postJson("/api/v1/reinsurance/treaties/{$this->bTreaty['id']}/versions", ['effective_from' => '2026-01-01', 'cession_percent' => 10,
        'participants' => [['reinsurer_id' => $this->bRe['id'], 'share_percent' => 100]]], $this->h)->assertNotFound();
    $this->postJson("/api/v1/reinsurance/treaties/{$this->bTreaty['id']}/large-loss-threshold", ['threshold_minor' => 5, 'reason' => 'x'], $this->h)->assertNotFound();
    $this->postJson("/api/v1/reinsurance/facultative/{$this->bFac['id']}/submit", [], $this->h)->assertNotFound();
    $this->postJson("/api/v1/coinsurance/arrangements/{$this->bCo['id']}/activate", [], $this->h)->assertNotFound();
    $this->postJson("/api/v1/reinsurance/policies/{$this->bPolicy->id}/cessions/preview", [], $this->h)->assertNotFound();
    // Nor may A reference B's reinsurer or policy in its own records.
    $this->postJson('/api/v1/reinsurance/facultative', ['policy_id' => $this->bPolicy->id, 'risk_description' => 'x', 'placed_share_percent' => 50,
        'period_from' => '2026-11-01', 'period_to' => '2027-10-31', 'participants' => [['reinsurer_id' => $this->bRe['id'], 'offered_percent' => 100]]], $this->h)->assertNotFound();
    expect(DB::table('reinsurers')->where('id', $this->bRe['id'])->value('status'))->toBe('ACTIVE');

    // B still sees its own rows; the tenant-wide admin sees everything including the legacy NULL row.
    Passport::actingAs($this->ub, [], 'api');
    expect(collect($this->getJson('/api/v1/reinsurance/treaties', $this->h)->json('data'))->pluck('code')->all())->toBe(['B-QS']);
    $this->getJson("/api/v1/coinsurance/arrangements/{$this->bCo['id']}", $this->h)->assertOk();
    Passport::actingAs($this->admin, [], 'api');
    expect(collect($this->getJson('/api/v1/reinsurance/treaties', $this->h)->json('data'))->pluck('code')->sort()->values()->all())->toBe(['B-QS', 'LEGACY-QS']);
});

it('isolates the /insurer workbench lists and record actions', function () {
    s5Panel($this->ua, $this->tenant->id);
    expect(RiskTransferCarrierScope::carrier($this->tenant->id))->toBe($this->a->id);
    Livewire::test(ReinsuranceTreaties::class)->assertOk()->assertDontSee('B-QS')->assertDontSee('LEGACY-QS');
    Livewire::test(Reinsurers::class)->assertOk()->assertDontSee('B-RE');
    Livewire::test(FacultativePlacements::class)->assertOk()->assertDontSee($this->bFac['reference']);
    Livewire::test(CoinsuranceArrangements::class)->assertOk()->assertDontSee('B-CO');
    Livewire::test(\App\Filament\Shared\Pages\Registers\ReinsuranceTreatiesRegister::class)->assertOk()->assertDontSee('B quota share');
    Livewire::test(\App\Filament\Shared\Pages\Registers\CoinsuranceRegister::class)->assertOk()->assertDontSee('B-CO');

    // Even a forged call on B's treaty through the service is refused (404 inside the action).
    expect(fn () => app(\App\Application\Reinsurance\TreatyService::class)->treaty($this->tenant->id, $this->bTreaty['id']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

    // A creates its own treaty in /insurer; it is stamped with A and invisible to B.
    Livewire::test(ReinsuranceTreaties::class)
        ->callAction(TestAction::make('treatyCreate')->table(), ['code' => 'A-QS', 'name' => 'A quota share', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF'])
        ->assertNotified(__('risk_transfer_actions.treatyCreate.done'));
    expect(DB::table('reinsurance_treaties')->where('code', 'A-QS')->value('carrier_id'))->toBe($this->a->id);

    s5Panel($this->ub, $this->tenant->id);
    Livewire::test(ReinsuranceTreaties::class)->assertSee('B-QS')->assertDontSee('A-QS');
});

it('backfills carrier_id from linked policies idempotently and leaves ambiguous rows NULL', function () {
    DB::table('facultative_placements')->where('id', $this->bFac['id'])->update(['carrier_id' => null]);
    DB::table('coinsurance_arrangements')->where('id', $this->bCo['id'])->update(['carrier_id' => null]);
    DB::table('reinsurers')->where('id', $this->bRe['id'])->update(['carrier_id' => null]);
    $migration = require database_path('migrations/2026_11_11_100001_add_carrier_id_to_risk_transfer_tables.php');
    $migration->up();
    $migration->up();
    expect(DB::table('facultative_placements')->where('id', $this->bFac['id'])->value('carrier_id'))->toBe($this->b->id)
        ->and(DB::table('coinsurance_arrangements')->where('id', $this->bCo['id'])->value('carrier_id'))->toBe($this->b->id)
        ->and(DB::table('reinsurers')->where('id', $this->bRe['id'])->value('carrier_id'))->toBe($this->b->id)
        ->and(DB::table('reinsurance_treaties')->where('code', 'LEGACY-QS')->value('carrier_id'))->toBeNull();
});
