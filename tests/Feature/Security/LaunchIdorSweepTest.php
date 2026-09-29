<?php

declare(strict_types=1);

/**
 * S6 launch IDOR sweep (2026-09-29). Each case failed before the fix:
 *  - BRK-017 duplicate review listed / counted match candidates against parties outside the caller's book;
 *  - party-merges accepted any candidate id, whatever the two parties;
 *  - a carrier-scoped admin could read / move another insurer's tariffs, products, capability profiles and rule sets;
 *  - POST carrier/delegated-authorities/{a}/check had no permission gate and no carrier scope;
 *  - any tenant user could register customers, change another customer's communication preferences and register a
 *    document against any party;
 *  - privileged access could be granted to a user who is not a member of the tenant.
 * Foreign records answer 404 (never reveal existence); missing permissions answer 403.
 */

use App\Models\{Carrier, InsuranceProduct, Partner, Party, Role, Tenant, TenantCustomer, TenantMembership, TariffVersion, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function s6Tenant(string $type = 'BROKER'): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'S6 '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function s6User(Tenant $t, string $role, array $permissions, ?string $partyId = null, ?string $carrierId = null): User
{
    $u = User::factory()->create(['status' => 'ACTIVE', 'party_id' => $partyId]);
    $m = TenantMembership::forceCreate(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->syncWithoutDetaching([Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'code' => $role.'_S6'.Str::random(4), 'permissions' => $permissions, 'is_system' => false])->id]);

    return $u;
}

