<?php

declare(strict_types=1);

/**
 * Owner decision 2026-09-27 (docs/spec/RBAC_MATRIX_BROKER_CARRIER.md §3): insurance company admins see everything about
 * their own company, and nothing of another carrier. Health queues (pre-authorizations, provider claims, settlements,
 * disputes) in /insurer and the health API are narrowed to the caller's carrier.
 */

use App\Application\Identity\RoleCatalogue;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\HealthPreauthorizationQueue;
use App\Models\{Party, Partner, Role, Tenant, TenantMembership, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function hciUser(Tenant $t, string $role, array $membership = []): User
{
    $u = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::forceCreate(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE'] + $membership);
    $r = Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

/** One carrier's health book: a policy, a pre-authorization, a provider claim in a settlement batch and a dispute. */
function hciBook(Tenant $t, string $providerId, string $contractId, string $tag): array
{
    $chain = makeMobileFinanceProposalChain($t);
    $policy = makeMobileTestPolicy($chain['proposal'], $t, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-'.$tag]);
    $now = now();
    $pa = (string) Str::uuid();
    DB::table('health_preauthorizations')->insert(['id' => $pa, 'tenant_id' => $t->id, 'carrier_id' => $policy->carrier_id, 'preauth_number' => 'PA-'.$tag, 'request_type' => 'OUTPATIENT',
        'status' => 'REQUESTED', 'policy_id' => $policy->id, 'member_ref' => 'M-'.$tag, 'provider_profile_id' => $providerId, 'service_date' => $now->toDateString(), 'currency' => 'XAF',
        'created_at' => $now, 'updated_at' => $now]);
    $batch = (string) Str::uuid();
    DB::table('health_provider_settlement_batches')->insert(['id' => $batch, 'tenant_id' => $t->id, 'batch_number' => 'HPS-'.$tag, 'provider_profile_id' => $providerId, 'currency' => 'XAF',
        'status' => 'OPEN', 'created_at' => $now, 'updated_at' => $now]);
    $claim = (string) Str::uuid();
    DB::table('health_provider_claims')->insert(['id' => $claim, 'tenant_id' => $t->id, 'claim_number' => 'HPC-'.$tag, 'provider_profile_id' => $providerId, 'provider_contract_id' => $contractId,
        'invoice_reference' => 'INV-'.$tag, 'policy_id' => $policy->id, 'service_date' => $now->toDateString(), 'currency' => 'XAF', 'status' => 'PAYABLE', 'settlement_batch_id' => $batch,
        'created_at' => $now, 'updated_at' => $now]);
    $dispute = (string) Str::uuid();
    DB::table('provider_disputes')->insert(['id' => $dispute, 'tenant_id' => $t->id, 'dispute_number' => 'DSP-'.$tag, 'provider_profile_id' => $providerId, 'subject_type' => 'CLAIM',
        'health_provider_claim_id' => $claim, 'reason_code' => 'UNDERPAID', 'description' => 'x', 'status' => 'SUBMITTED', 'created_at' => $now, 'updated_at' => $now]);

    return ['carrier_id' => (string) $policy->carrier_id, 'preauth' => $pa, 'claim' => $claim, 'batch' => $batch, 'dispute' => $dispute];
}

beforeEach(function () {
    $this->t = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'HCI '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Clinic', 'status' => 'ACTIVE']);
    $partner = Partner::create(['tenant_id' => $this->t->id, 'party_id' => $party->id, 'type' => 'PROVIDER', 'status' => 'ACTIVE']);
    $provider = (string) Str::uuid();
    DB::table('provider_profiles')->insert(['id' => $provider, 'partner_id' => $partner->id, 'party_id' => $party->id, 'category' => 'HEALTH', 'provider_type_code' => 'CLINIC', 'created_at' => now(), 'updated_at' => now()]);
    $network = (string) Str::uuid();
    DB::table('provider_networks')->insert(['id' => $network, 'tenant_id' => $this->t->id, 'code' => 'NET', 'name' => 'Net', 'network_type_code' => 'PPO', 'created_at' => now(), 'updated_at' => now()]);
    $contract = (string) Str::uuid();
    DB::table('provider_contracts')->insert(['id' => $contract, 'tenant_id' => $this->t->id, 'provider_network_id' => $network, 'provider_profile_id' => $provider, 'contract_number' => 'C-1',
        'effective_from' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    $this->a = hciBook($this->t, $provider, $contract, 'A');
    $this->b = hciBook($this->t, $provider, $contract, 'B');
    expect($this->a['carrier_id'])->not->toBe($this->b['carrier_id']);
    $this->adminA = hciUser($this->t, 'CARRIER_ADMIN', ['carrier_id' => $this->a['carrier_id']]);
});

it('grants the carrier roles the health reads and decisions of the matrix', function () {
    $admin = RoleCatalogue::defaultPermissions('CARRIER_ADMIN');
    $staff = RoleCatalogue::defaultPermissions('CARRIER_STAFF');
    expect($admin)->toContain('health.preauth.view', 'health.preauth.review', 'health.preauth.approve', 'health.preauth.supervise', 'health.provider_claims.view',
        'health.provider_claims.adjudicate', 'health.provider_claims.approve_payment', 'health.provider_settlements.manage', 'health.provider_settlements.pay')
        ->and(RoleCatalogue::defaultPermissions('CARRIER_SUPER_ADMIN'))->toContain('health.preauth.approve', 'health.provider_settlements.pay')
        ->and($staff)->toContain('health.preauth.view', 'health.provider_claims.adjudicate')->not->toContain('health.preauth.approve', 'health.provider_settlements.pay')
        ->and(RoleCatalogue::defaultPermissions('CLAIMS_OFFICER'))->toContain('health.preauth.view', 'health.provider_claims.view')->not->toContain('health.preauth.review', 'health.preauth.approve');
});

it('insurer panel: carrier A sees only its own pre-authorizations, provider claims, settlements and disputes', function () {
    auth()->login($this->adminA);
    Filament::setCurrentPanel(Filament::getPanel('insurer'));
    app(TenantContext::class)->set($this->t->id);

    foreach (['health_preauthorizations' => 'preauth', 'health_provider_claims' => 'claim', 'health_provider_settlement_batches' => 'batch', 'provider_disputes' => 'dispute'] as $table => $k) {
        expect(PortalScope::visibleOf($table, [$this->a[$k], $this->b[$k]]))->toBe([$this->a[$k]]);
    }
    expect(fn () => HealthPreauthorizationQueue::assertVisible($this->b['preauth']))->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class)
        ->and(HealthPreauthorizationQueue::assertVisible($this->a['preauth']))->toBe($this->a['preauth']);
});

it('insurer panel: the health queue pages render for a carrier admin with only its own rows', function () {
    $this->actingAs($this->adminA);
    $this->get('/insurer/health/preauthorizations')->assertOk()->assertSee('PA-A')->assertDontSee('PA-B');
    $this->get('/insurer/health/provider-claims')->assertOk()->assertSee('HPC-A')->assertDontSee('HPC-B');
});

it('health API: carrier A lists only its rows and gets 404 on carrier B ids', function () {
    \Laravel\Passport\Passport::actingAs($this->adminA);
    $h = ['X-Tenant-Id' => $this->t->id, 'Accept' => 'application/json'];

    $ids = collect($this->getJson('/api/v1/health/preauthorizations', $h)->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$this->a['preauth']]);
    $ids = collect($this->getJson('/api/v1/health/provider-claims', $h)->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$this->a['claim']]);

    $this->getJson('/api/v1/health/preauthorizations/'.$this->a['preauth'], $h)->assertOk();
    $this->getJson('/api/v1/health/preauthorizations/'.$this->b['preauth'], $h)->assertNotFound();
    $this->getJson('/api/v1/health/provider-claims/'.$this->b['claim'], $h)->assertNotFound();
    $this->getJson('/api/v1/health/provider-settlements/'.$this->b['batch'], $h)->assertNotFound();
    $this->postJson('/api/v1/health/provider-disputes/'.$this->b['dispute'].'/resolve', ['status' => 'CLOSED', 'response' => 'nope'], $h + ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();
});

it('an unlinked carrier admin in a shared tenant is refused, not shown every carrier', function () {
    \Laravel\Passport\Passport::actingAs(hciUser($this->t, 'CARRIER_ADMIN'));
    $this->getJson('/api/v1/health/preauthorizations', ['X-Tenant-Id' => $this->t->id])->assertForbidden();
});
