<?php

declare(strict_types=1);

use App\Application\Approvals\ApprovalService;
use App\Application\CarrierOperations\Agreements\CarrierBrokerAgreementService;
use App\Application\CarrierOperations\Agreements\LegacyAgreementBackfill;
use App\Application\Configuration\ConfigurationGovernanceService;
use App\Application\Configuration\ConfigurationInheritance;
use App\Application\Configuration\PlatformSetupService;
use App\Application\Demo\DemoCoverageReport;
use App\Application\Demo\DemoEnvironment;
use App\Models\ApprovalRequest;
use App\Models\ConfigurationChangeSet;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Providers\ApprovalServiceProvider;
use App\Providers\PlatformConfigurationServiceProvider;
use Database\Seeders\DemoInstitutionalSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

beforeEach(function () {
    foreach ([ApprovalServiceProvider::class, PlatformConfigurationServiceProvider::class] as $p) {
        if (! app()->providerIsLoaded($p)) {
            app()->register($p);
        }
    }
});

function b3eUser(): User
{
    return User::create(['full_name' => 'B3E '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b3eParty(string $name): string
{
    $id = (string) Str::uuid();
    DB::table('parties')->insert(['id' => $id, 'type' => 'ORGANIZATION', 'display_name' => $name, 'legal_identity' => '{}', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function b3eCarrier(array $extra = []): string
{
    $id = (string) Str::uuid();
    DB::table('carriers')->insert(['id' => $id, 'party_id' => b3eParty('Carrier '.Str::random(4)), 'cima_code' => 'B3E-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE', 'capabilities' => '{}', 'created_at' => now(), 'updated_at' => now(), ...$extra]);

    return $id;
}

function b3ePartner(?string $tenantId = null): string
{
    $id = (string) Str::uuid();
    DB::table('partners')->insert(['id' => $id, 'tenant_id' => $tenantId, 'party_id' => b3eParty('Broker '.Str::random(4)), 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Draft → submit → approve (checker) → publish (checker) through the existing governance engine. */
function b3ePublish(ConfigurationChangeSet $cs, User $maker, User $checker): ConfigurationChangeSet
{
    $gov = app(ConfigurationGovernanceService::class);
    $cs = $gov->submit($cs, $maker);
    if ($cs->status === 'IN_REVIEW') {
        app(ApprovalService::class)->approve(ApprovalRequest::findOrFail($cs->approval_request_id), $checker);
    }

    return $gov->publish($cs->refresh(), $checker);
}

it('REQ-SET-001 exposes platform identity defaults CM / XAF / Africa-Douala / FR-EN / CIMA with a readiness checklist', function () {
    $status = app(PlatformSetupService::class)->status();
    expect($status['identity'])->toMatchArray(['country_code' => 'CM', 'currency_code' => 'XAF', 'default_timezone' => 'Africa/Douala', 'default_locale' => 'fr', 'regulatory_zone' => 'CIMA'])
        ->and($status['identity']['supported_locales'])->toBe(['fr', 'en'])
        ->and(collect($status['checklist'])->pluck('code')->all())->toContain('COUNTRY', 'CURRENCY', 'TIMEZONE', 'LANGUAGES', 'GEOGRAPHY', 'APPROVAL_MATRIX')
        ->and($status['ready'])->toBeFalse(); // no platform name yet
    expect(fn () => app(PlatformSetupService::class)->complete(b3eUser()))->toThrow(ValidationException::class);
});

it('REQ-SET-001 identity changes are governed (maker-checker) and applied to the existing platform_settings row', function () {
    [$maker, $checker] = [b3eUser(), b3eUser()];
    $svc = app(PlatformSetupService::class);
    expect(fn () => $svc->draftIdentity($maker, ['default_locale' => 'de'], 'Switch language'))->toThrow(ValidationException::class);
    expect(fn () => $svc->draftIdentity($maker, ['unknown' => 1], 'Nothing useful'))->toThrow(ValidationException::class);

    $cs = $svc->draftIdentity($maker, ['platform_name' => 'OpesInsure', 'default_locale' => 'en'], 'Name the platform');
    expect(PlatformSetting::count())->toBe(0);
    expect(fn () => app(ConfigurationGovernanceService::class)->publish($cs, $checker))->toThrow(ValidationException::class);
    b3ePublish($cs, $maker, $checker);

    $row = PlatformSetting::first();
    expect($row->platform_name)->toBe('OpesInsure')->and($row->default_locale)->toBe('en')->and($svc->identity()['platform_name'])->toBe('OpesInsure');
    expect(DB::table('audit_log')->where('action', 'configuration.published')->where('subject_id', $cs->id)->exists())->toBeTrue();
});

it('REQ-SET-004 resolves Platform → Insurer → Broker agreement → Broker → Branch → User, only more restrictive values apply', function () {
    [$maker, $checker] = [b3eUser(), b3eUser()];
    $inh = app(ConfigurationInheritance::class);
    [$carrier, $broker, $branch, $user] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
    $key = 'quote.max_discount_basis_points';

    expect($inh->resolve($key)['value'])->toBe(1500);
    b3ePublish($inh->draftOverride($maker, $key, 'PLATFORM', null, 1200, 'Platform cap'), $maker, $checker);
    b3ePublish($inh->draftOverride($maker, $key, 'INSURER', $carrier, 1000, 'Insurer cap'), $maker, $checker);
    b3ePublish($inh->draftOverride($maker, $key, 'BROKER', $broker, 800, 'Broker cap', ['INSURER' => $carrier]), $maker, $checker);

    // A branch may not loosen what the insurer/broker set.
    expect(fn () => $inh->draftOverride($maker, $key, 'BRANCH', $branch, 1100, 'Too loose', ['INSURER' => $carrier, 'BROKER' => $broker]))->toThrow(ValidationException::class);
    b3ePublish($inh->draftOverride($maker, $key, 'BRANCH', $branch, 500, 'Branch cap', ['INSURER' => $carrier, 'BROKER' => $broker]), $maker, $checker);

    $r = $inh->resolve($key, ['INSURER' => $carrier, 'BROKER' => $broker, 'BRANCH' => $branch, 'USER' => $user]);
    expect($r['value'])->toBe(500)->and($r['source_level'])->toBe('BRANCH');
    expect($inh->resolve($key, ['INSURER' => $carrier])['value'])->toBe(1000);
    expect($inh->resolve($key, [])['value'])->toBe(1200);

    // Read-time enforcement: a branch value written without the insurer context is ignored where the insurer is stricter.
    DB::table('configuration_overrides')->insert(['id' => (string) Str::uuid(), 'scope_level' => 'USER', 'scope_id' => $user, 'config_key' => $key,
        'value' => json_encode(['value' => 9000]), 'effective_from' => now()->toDateString(), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $r = $inh->resolve($key, ['INSURER' => $carrier, 'BROKER' => $broker, 'BRANCH' => $branch, 'USER' => $user]);
    expect($r['value'])->toBe(500)->and(collect($r['trace'])->firstWhere('level', 'USER')['outcome'])->toBe('IGNORED_LESS_RESTRICTIVE');
});

it('REQ-SET-004 not every value is overridable: a broker cannot redefine cover or the premium formula; flags and lists only narrow', function () {
    $inh = app(ConfigurationInheritance::class);
    $maker = b3eUser();
    expect(fn () => $inh->draftOverride($maker, 'product.coverage_definition', 'BROKER', (string) Str::uuid(), ['x' => 1], 'Redefine cover'))->toThrow(ValidationException::class);
    expect(fn () => $inh->draftOverride($maker, 'tariff.premium_formula', 'USER', (string) Str::uuid(), 'f(x)', 'Agent formula'))->toThrow(ValidationException::class);
    expect(fn () => $inh->draftOverride($maker, 'payments.allowed_methods', 'BRANCH', (string) Str::uuid(), ['CRYPTO'], 'Add crypto'))->toThrow(ValidationException::class);
    expect(fn () => $inh->draftOverride($maker, 'kyc.minimum_level', 'BRANCH', (string) Str::uuid(), 0, 'Lower KYC'))->toThrow(ValidationException::class);
    expect(fn () => $inh->draftOverride($maker, 'quote.validity_days', 'USER', (string) Str::uuid(), 10, 'User level not allowed'))->toThrow(ValidationException::class);
    expect(fn () => $inh->draftOverride($maker, 'quote.max_discount_basis_points', 'BRANCH', null, 10, 'Missing scope'))->toThrow(ValidationException::class);

    $cs = $inh->draftOverride($maker, 'payments.cash_collection_allowed', 'BRANCH', (string) Str::uuid(), false, 'No cash at this branch');
    expect($cs->config_type)->toBe(ConfigurationInheritance::CONFIG_TYPE);
    expect(fn () => $inh->assertAllowed('payments.cash_collection_allowed', 'BRANCH', 'no', []))->toThrow(ValidationException::class);
});

it('REQ-DUP-023 splits legacy delegated authority agreements into carrier_broker_agreements (distribution) + authority_limits (authority), idempotently', function () {
    [$carrier, $partner] = [b3eCarrier(), b3ePartner()];
    $legacy = (string) Str::uuid();
    DB::table('delegated_authority_agreements')->insert(['id' => $legacy, 'carrier_id' => $carrier, 'partner_id' => $partner, 'agreement_number' => 'DA-B3E-1',
        'effective_from' => now()->subMonth()->toDateString(), 'effective_until' => now()->addYear()->toDateString(), 'status' => 'ACTIVE',
        'permitted_lines' => json_encode(['MOTOR', 'TRAVEL']), 'max_policy_premium_minor' => 50_000_000, 'max_claim_authority_minor' => 2_000_000,
        'territories' => json_encode(['CM']), 'created_at' => now(), 'updated_at' => now()]);

    $backfill = app(LegacyAgreementBackfill::class);
    expect($backfill->run())->toBe(['created' => 1, 'refreshed' => 0]);
    expect($backfill->run())->toBe(['created' => 0, 'refreshed' => 1]);

    $a = DB::table('carrier_broker_agreements')->where('legacy_delegated_authority_agreement_id', $legacy)->first();
    expect($a->agreement_number)->toBe('DA-B3E-1')->and($a->status)->toBe('ACTIVE');
    expect(DB::table('carrier_broker_agreement_products')->where('agreement_id', $a->id)->orderBy('line_code')->pluck('line_code')->all())->toBe(['MOTOR', 'TRAVEL']);
    expect(DB::table('carrier_broker_agreement_products')->where('agreement_id', $a->id)->where('can_collect_premium', true)->exists())->toBeFalse();
    expect(DB::table('authority_limits')->where('carrier_broker_agreement_id', $a->id)->orderBy('authority_type')->pluck('max_amount_minor', 'authority_type')->map(fn ($v) => (int) $v)->all())
        ->toBe(['CLAIM_PAYMENT' => 2_000_000, 'POLICY_PREMIUM' => 50_000_000]);
    expect(DB::table('delegated_authority_agreements')->where('id', $legacy)->exists())->toBeTrue(); // legacy kept

    DB::table('delegated_authority_agreements')->where('id', $legacy)->update(['status' => 'SUSPENDED']);
    $backfill->run();
    expect(DB::table('carrier_broker_agreements')->where('id', $a->id)->value('status'))->toBe('SUSPENDED')
        ->and(DB::table('authority_limits')->where('carrier_broker_agreement_id', $a->id)->where('status', 'ACTIVE')->exists())->toBeFalse();
});

it('REQ-SEED-004 agreement product permissions and per-agreement commission gate quote / bind / collect, with maker-checker activation', function () {
    [$carrier, $partner] = [b3eCarrier(), b3ePartner()];
    [$maker, $checker] = [b3eUser(), b3eUser()];
    $svc = app(CarrierBrokerAgreementService::class);

    expect($svc->permits($partner, $carrier, 'MOTOR', null, 'quote')['reason'])->toBe('NO_AGREEMENT');
    $a = $svc->create($maker, ['carrier_id' => $carrier, 'partner_id' => $partner, 'agreement_number' => 'CBA-B3E-1', 'effective_from' => now()->subDay()->toDateString(), 'territories' => ['CM']]);
    expect($svc->permits($partner, $carrier, 'MOTOR', null, 'quote')['reason'])->toBe('AGREEMENT_INACTIVE');
    expect(fn () => $svc->transition($checker, $a->id, 'ACTIVE', 'No products yet'))->toThrow(ValidationException::class);

    $svc->setProduct($maker, $a->id, ['line_code' => 'MOTOR', 'can_quote' => true, 'can_bind' => true, 'can_collect_premium' => false, 'requires_carrier_approval' => true, 'commission_basis_points' => 1200]);
    expect(fn () => $svc->setProduct($maker, $a->id, ['line_code' => 'TRAVEL', 'can_quote' => false, 'can_bind' => true]))->toThrow(ValidationException::class);
    expect(fn () => $svc->transition($maker, $a->id, 'ACTIVE', 'Self activation'))->toThrow(ValidationException::class);
    $svc->transition($checker, $a->id, 'ACTIVE', 'Carrier signed');

    expect($svc->permits($partner, $carrier, 'MOTOR', null, 'quote'))->toMatchArray(['allowed' => true, 'requires_carrier_approval' => true, 'commission_basis_points' => 1200]);
    expect($svc->permits($partner, $carrier, 'MOTOR', null, 'collect_premium')['reason'])->toBe('COLLECT_PREMIUM_NOT_PERMITTED');
    expect($svc->permits($partner, $carrier, 'HEALTH', null, 'quote')['reason'])->toBe('PRODUCT_NOT_AUTHORISED');
    expect($svc->permits($partner, $carrier, 'MOTOR', null, 'bind', now()->subYear()->toDateString())['reason'])->toBe('OUTSIDE_EFFECTIVE_PERIOD');

    // No global commission: a line without its own rate or rule has none.
    $svc->setProduct($maker, $a->id, ['line_code' => 'TRAVEL', 'can_quote' => true]);
    expect($svc->permits($partner, $carrier, 'TRAVEL', null, 'quote')['commission_basis_points'])->toBeNull();

    // Database guards: commission ≤ 100%, bind implies quote.
    expect(fn () => DB::table('carrier_broker_agreement_products')->where('agreement_id', $a->id)->where('line_code', 'MOTOR')->update(['commission_basis_points' => 20000]))->toThrow(QueryException::class);
});

it('REQ-SEED-004 exposes agreements over the API with permissions and tenant scoping', function () {
    $tenant = makeAuthTestTenant();
    $admin = makeAuthTestUser($tenant, ['distribution.agreements.view', 'distribution.agreements.manage', 'distribution.agreements.approve']);
    $checker = makeAuthTestUser($tenant, ['distribution.agreements.approve']);
    [$carrier, $partner, $foreign] = [b3eCarrier(), b3ePartner($tenant->id), b3ePartner()];
    $h = tenantHeader($tenant);

    Passport::actingAs(makeAuthTestUser($tenant, ['partners.read']));
    $this->getJson('/api/v1/carrier-broker-agreements', $h)->assertForbidden();

    Passport::actingAs($admin);
    $this->postJson('/api/v1/carrier-broker-agreements', ['carrier_id' => $carrier, 'partner_id' => $foreign, 'agreement_number' => 'X-1', 'effective_from' => now()->toDateString()], $h)->assertNotFound();
    $id = $this->postJson('/api/v1/carrier-broker-agreements', ['carrier_id' => $carrier, 'partner_id' => $partner, 'agreement_number' => 'API-B3E-1', 'effective_from' => now()->toDateString()], $h)
        ->assertCreated()->json('data.id');
    $this->putJson("/api/v1/carrier-broker-agreements/$id/products", ['line_code' => 'MOTOR', 'can_quote' => true, 'can_bind' => true, 'commission_basis_points' => 1000], $h)->assertOk();
    $this->postJson("/api/v1/carrier-broker-agreements/$id/activate", ['reason' => 'Self approve'], $h)->assertUnprocessable();

    Passport::actingAs($checker);
    $this->postJson("/api/v1/carrier-broker-agreements/$id/activate", ['reason' => 'Carrier signed'], $h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');

    Passport::actingAs($admin);
    $this->getJson("/api/v1/carrier-broker-agreements/permits?partner_id=$partner&carrier_id=$carrier&line_code=MOTOR&action=bind", $h)
        ->assertOk()->assertJsonPath('data.allowed', true)->assertJsonPath('data.commission_basis_points', 1000);
    expect($this->getJson('/api/v1/carrier-broker-agreements', $h)->assertOk()->json('data'))->toHaveCount(1);
});

it('REQ-SEED-005 REQ-SEED-001 seeds the flagged demo brokerage, 5 branches and demo agreements, and reports coverage from demo rows only', function () {
    config(['demo.enabled' => true]);
    b3eCarrier(['is_official_register' => true, 'licence_branch' => 'IARD', 'insurer_code' => 'B3E_IARD', 'regulator_sequence' => 1, 'canonical_id' => 'CM-INS-IARD-B3E']);

    $this->seed(DemoInstitutionalSeeder::class);
    $this->seed(DemoInstitutionalSeeder::class); // idempotent

    $partner = DB::table('partners')->where('canonical_id', DemoInstitutionalSeeder::CANONICAL_ID)->first();
    expect($partner->is_demo)->toBeTrue()->and($partner->data_origin)->toBe('DEMO_SYNTHETIC')->and($partner->licence_number)->toBeNull();
    $tenant = DB::table('tenants')->where('slug', DemoInstitutionalSeeder::TENANT_SLUG)->first();
    expect($tenant->is_demo)->toBeTrue();
    expect(DB::table('tenant_branches')->where('tenant_id', $tenant->id)->where('is_demo', true)->count())->toBe(5);
    expect(DB::table('tenant_branches')->where('tenant_id', $tenant->id)->where('email', 'not like', '%.invalid')->exists())->toBeFalse();
    $agreements = DB::table('carrier_broker_agreements')->where('partner_id', $partner->id)->get();
    expect($agreements)->toHaveCount(1)->and($agreements->every(fn ($a) => $a->is_demo === true && $a->data_origin === 'DEMO_SYNTHETIC'))->toBeTrue();

    $report = app(DemoCoverageReport::class)->build();
    expect($report['institutional'])->toBe(['demo_brokerage' => true, 'demo_branches' => 5, 'demo_agreements' => 1])
        ->and($report['labels']['watermark'])->toBe(DemoEnvironment::DOCUMENT_WATERMARK)
        ->and($report['gaps'])->toContain('END_TO_END_CHAIN_CUS_DEMO_0001_MISSING')
        ->and($report['environment']['banner_text'])->toBe(DemoEnvironment::BANNER_TEXT);
});

it('REQ-SEED-005 refuses the demo institutional layer where demo seeding is not allowed', function () {
    config(['demo.enabled' => false]);
    $this->seed(DemoInstitutionalSeeder::class);
    expect(DB::table('partners')->where('canonical_id', DemoInstitutionalSeeder::CANONICAL_ID)->exists())->toBeFalse();
});

it('REQ-SET-001 REQ-SET-004 serves setup and inheritance endpoints behind permissions', function () {
    $tenant = makeAuthTestTenant();
    $h = tenantHeader($tenant);
    Passport::actingAs(makeAuthTestUser($tenant, ['platform.settings.manage', 'configuration.changes.manage']));
    $this->getJson('/api/v1/platform/setup', $h)->assertOk()->assertJsonPath('data.identity.currency_code', 'XAF');
    $this->getJson('/api/v1/configuration/inheritance', $h)->assertOk()->assertJsonPath('data.levels.0', 'PLATFORM');
    $this->getJson('/api/v1/configuration/inheritance/resolve?key=kyc.minimum_level', $h)->assertOk()->assertJsonPath('data.value', 1);
    $this->postJson('/api/v1/configuration/inheritance/overrides', ['key' => 'kyc.minimum_level', 'scope_level' => 'BRANCH', 'scope_id' => (string) Str::uuid(), 'value' => 2, 'reason' => 'Stricter KYC', 'dry_run' => true], $h)
        ->assertOk()->assertJsonPath('data.allowed', true);
    $this->postJson('/api/v1/configuration/inheritance/overrides', ['key' => 'kyc.minimum_level', 'scope_level' => 'BRANCH', 'scope_id' => (string) Str::uuid(), 'value' => 0, 'reason' => 'Looser KYC'], $h)
        ->assertUnprocessable();
    $this->getJson('/api/v1/demo/coverage', $h)->assertOk()->assertJsonStructure(['data' => ['institutional', 'states', 'gaps', 'labels']]);

    Passport::actingAs(makeAuthTestUser($tenant, ['partners.read']));
    $this->getJson('/api/v1/platform/setup', $h)->assertForbidden();
});
