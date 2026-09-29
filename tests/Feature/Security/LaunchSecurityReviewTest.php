<?php

declare(strict_types=1);

/**
 * R1 launch security review (2026-09-29): adversarial checks on today's additions and the pre-existing servicing
 * routes. Each test pins one real finding (it failed before the fix).
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{Partner, Party, Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function r1Tenant(): Tenant
{
    return Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'R1 '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function r1User(Tenant $t, string $role, array $membership = [], ?string $partyId = null, ?array $perms = null): User
{
    $u = User::factory()->create(['status' => 'ACTIVE', 'party_id' => $partyId]);
    $m = TenantMembership::forceCreate(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE'] + $membership);
    $r = $perms === null
        ? Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true])
        : Role::create(['tenant_id' => $t->id, 'code' => $role.'_'.Str::random(6), 'permissions' => $perms, 'is_system' => false]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

function r1Broker(Tenant $t, string $name): Partner
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE']);

    return Partner::create(['tenant_id' => $t->id, 'party_id' => $party->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
}

/** A client of $partner recorded by $producer with an ACTIVE policy. */
function r1Client(Tenant $t, Partner $partner, User $producer): array
{
    $chain = makeMobileFinanceProposalChain($t);
    DB::table('customer_attributions')->insert(['id' => (string) Str::uuid(), 'party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER',
        'terms_version' => '1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $producer->id, 'created_at' => now(), 'updated_at' => now()]);
    $policy = makeMobileTestPolicy($chain['proposal'], $t, $chain['carrier']->id, $chain['party']->id);

    return ['party' => $chain['party'], 'policy' => $policy, 'carrier_id' => $policy->carrier_id];
}

function r1H(Tenant $t): array
{
    return ['X-Tenant-Id' => $t->id, 'Idempotency-Key' => (string) Str::uuid()];
}

describe('policy servicing writes are bounded by the caller\'s book / carrier (R1-01)', function () {
    beforeEach(function () {
        $this->t = r1Tenant();
        $this->a = r1Broker($this->t, 'Broker A');
        $this->b = r1Broker($this->t, 'Broker B');
        $this->adminA = r1User($this->t, 'BROKER_ADMIN', [], $this->a->party_id);
        $this->adminB = r1User($this->t, 'BROKER_ADMIN', [], $this->b->party_id);
        $this->ca = r1Client($this->t, $this->a, $this->adminA);
        $this->cb = r1Client($this->t, $this->b, $this->adminB);
        $this->cancel = ['effective_at' => now()->addDay()->toDateString(), 'initiated_by' => 'INSURED', 'reason_code' => 'CLIENT_REQUEST'];
    });

    it('a broker cannot request a cancellation (or preview one) on another broker\'s policy', function () {
        Passport::actingAs($this->adminA);
        $pid = $this->cb['policy']->id;
        $this->postJson("/api/v1/policies/{$pid}/cancellations/preview", $this->cancel, r1H($this->t))->assertNotFound();
        $this->postJson("/api/v1/policies/{$pid}/cancellations", $this->cancel, r1H($this->t))->assertNotFound();
        expect(DB::table('policy_cancellations')->where('policy_id', $pid)->exists())->toBeFalse();
        // own book: reaches the service (not 403/404)
        expect($this->postJson("/api/v1/policies/{$this->ca['policy']->id}/cancellations/preview", $this->cancel, r1H($this->t))->status())->not->toBeIn([403, 404]);
    });

    it('a broker cannot assign a sticker to another broker\'s policy', function () {
        Passport::actingAs($this->adminA);
        $this->postJson("/api/v1/policies/{$this->cb['policy']->id}/sticker", ['serial_number' => 'S-1'], r1H($this->t))->assertNotFound();
    });

    it('a linked insurer user cannot act on another carrier\'s policy', function () {
        $other = makeMobileFinanceProposalChain($this->t)['carrier'];
        $u = r1User($this->t, 'CARRIER_ADMIN', ['carrier_id' => $other->id], null, ['policies.cancellation.request', 'policies.service.approve']);
        Passport::actingAs($u);
        $this->postJson("/api/v1/policies/{$this->ca['policy']->id}/cancellations/preview", $this->cancel, r1H($this->t))->assertNotFound();
        $this->postJson("/api/v1/policies/{$this->ca['policy']->id}/transactions", ['type' => 'ENDORSEMENT', 'effective_at' => now()->addDay()->toDateString(), 'premium_delta_minor' => 0, 'reason_code' => 'X'], r1H($this->t))->assertNotFound();
    });

    it('POST policies/{p}/transactions needs a servicing permission and the book', function () {
        $body = ['type' => 'ENDORSEMENT', 'effective_at' => now()->addDay()->toDateString(), 'premium_delta_minor' => -50000, 'reason_code' => 'X'];
        // broker admin: no policies.service.approve — was 201 on any tenant policy
        Passport::actingAs($this->adminA);
        $this->postJson("/api/v1/policies/{$this->cb['policy']->id}/transactions", $body, r1H($this->t))->assertNotFound();
        $this->postJson("/api/v1/policies/{$this->ca['policy']->id}/transactions", $body, r1H($this->t))->assertForbidden();
        // tenant ops staff without the permission
        Passport::actingAs(r1User($this->t, 'R1_OPS', [], null, ['policies.read']));
        $this->postJson("/api/v1/policies/{$this->ca['policy']->id}/transactions", $body, r1H($this->t))->assertForbidden();
        expect(DB::table('policy_transactions')->whereIn('policy_id', [$this->ca['policy']->id, $this->cb['policy']->id])->count())->toBe(0);
        // tenant ops staff with it: legitimate flow still works
        Passport::actingAs(r1User($this->t, 'R1_OPS', [], null, ['policies.service.approve']));
        $this->postJson("/api/v1/policies/{$this->ca['policy']->id}/transactions", $body, r1H($this->t))->assertCreated();
    });
});

it('R1-02 an insurer tenant\'s claims manager cannot open the platform-wide API client / webhook screens', function () {
    $insurer = Tenant::create(['type' => 'INSURER', 'legal_name' => 'R1 Ins '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $u = r1User($insurer, 'CLAIMS_MANAGER');
    $this->actingAs($u)->get('/admin/integrations/api-clients')->assertForbidden();
    $this->actingAs($u)->get('/admin/integrations/webhooks')->assertForbidden();
});

it('R1-03 an agent cannot assign branch-level sticker stock to a book policy', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $agentParty = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'R1 Agent', 'status' => 'ACTIVE']);
    $partner = Partner::create(['tenant_id' => $f['tenant']->id, 'party_id' => $agentParty->id, 'type' => 'AGENT', 'status' => 'ACTIVE']);
    $agent = r1User($f['tenant'], 'AGENT', [], $agentParty->id, ['stickers.view', 'stickers.assign', 'agent.clients.read', 'agent.clients.manage']);
    DB::table('customer_attributions')->insert(['id' => (string) Str::uuid(), 'party_id' => $f['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'AGENT',
        'terms_version' => '1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now()]);
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $admin = r1User($f['tenant'], 'CARRIER_ADMIN', [], null, ['stickers.allocate.carrier', 'stickers.receive', 'stickers.view']);
    app(\App\Application\Certificates\CertificateService::class)->receiveBatch(['carrier_id' => $f['carrier']->id, 'batch_number' => 'B-'.Str::random(6),
        'stickers' => [['serial_number' => 'R1-STK-1', 'security_code' => Str::random(24)]]], $admin);
    DB::table('sticker_stock')->where('serial_number', 'R1-STK-1')->update(['custody_level' => 'BRANCH', 'custodian_tenant_id' => $f['tenant']->id, 'status' => 'IN_STOCK']);

    Passport::actingAs($agent);
    $this->postJson("/api/v1/mobile/partner/agent/policies/{$policy->id}/sticker", ['serial_number' => 'R1-STK-1'], r1H($f['tenant']))->assertStatus(422);
    $this->postJson("/api/v1/policies/{$policy->id}/sticker", ['serial_number' => 'R1-STK-1'], r1H($f['tenant']))->assertStatus(422);
    expect(DB::table('sticker_stock')->where('serial_number', 'R1-STK-1')->value('status'))->toBe('IN_STOCK');
});
