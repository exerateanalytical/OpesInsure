<?php

declare(strict_types=1);

/**
 * Q5 2026-09-29 — broker portal screens BRK-002 .. BRK-024 (App\Filament\Shared\Pages\Broker, BrokerOperationsWidget).
 *  (1) each page answers 200 (EN and FR) to exactly the broker roles holding its API permission and 403 to the others,
 *      with the roles' governed default permissions (RoleCatalogue), and appears in the sidebar only for them;
 *  (2) isolation: a broker admin sees its own company's book only — never another company of the same tenant, never
 *      another tenant; detail pages 404 on another company's lead / KYC case / customer.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{Claim, KycSubmission, Partner, Party, Role, Tenant, TenantCustomer, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

/** page => roles that get 200 (every other broker role gets 403). */
const BSA_MATRIX = [
    '/broker' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER'],
    '/broker/customer-dashboard' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF'],
    '/broker/claims-dashboard' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER'],
    '/broker/leads' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER'],
    '/broker/lead-assignment' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BRANCH_MANAGER'],
    '/broker/customer-timeline' => ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF'],
    // R6 2026-09-29: BROKER_ADMIN now holds parties.match.review (RoleCatalogue); the review lists the caller's own customers only.
    '/broker/duplicate-customers' => ['BROKER_ADMIN'],
    '/broker/portfolio-transfers' => ['BROKER_ADMIN'],
    '/broker/kyc' => ['BROKER_ADMIN'],
    '/broker/kyc/queue' => ['BROKER_ADMIN'],
    '/broker/kyc/remediation' => ['BROKER_ADMIN'],
    '/broker/kyc/expiring' => ['BROKER_ADMIN'],
    '/broker/kyc/corporate-due-diligence' => ['BROKER_ADMIN'],
];

function bsaTenant(string $type = 'BROKER'): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'BSA '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function bsaUser(Tenant $t, string $role, ?string $partyId = null, ?array $permissions = null): User
{
    $u = User::factory()->create(['status' => 'ACTIVE', 'party_id' => $partyId]);
    $m = TenantMembership::forceCreate(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = $permissions === null
        ? Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true])
        : Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'code' => $role.'_X'.Str::random(4), 'permissions' => $permissions, 'is_system' => false]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

