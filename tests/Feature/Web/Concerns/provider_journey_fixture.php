<?php

declare(strict_types=1);

/**
 * Shared /provider journey fixture (ProviderJourneyTest, LaunchProviderE2ETest): an active health policy with OUTPATIENT and
 * INPATIENT cover, a contracted clinic (facility MAIN, network, approved tariff for CONS_PJ), a second unrelated provider,
 * the published document catalogue, and a provider staff user.
 */

use App\Application\Documents\Engine\DocumentTemplateService;
use App\Application\Health\Preauth\PreauthLifecycle;
use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Models\DocumentIssuanceProfile;
use App\Models\DocumentTemplate;
use App\Models\Party;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

const PJ_PERMS = ['provider.dashboard.view', 'provider.patient.search', 'provider.eligibility.check', 'provider.benefits.view', 'provider.preauth.create', 'provider.preauth.view',
    'provider.preauth.respond_to_query', 'provider.admission.create', 'provider.admission.extend', 'provider.treatment.view', 'provider.treatment.update', 'provider.claim.create',
    'provider.claim.submit', 'provider.claim.view', 'provider.claim.respond_to_query', 'provider.contract.view', 'provider.settlement.view', 'provider.documents.view'];

function pjEmployee(object $t, object $provider, array $extraPerms = []): User
{
    $person = Party::create(['type' => 'PERSON', 'display_name' => 'Staff '.Str::random(4), 'status' => 'ACTIVE']);
    DB::table('party_relationships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'from_party_id' => $person->id, 'to_party_id' => $provider->party_id,
        'type' => 'EMPLOYED_BY', 'status' => 'ACTIVE', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $u = makeAuthTestUser($t->tenant, array_values(array_unique([...PJ_PERMS, ...$extraPerms])), 'PROVIDER_ADMIN');
    $u->update(['party_id' => $person->id]);

    return $u->refresh();
}

function pjActAs(object $t, User $u, object $provider): void
{
    test()->actingAs($u, 'web');
    app(TenantContext::class)->set($t->tenant->id);
    app()->instance(ProviderScope::class.'@panel', new ProviderScope($provider->id, [$provider->id], $provider->party_id, null));
}

function pjInsurer(object $t, array $perms): User
{
    $u = makeAuthTestUser($t->tenant, $perms);
    DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $t->f['carrier']->id, 'holder_type' => 'USER', 'holder_id' => $u->id,
        'authority_type' => PreauthLifecycle::authorityType(), 'max_amount_minor' => 10_000_000, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

    return $u;
}

/** Builds the fixture on the test case $t (f, tenant, policy, cons, clinic, other, main, contract, user). */
function pjFixture(object $t): void
{
    $root = storage_path('framework/testing/disks/pj-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    // beforeApplicationDestroyed is protected: register the disk cleanup from inside the test case.
    (fn () => $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root)))->call($t);

    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $t->f = $f;
    $t->tenant = $f['tenant'];
    $t->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    DocumentIssuanceProfile::create(['carrier_id' => $f['carrier']->id, 'issuance_mode' => 'OPES_GENERATED', 'opes_rendering_authorized' => true, 'authorization_reference' => 'AUTH-PJ', 'default_language' => 'BILINGUAL']);
    $admin = makeAuthTestUser($t->tenant, ['documents.templates.manage']);
    foreach (DocumentTemplate::where('status', 'REVIEW')->where('ownership', 'PLATFORM')->get() as $tpl) {
        app(DocumentTemplateService::class)->approveAndPublishSystem($tpl, $admin);
    }

    $t->policy = Policy::create(['tenant_id' => $t->tenant->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonths(2)->toDateString(), 'coverage_ends_at' => now()->addMonths(10)->toDateString(),
        'terms_snapshot' => ['line_code' => 'HEALTH'], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 1_000_000, 'issued_at' => now()]);
    $version = (string) Str::uuid();
    DB::table('policy_versions')->insert(['id' => $version, 'tenant_id' => $t->tenant->id, 'policy_id' => $t->policy->id, 'version_no' => 1, 'kind' => 'ISSUANCE',
        'valid_from' => now()->subMonths(2)->toDateString(), 'recorded_at' => now()->subDay(), 'snapshot' => '{}', 'snapshot_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
    foreach (['OUTPATIENT', 'INPATIENT'] as $cov) {
        DB::table('policy_coverages')->insert(['id' => (string) Str::uuid(), 'policy_id' => $t->policy->id, 'policy_version_id' => $version, 'coverage_code' => $cov,
            'limit_minor' => 5_000_000, 'deductible_minor' => 0, 'currency' => 'XAF', 'starts_at' => now()->subMonths(2)->toDateString(), 'ends_at' => now()->addMonths(10)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }

    $net = app(ProviderNetworkService::class);
    $t->cons = $net->addMedicalService(['code' => 'CONS_PJ', 'name' => 'GP consultation', 'category_code' => 'OUTPATIENT']);
    DB::table('health_benefit_rules')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->tenant->id, 'service_category_code' => 'OUTPATIENT', 'coverage_code' => 'OUTPATIENT',
        'benefit_code' => 'OP', 'created_at' => now(), 'updated_at' => now()]);

    $reg = app(ProviderRegistry::class);
    $t->clinic = $reg->register(['category' => 'HEALTH', 'name' => 'Clinique PJ '.Str::random(3), 'provider_type_code' => 'CLINIC'], null);
    $t->other = $reg->register(['category' => 'HEALTH', 'name' => 'Autre PJ '.Str::random(3), 'provider_type_code' => 'CLINIC'], null);
    foreach ([$t->clinic, $t->other] as $p) {
        foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
            $reg->transition($p->id, $to, null, null, null);
        }
    }
    $t->main = $reg->addFacility($t->clinic->id, ['code' => 'MAIN', 'name' => 'Main']);
    $network = $net->createNetwork($t->tenant->id, ['code' => 'PJNET', 'name' => 'PJ network', 'network_type_code' => 'PREFERRED', 'category' => 'HEALTH', 'carrier_id' => $f['carrier']->id], null);
    $net->addMember($t->tenant->id, $network->id, ['provider_id' => $t->clinic->id, 'effective_from' => now()->subMonths(2)->toDateString()], null);
    DB::table('health_policy_networks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->tenant->id, 'policy_id' => $t->policy->id, 'provider_network_id' => $network->id, 'created_at' => now(), 'updated_at' => now()]);
    $t->contract = $net->createContract($t->tenant->id, $network->id, ['provider_id' => $t->clinic->id, 'contract_number' => 'PJ-001', 'effective_from' => now()->subMonths(2)->toDateString()], null);
    $tariff = $net->draftTariff($t->tenant->id, $t->contract->id, now()->subMonths(2)->toDateString(), 'XAF',
        [['medical_service_id' => $t->cons->id, 'price_minor' => 20000, 'contracted_price_minor' => 15000, 'copay_minor' => 3000, 'insurer_share_percent' => 80]], (string) Str::uuid());
    $net->approveTariff($t->tenant->id, $tariff->id, (string) Str::uuid());

    $t->user = pjEmployee($t, $t->clinic);
}