function s6Company(Tenant $t, string $name): Partner
{
    return Partner::create(['tenant_id' => $t->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
}

/** A client of $partner's book (ACTIVE attribution recorded by $producer) and customer of $t. */
function s6Client(Tenant $t, ?Partner $partner, ?User $producer, string $name): Party
{
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => $name, 'status' => 'ACTIVE']);
    TenantCustomer::create(['tenant_id' => $t->id, 'party_id' => $party->id, 'customer_number' => 'CUS-'.$name, 'status' => 'ACTIVE']);
    if ($partner) {
        DB::table('customer_attributions')->insert(['id' => (string) Str::uuid(), 'party_id' => $party->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER',
            'terms_version' => '1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $producer->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $party;
}

function s6Candidate(Party $a, Party $b, float $score): string
{
    $id = (string) Str::uuid();
    DB::table('entity_match_candidates')->insert(['id' => $id, 'entity_type' => 'party', 'party_a_id' => $a->id, 'party_b_id' => $b->id, 'score' => $score,
        'band' => $score >= 0.9 ? 'HIGH' : 'MEDIUM', 'reasons' => '[]', 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Two insurers in one tenant; the caller is carrier A's admin. */
function s6Carriers(): array
{
    $t = s6Tenant('CARRIER');
    $carrier = fn (string $n) => Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => $n, 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
    [$a, $b] = [$carrier('Insurer A'), $carrier('Insurer B')];
    $product = fn (Carrier $c) => InsuranceProduct::create(['carrier_id' => $c->id, 'line_code' => 'AUTO', 'code' => 'AUTO-'.Str::random(6), 'name' => 'Plan', 'version' => 1, 'effective_from' => now()->toDateString(), 'status' => 'DRAFT']);
    [$pa, $pb] = [$product($a), $product($b)];
    $tariff = fn (InsuranceProduct $p) => TariffVersion::create(['insurance_product_id' => $p->id, 'version' => 1, 'effective_from' => now()->toDateString(), 'status' => 'IN_REVIEW', 'input_schema' => [], 'rules' => [], 'rules_hash' => Str::random(64)]);
    $admin = s6User($t, 'CARRIER_ADMIN', ['tariff.manage', 'tariff.approve', 'tariff.publish', 'catalogue.manage', 'catalogue.publish', 'capability_profiles.view', 'capability_profiles.manage',
        'rules.manage', 'rules.approve', 'carrier.authority.manage'], null, $a->id);

    return ['tenant' => $t, 'a' => $a, 'b' => $b, 'pa' => $pa, 'pb' => $pb, 'ta' => $tariff($pa), 'tb' => $tariff($pb), 'admin' => $admin, 'h' => ['X-Tenant-Id' => $t->id]];
}

it('BRK-017 lists and counts only match candidates whose two parties are both in the caller\'s book', function () {
    $t = s6Tenant();
    [$a, $b] = [s6Company($t, 'Company A'), s6Company($t, 'Company B')];
    $admin = s6User($t, 'BROKER_ADMIN', ['broker.portal.read', 'parties.match.review'], $a->party_id);
    $producerB = s6User($t, 'BROKER_ADMIN', ['broker.portal.read'], $b->party_id);
    $mine = s6Client($t, $a, $admin, 'CLIENT-AAA');
    $mineToo = s6Client($t, $a, $admin, 'CLIENT-AAB');
    $crossOnly = s6Client($t, $a, $admin, 'CLIENT-AAC');
    $theirs = s6Client($t, $b, $producerB, 'CLIENT-BBB');
    $elsewhere = s6Client(s6Tenant(), null, null, 'CLIENT-XXX');

    s6Candidate($mine, $mineToo, 0.7123);      // in book: shown
    s6Candidate($mine, $theirs, 0.9911);       // other company's client: must not raise AAA's count / best score
    s6Candidate($crossOnly, $theirs, 0.9822);  // only match is outside the book: AAC must not be listed
    s6Candidate($crossOnly, $elsewhere, 0.9733); // other tenant

    $this->actingAs($admin);
    $this->get('/broker/duplicate-customers')->assertOk()
        ->assertSee('CLIENT-AAA')->assertSee('0.7123')
        ->assertDontSee('CLIENT-AAC')->assertDontSee('CLIENT-BBB')
        ->assertDontSee('0.9911')->assertDontSee('0.9822')->assertDontSee('0.9733');
});

it('refuses a party merge that names a match candidate of two other parties', function () {
    $t = s6Tenant();
    $u = s6User($t, 'OPERATIONS_OFFICER', ['parties.merge.request', 'parties.match.review']);
    [$p1, $p2, $x1, $x2] = [s6Client($t, null, null, 'P1'), s6Client($t, null, null, 'P2'), s6Client($t, null, null, 'X1'), s6Client($t, null, null, 'X2')];
    $foreign = s6Candidate($x1, $x2, 0.95);
    Passport::actingAs($u);

    $this->postJson('/api/v1/party-merges', ['survivor_party_id' => $p1->id, 'merged_party_id' => $p2->id, 'candidate_id' => $foreign], ['X-Tenant-Id' => $t->id])->assertNotFound();
    expect(DB::table('entity_match_candidates')->where('id', $foreign)->value('status'))->toBe('OPEN');
});

it('404s a carrier admin on another insurer\'s tariffs and products, and still serves its own', function () {
    $w = s6Carriers();
    Passport::actingAs($w['admin']);

    $this->getJson("/api/v1/tariffs/{$w['tb']->id}", $w['h'])->assertNotFound();
    $this->getJson("/api/v1/tariffs/{$w['ta']->id}", $w['h'])->assertOk();
    $this->postJson("/api/v1/tariffs/{$w['tb']->id}/approve", ['reason' => str_repeat('Approved after an independent review. ', 2)], $w['h'])->assertNotFound();
    $this->postJson("/api/v1/tariffs/{$w['tb']->id}/reject", ['notes' => 'Rejected by the other insurer.'], $w['h'])->assertNotFound();
    $this->postJson("/api/v1/catalogue/products/{$w['pb']->id}/submit", ['notes' => 'Submitting a foreign product.'], $w['h'])->assertNotFound();
    $this->postJson("/api/v1/catalogue/products/{$w['pb']->id}/publish", ['reason' => str_repeat('Publishing a foreign product. ', 2)], $w['h'])->assertNotFound();

    expect($w['tb']->fresh()->status)->toBe('IN_REVIEW')->and($w['pb']->fresh()->status)->toBe('DRAFT');
});

it('404s a carrier admin on another insurer\'s capability profiles', function () {
    $w = s6Carriers();
    Passport::actingAs($w['admin']);

    $this->getJson("/api/v1/carriers/{$w['b']->id}/capability-profiles", $w['h'])->assertNotFound();
    $this->getJson("/api/v1/carriers/{$w['a']->id}/capability-profiles", $w['h'])->assertOk();
    $this->postJson("/api/v1/carriers/{$w['b']->id}/capability-profiles", ['modes' => []], $w['h'])->assertNotFound();
});

it('gates the delegated-authority check by permission and insurer', function () {
    $w = s6Carriers();
    $partner = s6Company($w['tenant'], 'DA Broker');
    $da = fn (Carrier $c) => tap((string) Str::uuid(), fn ($id) => DB::table('delegated_authority_agreements')->insert(['id' => $id, 'carrier_id' => $c->id, 'partner_id' => $partner->id,
        'agreement_number' => 'DA-'.Str::random(6), 'effective_from' => now()->subDay()->toDateString(), 'effective_until' => now()->addYear()->toDateString(), 'status' => 'ACTIVE',
        'permitted_lines' => '["AUTOMOBILE"]', 'max_policy_premium_minor' => 500000, 'territories' => '["CM"]', 'created_at' => now(), 'updated_at' => now()]));
    [$own, $foreign] = [$da($w['a']), $da($w['b'])];
    $body = ['line_code' => 'AUTOMOBILE', 'premium_minor' => 1000, 'territory' => 'CM', 'effective_at' => now()->toIso8601String()];

    Passport::actingAs($w['admin']);
    $this->postJson("/api/v1/carrier/delegated-authorities/{$foreign}/check", $body, $w['h'])->assertNotFound();
    $this->postJson("/api/v1/carrier/delegated-authorities/{$own}/check", $body, $w['h'])->assertOk()->assertJsonPath('data.allowed', true);

    Passport::actingAs(s6User($w['tenant'], 'OPERATIONS_OFFICER', ['quotes.read']));
    $this->postJson("/api/v1/carrier/delegated-authorities/{$own}/check", $body, $w['h'])->assertForbidden();
});

it('keeps customer self-service routes on the caller\'s own party', function () {
    $a = makeMobileCustomerFixture('+237670006601');
    makeMobileTestTenantCustomer($a['tenant'], $a['party']);
    $other = s6Client($a['tenant'], null, null, 'Other Customer');
    $h = tenantHeaderFor($a['tenant']);
    Passport::actingAs($a['user']);

    $pref = ['purpose' => 'MARKETING', 'channel' => 'SMS', 'enabled' => true];
    $this->putJson('/api/v1/communication-preferences', ['party_id' => $other->id] + $pref, $h)->assertNotFound();
    $this->putJson('/api/v1/communication-preferences', ['party_id' => $a['party']->id] + $pref, $h)->assertOk();
    expect(DB::table('communication_preferences')->where('party_id', $other->id)->exists())->toBeFalse();

    $doc = ['category' => 'ID_CARD', 'storage_key' => 'documents/s6/x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => hash('sha256', Str::random(20))];
    $this->postJson('/api/v1/documents', ['party_id' => Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Stranger', 'status' => 'ACTIVE'])->id] + $doc, $h)->assertNotFound();

    $this->postJson('/api/v1/customers', ['type' => 'PERSON', 'display_name' => 'Injected', 'phone_e164' => '+237670006699', 'consent' => true, 'notice_version' => 'v1',
        'origin_type' => 'SYSTEM', 'evidence_reference' => 'x'], $h)->assertForbidden();
    expect(DB::table('party_contacts')->where('normalized_value', '+237670006699')->exists())->toBeFalse();
});

it('grants privileged access only to members of the tenant', function () {
    $t = s6Tenant();
    $admin = s6User($t, 'COMPLIANCE_ADMIN', ['compliance.access.grant']);
    $stranger = User::factory()->create(['status' => 'ACTIVE']);
    Passport::actingAs($admin);

    $res = $this->postJson('/api/v1/compliance/privileged-access', ['user_id' => $stranger->id, 'purpose' => 'INCIDENT', 'justification' => str_repeat('Investigating an incident. ', 3),
        'starts_at' => now()->toIso8601String(), 'expires_at' => now()->addHour()->toIso8601String(), 'scope' => ['policies.read']], ['X-Tenant-Id' => $t->id]);
    $res->assertNotFound();
    expect(DB::table('privileged_access_grants')->where('user_id', $stranger->id)->exists())->toBeFalse();
});