function bsaCompany(Tenant $t, string $name): Partner
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE']);

    return Partner::create(['tenant_id' => $t->id, 'party_id' => $party->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
}

/** A corporate client of $partner (tag in every name / number) with quote, proposal, policy, open claim, KYC case and a lead. */
function bsaClient(Tenant $t, Partner $partner, User $producer, string $tag): array
{
    $chain = makeMobileFinanceProposalChain($t);
    $chain['party']->update(['display_name' => 'CLIENT-'.$tag, 'type' => 'ORGANIZATION']);
    DB::table('customer_attributions')->insert(['id' => (string) Str::uuid(), 'party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER',
        'terms_version' => '1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $producer->id, 'created_at' => now(), 'updated_at' => now()]);
    $customer = TenantCustomer::create(['tenant_id' => $t->id, 'party_id' => $chain['party']->id, 'customer_number' => 'CUS-'.$tag, 'status' => 'ACTIVE']);
    $policy = makeMobileTestPolicy($chain['proposal'], $t, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-'.$tag]);
    Claim::create(['tenant_id' => $t->id, 'policy_id' => $policy->id, 'claimant_party_id' => $chain['party']->id, 'claim_number' => 'CLM-'.$tag, 'status' => 'REGISTERED',
        'loss_occurred_at' => now()->subDay(), 'loss_details' => ['description' => 'x'], 'currency' => 'XAF']);
    $kyc = KycSubmission::create(['tenant_id' => $t->id, 'party_id' => $chain['party']->id, 'status' => 'SUBMITTED', 'submitted_at' => now()]);
    $lead = (string) Str::uuid();
    DB::table('partner_leads')->insert(['id' => $lead, 'tenant_id' => $t->id, 'partner_id' => $partner->id, 'full_name' => 'LEAD-'.$tag, 'phone_e164' => '+237690000'.random_int(100, 999),
        'status' => 'NEW', 'created_by' => $producer->id, 'created_at' => now(), 'updated_at' => now()]);

    return ['customer' => $customer, 'kyc' => $kyc, 'lead' => $lead];
}

dataset('bsa roles', ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER']);

it('opens each BRK screen to exactly the broker roles holding its API permission, in EN and FR', function (string $role) {
    $this->actingAs(bsaUser(bsaTenant(), $role));
    foreach (['en', 'fr'] as $lang) {
        $got = [];
        foreach (BSA_MATRIX as $url => $roles) {
            $res = $this->get($url.'?lang='.$lang);
            $got[$url] = $res->getStatusCode();
            if ($res->getStatusCode() === 200) {
                expect(strip_tags($res->getContent()))->not->toMatch('/broker_screens_a\.[a-z_]+/');
            }
        }
        expect($got)->toBe(array_map(fn (array $roles) => in_array($role, $roles, true) ? 200 : 403, BSA_MATRIX));
    }
})->with('bsa roles');

it('lists a BRK screen in the sidebar only for the roles that may open it', function (string $role, string $lang, array $shown, array $hidden) {
    $this->actingAs(bsaUser(bsaTenant(), $role));
    $html = $this->get('/broker?lang='.$lang)->assertOk()->getContent();
    foreach ($shown as $needle) {
        expect($html)->toContain($needle);
    }
    foreach ($hidden as $needle) {
        expect($html)->not->toContain($needle);
    }
})->with([
    'staff' => ['BROKER_STAFF', 'en', ['/broker/leads', '/broker/customer-dashboard', 'Customer dashboard'], ['/broker/kyc/queue', '/broker/lead-assignment', '/broker/portfolio-transfers']],
    'admin fr' => ['BROKER_ADMIN', 'fr', ['/broker/kyc/queue', 'File KYC', 'Tableaux de bord', 'Due diligence entreprises', '/broker/duplicate-customers'], ['/broker/kyc/case']],
    'supervisor' => ['BROKER_SUPERVISOR', 'en', ['/broker/lead-assignment'], ['/broker/duplicate-customers', '/broker/kyc/queue']],
]);

it('opens the duplicate review to a holder of parties.match.review only', function () {
    $t = bsaTenant();
    $this->actingAs(bsaUser($t, 'BROKER_ADMIN', null, ['broker.portal.read', 'parties.match.review']));
    $this->get('/broker/duplicate-customers')->assertOk()->assertSee('Duplicate customer review');
    $this->get('/broker/duplicate-customers?lang=fr')->assertOk()->assertSee('Revue des doublons clients');
});

it('shows the /broker home as an operations dashboard of the book (EN and FR)', function () {
    $t = bsaTenant();
    $this->actingAs(bsaUser($t, 'BROKER_ADMIN'));
    $this->get('/broker?lang=en')->assertOk()->assertSee('data-metric="premium_written_12m"', false)->assertSee('data-metric="quote_conversion_90d"', false)
        ->assertSee('data-metric="commissions_outstanding"', false)->assertSee('data-metric="renewals_due_30d"', false)->assertSee('Premium written (12 months)');
    $this->get('/broker?lang=fr')->assertOk()->assertSee('Primes émises (12 mois)')->assertSee('Transformation des devis (90 jours)');

    // BRANCH_MANAGER holds no broker.finance.read: no commission tile.
    $this->flushSession();
    $this->actingAs(bsaUser($t, 'BRANCH_MANAGER'));
    $this->get('/broker')->assertOk()->assertDontSee('data-metric="commissions_outstanding"', false);
});

it('never shows another brokerage\'s customers, claims, leads or KYC cases', function () {
    $t = bsaTenant();
    $a = bsaCompany($t, 'Company A');
    $b = bsaCompany($t, 'Company B');
    $admin = bsaUser($t, 'BROKER_ADMIN', $a->party_id);
    $otherProducer = bsaUser($t, 'BROKER_ADMIN', $b->party_id);
    $mine = bsaClient($t, $a, $admin, 'AAA');
    $theirs = bsaClient($t, $b, $otherProducer, 'BBB');
    $elsewhere = bsaTenant();
    $x = bsaCompany($elsewhere, 'Company X');
    bsaClient($elsewhere, $x, bsaUser($elsewhere, 'BROKER_ADMIN', $x->party_id), 'XXX');

    $this->actingAs($admin);
    foreach ([
        '/broker/customer-dashboard' => 'CLIENT-', '/broker/claims-dashboard' => 'CLM-', '/broker/leads' => 'LEAD-', '/broker/customer-timeline' => 'POL-',
        '/broker/kyc' => 'CLIENT-', '/broker/kyc/queue' => 'CLIENT-', '/broker/kyc/corporate-due-diligence' => 'CLIENT-',
    ] as $url => $prefix) {
        $this->get($url)->assertOk()->assertSee($prefix.'AAA')->assertDontSee($prefix.'BBB')->assertDontSee($prefix.'XXX');
    }
    $this->get('/broker/leads/details?lead='.$mine['lead'])->assertOk()->assertSee('LEAD-AAA');
    $this->get('/broker/leads/details?lead='.$theirs['lead'])->assertNotFound();
    $this->get('/broker/kyc/case?submission='.$mine['kyc']->id)->assertOk()->assertSee('CLIENT-AAA');
    $this->get('/broker/kyc/case?submission='.$theirs['kyc']->id)->assertNotFound();
    $this->get('/broker/customer-timeline?customer='.$mine['customer']->id)->assertOk()->assertSee('POL-AAA');
    $this->get('/broker/customer-timeline?customer='.$theirs['customer']->id)->assertNotFound();
    $this->get('/broker?lang=en')->assertOk()->assertSee('data-metric="policies_active"', false);
});
