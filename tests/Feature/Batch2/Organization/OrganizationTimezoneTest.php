<?php

declare(strict_types=1);

use App\Application\Partners\AgentHierarchyService;
use App\Application\Settings\FeatureFlags;
use App\Application\Settings\PlatformSettings;
use App\Application\Temporal\BusinessCalendar;
use App\Application\Temporal\BusinessTime;
use App\Application\Temporal\OffsetAwarePostgresConnection;
use App\Application\Temporal\ReferenceDateResolver;
use App\Application\Temporal\TimezoneResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use App\Models\Party;
use App\Models\TenantBranch;
use App\Providers\OrganizationServiceProvider;
use App\Providers\TemporalServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(TemporalServiceProvider::class);
    $this->app->register(OrganizationServiceProvider::class);
    // Master data geography stand-in (the real seed holds ~250 countries).
    $domainId = DB::table('master_data_domains')->where('code', 'geography')->value('id');
    if (! $domainId) {
        $domainId = (string) Str::uuid();
        DB::table('master_data_domains')->insert(['id' => $domainId, 'code' => 'geography', 'label_en' => 'Geography', 'label_fr' => 'Géographie']);
    }
    $listId = DB::table('master_data_lists')->where(['domain_code' => 'geography', 'code' => 'country'])->value('id');
    if (! $listId) {
        $listId = (string) Str::uuid();
        DB::table('master_data_lists')->insert(['id' => $listId, 'domain_id' => $domainId, 'domain_code' => 'geography', 'code' => 'country', 'label_en' => 'Country', 'label_fr' => 'Pays']);
    }
    DB::table('master_data_values')->where(['domain_code' => 'geography', 'list_code' => 'country'])->delete();
    foreach ([['CM', ['Africa/Douala']], ['NG', ['Africa/Lagos']], ['FR', ['Europe/Paris']], ['US', ['America/New_York', 'America/Chicago']]] as $i => [$code, $tzs]) {
        DB::table('master_data_values')->insert(['id' => (string) Str::uuid(), 'list_id' => $listId, 'domain_code' => 'geography', 'list_code' => 'country', 'code' => $code, 'label_en' => $code, 'label_fr' => $code, 'attributes' => json_encode(['timezones' => $tzs]), 'sort_order' => $i, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->tenant = makeAuthTestTenant('Org');
    $this->admin = makeAuthTestUser($this->tenant, ['tenant.manage', 'partners.manage', 'partners.read']);
    app(TenantContext::class)->set($this->tenant->id);
});

function b2dBranch(string $tenantId, ?string $tz, string $code = 'HQ'): TenantBranch
{
    return TenantBranch::create(['tenant_id' => $tenantId, 'code' => $code.Str::random(4), 'name' => 'Branch '.$code, 'status' => 'ACTIVE', 'timezone' => $tz, 'address' => []]);
}

function b2dAgent(string $tenantId, string $status = 'ACTIVE'): Partner
{
    $party = Party::create(['type' => 'PERSON', 'display_name' => 'Agent '.Str::random(5), 'legal_identity' => [], 'status' => 'ACTIVE']);

    return Partner::create(['tenant_id' => $tenantId, 'party_id' => $party->id, 'type' => 'AGENT', 'status' => $status, 'compliance' => []]);
}

// ---------------------------------------------------------------- REQ-TMP-003

it('REQ-TMP-003 resolves branch > tenant > platform > Africa/Douala', function () {
    $r = app(TimezoneResolver::class);
    expect($r->forTenant($this->tenant->id))->toBe('Africa/Douala');

    DB::table('tenants')->where('id', $this->tenant->id)->update(['timezone' => 'Europe/Paris']);
    $r->forget();
    $inherit = b2dBranch($this->tenant->id, null, 'A');
    $own = b2dBranch($this->tenant->id, 'America/New_York', 'B');

    expect($r->forTenant($this->tenant->id))->toBe('Europe/Paris')
        ->and($r->forBranch($inherit->id))->toBe('Europe/Paris')
        ->and($r->forBranch($own->id))->toBe('America/New_York')
        ->and($r->current())->toBe('Europe/Paris')
        ->and($r->explain($this->tenant->id, $own->id)['source'])->toBe('BRANCH');
});

it('REQ-TMP-003 platform default comes from platform settings', function () {
    $row = app(PlatformSettings::class)->editable();
    $row->default_timezone = 'Africa/Lagos';
    $row->save();
    app(PlatformSettings::class)->flush();

    expect(app(TimezoneResolver::class)->forTenant($this->tenant->id))->toBe('Africa/Lagos');
});

it('REQ-TMP-003 BusinessTime and BusinessCalendar use the tenant timezone, not a hard-coded Douala', function () {
    // 23:30Z on 31 Dec: 1 Jan in Douala (UTC+1), still 31 Dec in New York.
    expect(BusinessTime::businessDate('2026-12-31T23:30:00Z'))->toBe('2027-01-01');

    DB::table('tenants')->where('id', $this->tenant->id)->update(['timezone' => 'America/New_York']);
    app(TimezoneResolver::class)->forget();
    expect(BusinessTime::businessDate('2026-12-31T23:30:00Z'))->toBe('2026-12-31')
        ->and(BusinessTime::parse('2026-03-01')->toIso8601String())->toBe('2026-03-01T00:00:00-05:00');

    $branch = b2dBranch($this->tenant->id, 'Europe/Paris');
    $tz = app(TimezoneResolver::class)->forBranch($branch->id);
    // Friday 23:30Z = Saturday 00:30 Paris → not a business day in Paris.
    expect(app(BusinessCalendar::class)->isBusinessDay('2026-10-02T23:30:00Z', 'CM', $tz))->toBeFalse()
        ->and(app(BusinessCalendar::class)->isBusinessDay('2026-10-02T23:30:00Z', 'CM', 'America/New_York'))->toBeTrue();
});

it('REQ-TMP-003 reference dates resolve in the subject tenant/branch timezone', function () {
    $branch = b2dBranch($this->tenant->id, 'America/Chicago');
    $i = app(ReferenceDateResolver::class)->for('BIND', ['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id], 'AUTHORITY');
    expect($i->timezone)->toBe('America/Chicago');
});

it('REQ-TMP-003 timestamptz stores the true instant regardless of the session timezone', function () {
    expect(DB::connection())->toBeInstanceOf(OffsetAwarePostgresConnection::class);
    DB::statement("SET TIME ZONE 'UTC'");
    $at = \Carbon\CarbonImmutable::parse('2026-09-25 10:00:00', 'Africa/Douala'); // = 09:00Z
    $user = makeAuthTestUser($this->tenant, []);
    DB::table('users')->where('id', $user->id)->update(['email_verified_at' => $at]);
    $user->forceFill(['phone_verified_at' => $at])->save();

    $rows = DB::selectOne("select to_char(email_verified_at at time zone 'UTC','HH24:MI') e, to_char(phone_verified_at at time zone 'UTC','HH24:MI') p from users where id = ?", [$user->id]);
    expect($rows->e)->toBe('09:00')->and($rows->p)->toBe('09:00')
        ->and($user->fresh()->phone_verified_at->equalTo($at))->toBeTrue();
    DB::statement("SET TIME ZONE '".config('app.timezone')."'");
});

it('REQ-TMP-003 timezone catalogue comes from master data geography', function () {
    Passport::actingAs($this->admin);
    $res = $this->getJson('/api/v1/settings/timezones', tenantHeader($this->tenant))->assertOk();
    expect(collect($res->json('data'))->pluck('timezone')->all())->toBe(['Africa/Douala', 'Africa/Lagos', 'America/Chicago', 'America/New_York', 'Europe/Paris'])
        ->and($res->json('meta.source'))->toBe('MASTER_DATA_GEOGRAPHY');
});

it('REQ-TMP-003 user display timezone API', function () {
    Passport::actingAs($this->admin);
    $h = tenantHeader($this->tenant);
    $this->getJson('/api/v1/me/settings', $h)->assertOk()->assertJsonPath('data.effective_display_timezone', 'Africa/Douala');
    $this->patchJson('/api/v1/me/settings', ['display_timezone' => 'Europe/Paris'], $h)->assertOk()
        ->assertJsonPath('data.display_timezone', 'Europe/Paris')->assertJsonPath('data.business_timezone', 'Africa/Douala');
    $this->patchJson('/api/v1/me/settings', ['display_timezone' => 'Asia/Tokyo'], $h)->assertUnprocessable(); // not in master data
    $this->patchJson('/api/v1/me/settings', ['display_timezone' => null], $h)->assertOk()->assertJsonPath('data.effective_display_timezone', 'Africa/Douala');
});

it('REQ-TMP-003 tenant timezone API is permission-guarded and audited', function () {
    $h = tenantHeader($this->tenant);
    Passport::actingAs(makeAuthTestUser($this->tenant, ['partners.read']));
    $this->patchJson('/api/v1/organization/settings', ['timezone' => 'Europe/Paris'], $h)->assertForbidden();

    Passport::actingAs($this->admin);
    $this->patchJson('/api/v1/organization/settings', ['timezone' => 'Europe/Paris'], $h)->assertOk()
        ->assertJsonPath('data.timezone', 'Europe/Paris')->assertJsonPath('data.source', 'TENANT');
    $this->assertDatabaseHas('tenants', ['id' => $this->tenant->id, 'timezone' => 'Europe/Paris']);
    $this->assertDatabaseHas('audit_log', ['action' => 'tenant.timezone.changed']);
});

it('REQ-TMP-003 platform default timezone API only from the platform tenant', function () {
    $platform = makeAuthTestTenant('Platform');
    DB::table('tenants')->where('id', $platform->id)->update(['type' => 'PLATFORM']);
    Passport::actingAs($this->admin);
    $this->patchJson('/api/v1/admin/platform/settings/timezone', ['default_timezone' => 'Africa/Lagos'], tenantHeader($this->tenant))->assertForbidden();

    $pa = makeAuthTestUser($platform, ['platform.settings.manage']);
    Passport::actingAs($pa);
    $this->patchJson('/api/v1/admin/platform/settings/timezone', ['default_timezone' => 'Africa/Lagos'], tenantHeader($platform))->assertOk()
        ->assertJsonPath('data.default_timezone', 'Africa/Lagos');
});

// ---------------------------------------------------------------- REQ-TEN-001

it('REQ-TEN-001 tenants.type is constrained to the SCF kinds', function () {
    foreach (['INSURER', 'REINSURER', 'PROVIDER_NETWORK', 'CORPORATE'] as $type) {
        DB::table('tenants')->where('id', $this->tenant->id)->update(['type' => $type]);
    }
    expect(fn () => DB::table('tenants')->where('id', $this->tenant->id)->update(['type' => 'BANANA']))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------- REQ-TEN-002

it('REQ-TEN-002 branch hierarchy, capabilities and departments', function () {
    Passport::actingAs($this->admin);
    $h = tenantHeader($this->tenant);
    $hq = b2dBranch($this->tenant->id, 'Africa/Douala', 'HQ');
    $sub = b2dBranch($this->tenant->id, null, 'SUB');

    $this->patchJson("/api/v1/organization/branches/{$sub->id}", ['parent_branch_id' => $hq->id, 'capabilities' => ['SALES', 'CLAIMS']], $h)->assertOk();
    $this->patchJson("/api/v1/organization/branches/{$hq->id}", ['parent_branch_id' => $sub->id], $h)->assertUnprocessable(); // cycle
    $this->patchJson("/api/v1/organization/branches/{$sub->id}", ['capabilities' => ['TELEPORT']], $h)->assertUnprocessable();

    $other = makeAuthTestTenant('Other');
    $foreign = b2dBranch($other->id, null, 'X');
    $this->patchJson("/api/v1/organization/branches/{$sub->id}", ['parent_branch_id' => $foreign->id], $h)->assertUnprocessable();
    $this->patchJson("/api/v1/organization/branches/{$foreign->id}", ['name' => 'Hijack'], $h)->assertNotFound();

    $this->postJson('/api/v1/organization/departments', ['code' => 'CLAIMS', 'name' => 'Claims desk', 'branch_id' => $sub->id, 'queue_code' => 'CLAIMS_Q', 'limits' => ['claim_payment_minor' => 500000]], $h)->assertCreated();
    $this->postJson('/api/v1/organization/departments', ['code' => 'CLAIMS', 'name' => 'Dup'], $h)->assertUnprocessable();

    $tree = $this->getJson('/api/v1/organization/branches/tree', $h)->assertOk()->json('data.branches');
    expect($tree)->toHaveCount(1)
        ->and($tree[0]['code'])->toBe($hq->code)
        ->and($tree[0]['children'][0]['capabilities'])->toBe(['SALES', 'CLAIMS'])
        ->and($tree[0]['children'][0]['effective_timezone'])->toBe('Africa/Douala')
        ->and($tree[0]['children'][0]['departments'][0]->code ?? $tree[0]['children'][0]['departments'][0]['code'])->toBe('CLAIMS');
});

// ---------------------------------------------------------------- REQ-ORG-001

it('REQ-ORG-001 agent hierarchy with sub-agents and cycle protection', function () {
    $svc = app(AgentHierarchyService::class);
    $branch = b2dBranch($this->tenant->id, null);
    $sup = b2dAgent($this->tenant->id);
    $agent = b2dAgent($this->tenant->id);
    $subAgent = b2dAgent($this->tenant->id);

    $svc->place($sup, ['branch_id' => $branch->id, 'agent_type' => 'EMPLOYEE'], 'ONBOARDING', $this->admin);
    $svc->place($agent, ['supervisor_partner_id' => $sup->id, 'agent_type' => 'INDEPENDENT'], 'ONBOARDING', $this->admin);
    $svc->place($subAgent, ['supervisor_partner_id' => $agent->id, 'agent_type' => 'SUB_AGENT'], 'ONBOARDING', $this->admin);

    expect(fn () => $svc->place($sup, ['supervisor_partner_id' => $subAgent->id], 'LOOP', $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $svc->place(b2dAgent($this->tenant->id), ['agent_type' => 'SUB_AGENT'], 'X', $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $svc->place($agent, ['supervisor_partner_id' => b2dAgent(makeAuthTestTenant('F')->id)->id], 'X', $this->admin))->toThrow(ValidationException::class);

    $down = $svc->downline($sup);
    expect($down[0]['id'])->toBe($agent->id)->and($down[0]['reports'][0]['id'])->toBe($subAgent->id);
    $this->assertDatabaseHas('partner_hierarchy_history', ['partner_id' => $subAgent->id, 'to_supervisor_partner_id' => $agent->id]);
});

it('REQ-ORG-001 WF-080 suspension keeps references and re-points direct reports', function () {
    Passport::actingAs($this->admin);
    $h = tenantHeader($this->tenant);
    $svc = app(AgentHierarchyService::class);
    $sup = b2dAgent($this->tenant->id);
    $agent = b2dAgent($this->tenant->id);
    $subAgent = b2dAgent($this->tenant->id);
    $svc->place($agent, ['supervisor_partner_id' => $sup->id], 'ONBOARDING', $this->admin);
    $svc->place($subAgent, ['supervisor_partner_id' => $agent->id, 'agent_type' => 'SUB_AGENT'], 'ONBOARDING', $this->admin);

    $res = $this->postJson("/api/v1/partners/{$agent->id}/suspension", ['notes' => 'Licence lapsed pending renewal'], $h)->assertOk();
    expect($res->json('data.reassigned_partner_ids'))->toBe([$subAgent->id]);

    $this->assertDatabaseHas('partners', ['id' => $agent->id, 'status' => 'SUSPENDED', 'supervisor_partner_id' => $sup->id]);
    $this->assertDatabaseHas('partners', ['id' => $subAgent->id, 'supervisor_partner_id' => $sup->id]);
    $this->assertDatabaseHas('partner_status_history', ['partner_id' => $agent->id, 'to_status' => 'SUSPENDED']);
    $this->assertDatabaseHas('partner_hierarchy_history', ['partner_id' => $subAgent->id, 'reason_code' => 'SUPERVISOR_SUSPENDED']);

    // Suspended supervisor cannot receive new reports.
    $this->patchJson("/api/v1/partners/{$subAgent->id}/hierarchy", ['supervisor_partner_id' => $agent->id, 'reason_code' => 'MOVE'], $h)->assertUnprocessable();
});

// ---------------------------------------------------------------- REQ-SEC-004

it('REQ-SEC-004 most specific feature flag scope wins', function () {
    $flags = app(FeatureFlags::class);
    $branch = b2dBranch($this->tenant->id, null);
    $flags->set(['key' => 'motor.quick_quote', 'enabled' => true], null);
    $flags->set(['key' => 'motor.quick_quote', 'enabled' => false, 'tenant_id' => $this->tenant->id], null);
    $flags->set(['key' => 'motor.quick_quote', 'enabled' => true, 'branch_id' => $branch->id], null);
    $flags->set(['key' => 'motor.quick_quote', 'enabled' => false, 'product_code' => 'MOTOR_TPL'], null);

    $other = makeAuthTestTenant('O2');
    expect($flags->enabled('motor.quick_quote', ['tenant_id' => $other->id]))->toBeTrue()
        ->and($flags->enabled('motor.quick_quote', ['tenant_id' => $this->tenant->id]))->toBeFalse()
        ->and($flags->enabled('motor.quick_quote', ['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id]))->toBeTrue()
        ->and($flags->enabled('motor.quick_quote', ['tenant_id' => $other->id, 'product_code' => 'MOTOR_TPL']))->toBeFalse()
        ->and($flags->enabled('unknown.flag'))->toBeFalse()
        ->and($flags->enabled('motor.quick_quote', ['environment' => 'production', 'tenant_id' => $other->id]))->toBeTrue();

    // Upsert on the same scope updates rather than duplicates.
    $flags->set(['key' => 'motor.quick_quote', 'enabled' => false], null);
    expect(DB::table('feature_flags')->where('key', 'motor.quick_quote')->count())->toBe(4)
        ->and($flags->enabled('motor.quick_quote', ['tenant_id' => $other->id]))->toBeFalse();
});

it('REQ-SEC-004 non-platform tenants can only set their own scope', function () {
    Passport::actingAs($this->admin);
    $h = tenantHeader($this->tenant);
    $other = makeAuthTestTenant('O3');
    $this->putJson('/api/v1/admin/feature-flags', ['key' => 'claims.fast_track', 'enabled' => true, 'tenant_id' => $other->id], $h)->assertForbidden();
    $this->putJson('/api/v1/admin/feature-flags', ['key' => 'claims.fast_track', 'enabled' => true], $h)->assertOk()->assertJsonPath('data.tenant_id', $this->tenant->id);
    $this->getJson('/api/v1/feature-flags/evaluate?key=claims.fast_track', $h)->assertOk()->assertJsonPath('data.enabled', true);
});
