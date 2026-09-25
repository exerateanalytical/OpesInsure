<?php

declare(strict_types=1);

use App\Models\CustomerAttribution;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function crmH(Tenant $t): array
{
    return ['X-Tenant-Id' => $t->id, 'Idempotency-Key' => (string) Str::uuid()];
}

function crmStaff(Tenant $t, string $phone, array $perms): User
{
    $u = User::create(['full_name' => 'CRM Ops', 'phone_e164' => $phone, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'CRM_OPS', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'CRM_OPS_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

function crmPartner(Tenant $t, string $type = 'AGENT', string $status = 'ACTIVE'): Partner
{
    return Partner::create(['tenant_id' => $t->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => $type.' '.Str::random(4), 'status' => 'ACTIVE'])->id, 'type' => $type, 'status' => $status, 'compliance' => []]);
}

const CRM_ALL = ['crm.leads.read', 'crm.leads.manage', 'crm.leads.assign', 'attribution.read', 'attribution.transfer', 'beneficiaries.read', 'beneficiaries.manage'];

// ------------------------------------------------------------ REQ-CRM-001

it('REQ-CRM-001 runs the full pipeline NEW→CONTACTED→QUALIFIED→QUOTE→NEGOTIATION and refuses skips and manual WON', function () {
    $a = makeMobileAgentFixture('+237680004001');
    Passport::actingAs($a['user']);
    $h = crmH($a['tenant']);
    $id = $this->postJson('/api/v1/mobile/partner/agent/leads', ['full_name' => 'Pipe Line', 'phone_e164' => '+237690004001'], $h)->assertStatus(201)->json('data.id');

    $this->patchJson("/api/v1/mobile/partner/agent/leads/{$id}", ['status' => 'QUOTE'], crmH($a['tenant']))->assertStatus(422);
    foreach (['CONTACTED', 'QUALIFIED', 'QUOTE', 'NEGOTIATION'] as $s) {
        $this->patchJson("/api/v1/mobile/partner/agent/leads/{$id}", ['status' => $s], crmH($a['tenant']))->assertStatus(200)->assertJsonPath('data.status', $s);
    }
    $this->patchJson("/api/v1/mobile/partner/agent/leads/{$id}", ['status' => 'CONVERTED'], crmH($a['tenant']))->assertStatus(422);
    $this->postJson("/api/v1/mobile/partner/agent/leads/{$id}/convert", ['consent_confirmed' => true], crmH($a['tenant']))->assertStatus(201)->assertJsonPath('data.lead.status', 'CONVERTED');
    expect(DB::table('partner_leads')->find($id)->assigned_user_id)->toBe($a['user']->id);
});

it('REQ-CRM-001 logs agent lead activities in the case diary; follow-ups appear in My Work', function () {
    $a = makeMobileAgentFixture('+237680004002');
    Passport::actingAs($a['user']);
    $id = $this->postJson('/api/v1/mobile/partner/agent/leads', ['full_name' => 'Follow Me', 'phone_e164' => '+237690004002'], crmH($a['tenant']))->json('data.id');

    $this->postJson("/api/v1/mobile/partner/agent/leads/{$id}/activities", ['entry_type' => 'FOLLOW_UP', 'body' => 'Call back'], crmH($a['tenant']))->assertStatus(422);
    $this->postJson("/api/v1/mobile/partner/agent/leads/{$id}/activities", ['entry_type' => 'FOLLOW_UP', 'body' => 'Call back Tuesday', 'follow_up_at' => now()->addDay()->toIso8601String()], crmH($a['tenant']))->assertStatus(201);
    $this->postJson("/api/v1/mobile/partner/agent/leads/{$id}/activities", ['entry_type' => 'CALL', 'body' => 'First call'], crmH($a['tenant']))->assertStatus(201);
    $this->getJson("/api/v1/mobile/partner/agent/leads/{$id}/activities", crmH($a['tenant']))->assertStatus(200)->assertJsonCount(2, 'data');

    $row = DB::table('diary_entries')->where('subject_type', 'partner_lead')->where('subject_id', $id)->whereNotNull('follow_up_at')->first();
    expect($row)->not->toBeNull()->and($row->case_id)->toBeNull();

    // Another agent cannot see or write the activities.
    $b = makeMobileAgentFixtureInTenant($a['tenant'], '+237680004003');
    Passport::actingAs($b['user']);
    $this->getJson("/api/v1/mobile/partner/agent/leads/{$id}/activities", crmH($a['tenant']))->assertStatus(404);
});

it('REQ-CRM-001 tenant directory: explicit, least-loaded and reassignment with history; LOST needs a reason', function () {
    $fx = makeMobileAgentFixture('+237680004010');
    $t = $fx['tenant'];
    $busy = crmPartner($t);
    $idle = crmPartner($t);
    crmPartner($t, 'AGENT', 'SUSPENDED');
    foreach ([$busy, $fx['partner']] as $p) {
        DB::table('partner_leads')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'partner_id' => $p->id, 'full_name' => 'x', 'phone_e164' => '+237690000000', 'status' => 'NEW', 'created_by' => $fx['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $ops = crmStaff($t, '+237670004010', CRM_ALL);
    Passport::actingAs($ops);
    $auto = $this->postJson('/api/v1/crm/leads', ['full_name' => 'Auto', 'phone_e164' => '+237690004010', 'auto_assign' => true, 'source' => 'web'], crmH($t))->assertStatus(201)->json('data');
    expect($auto['partner_id'])->toBe($idle->id)->and($auto['source'])->toBe('WEB');

    $this->postJson("/api/v1/crm/leads/{$auto['id']}/assign", ['partner_id' => $busy->id, 'assigned_user_id' => $ops->id, 'reason' => 'Territory'], crmH($t))->assertStatus(200)->assertJsonPath('data.partner_id', $busy->id);
    $show = $this->getJson("/api/v1/crm/leads/{$auto['id']}", crmH($t))->assertStatus(200)->json('data');
    expect($show['assignments'])->toHaveCount(2)->and($show['assignments'][0]['rule'])->toBe('LEAST_LOADED')->and($show['assignments'][1]['to_partner_id'])->toBe($busy->id);

    $this->postJson("/api/v1/crm/leads/{$auto['id']}/transitions", ['status' => 'LOST'], crmH($t))->assertStatus(422);
    $this->postJson("/api/v1/crm/leads/{$auto['id']}/transitions", ['status' => 'LOST', 'lost_reason' => 'Chose competitor'], crmH($t))->assertStatus(200)->assertJsonPath('data.status', 'LOST');
    $this->postJson("/api/v1/crm/leads/{$auto['id']}/assign", ['partner_id' => $idle->id, 'reason' => 'x'], crmH($t))->assertStatus(422);
    $this->getJson('/api/v1/crm/leads?status=LOST', crmH($t))->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('meta.pipeline.LOST', 1);
});

it('REQ-CRM-001 a broker only sees and assigns inside its own firm', function () {
    $br = makeMobilePartnerFixture('BROKER', '+237670004020');
    Role::where('tenant_id', $br['tenant']->id)->where('code', 'BROKER_STAFF')->first()->update(['permissions' => [...\App\Application\Identity\RoleCatalogue::defaultPermissions('BROKER_STAFF'), 'crm.leads.read', 'crm.leads.manage', 'crm.leads.assign']]);
    $rival = crmPartner($br['tenant'], 'BROKER');
    DB::table('partner_leads')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $br['tenant']->id, 'partner_id' => $rival->id, 'full_name' => 'Rival lead', 'phone_e164' => '+237690004021', 'status' => 'NEW', 'created_by' => $br['user']->id, 'created_at' => now(), 'updated_at' => now()]);

    Passport::actingAs($br['user']);
    $mine = $this->postJson('/api/v1/crm/leads', ['full_name' => 'My lead', 'phone_e164' => '+237690004022', 'partner_id' => $rival->id], crmH($br['tenant']))->assertStatus(201)->json('data');
    expect($mine['partner_id'])->toBe($br['partner']->id)->and($mine['assigned_user_id'])->toBe($br['user']->id);
    $this->getJson('/api/v1/crm/leads', crmH($br['tenant']))->assertStatus(200)->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/crm/leads/{$mine['id']}/assign", ['partner_id' => $rival->id, 'reason' => 'x'], crmH($br['tenant']))->assertStatus(422);
});

it('REQ-CRM-001 refuses the directory without crm permissions', function () {
    $a = makeMobileAgentFixture('+237680004030');
    Passport::actingAs($a['user']);
    $this->getJson('/api/v1/crm/leads', crmH($a['tenant']))->assertStatus(403);
});

// ------------------------------------------------------------ REQ-CRM-003

it('REQ-CRM-003 previews and transfers a portfolio, excluding disputed customers, with per-customer history', function () {
    $a = makeMobileAgentFixture('+237680004040');
    $t = $a['tenant'];
    $to = crmPartner($t);
    $ops = crmStaff($t, '+237670004040', CRM_ALL);
    $chains = [];
    foreach ([1, 2, 3] as $i) {
        $c = makeMobileFinanceProposalChain($t);
        $att = CustomerAttribution::create(['party_id' => $c['party']->id, 'partner_id' => $a['partner']->id, 'origin_type' => 'AGENT', 'terms_version' => 't1', 'effective_from' => now()->subYear(), 'status' => 'ACTIVE', 'recorded_by' => $ops->id]);
        $policy = makeMobileTestPolicy($c['proposal'], $t, $c['carrier']->id, $c['party']->id, ['policy_number' => 'POL-CRM-'.$i, 'status' => 'ACTIVE', 'issued_at' => now()->subMonth()]);
        $chains[$i] = compact('c', 'att', 'policy');
    }
    $chains[3]['att']->disputes()->create(['reason_code' => 'X', 'description' => 'd', 'status' => 'OPEN', 'evidence' => []]);

    Passport::actingAs($ops);
    $body = ['from_partner_id' => $a['partner']->id, 'to_partner_id' => $to->id];
    $preview = $this->postJson('/api/v1/crm/portfolio-transfers/preview', $body, crmH($t))->assertStatus(200)->json('data');
    expect($preview['customer_count'])->toBe(2)->and($preview['excluded'])->toHaveCount(1)->and($preview['policy_count'])->toBe(2);

    $this->postJson('/api/v1/crm/portfolio-transfers', $body + ['preview_hash' => str_repeat('0', 64), 'reason_code' => 'PARTNER_EXIT'], crmH($t))->assertStatus(422);
    $done = $this->postJson('/api/v1/crm/portfolio-transfers', $body + ['preview_hash' => $preview['preview_hash'], 'reason_code' => 'PARTNER_EXIT'], crmH($t))->assertStatus(201)->json('data');
    expect($done['customer_count'])->toBe(2);

    expect($chains[1]['att']->refresh()->partner_id)->toBe($to->id)->and($chains[3]['att']->refresh()->partner_id)->toBe($a['partner']->id);
    $hist = $this->getJson('/api/v1/crm/customers/'.$chains[1]['c']['party']->id.'/attribution-history', crmH($t))->assertStatus(200)->json('data.events');
    expect(collect($hist)->pluck('type')->all())->toContain('TRANSFERRED');

    // Commission-ready: the already-issued policy stays with the producing agent.
    $pa = $this->getJson('/api/v1/crm/policies/'.$chains[1]['policy']->id.'/attribution', crmH($t))->assertStatus(200)->json('data');
    expect($pa['partner_id'])->toBe($a['partner']->id)->and($pa['current_partner_id'])->toBe($to->id);

    $this->getJson('/api/v1/crm/portfolio-transfers', crmH($t))->assertStatus(200)->assertJsonCount(1, 'data');
});

it('REQ-CRM-003 refuses transfer to an inactive or same partner and without permission', function () {
    $a = makeMobileAgentFixture('+237680004050');
    $t = $a['tenant'];
    $inactive = crmPartner($t, 'AGENT', 'SUSPENDED');
    $ops = crmStaff($t, '+237670004050', CRM_ALL);
    Passport::actingAs($ops);
    $this->postJson('/api/v1/crm/portfolio-transfers/preview', ['from_partner_id' => $a['partner']->id, 'to_partner_id' => $inactive->id], crmH($t))->assertStatus(422);
    $this->postJson('/api/v1/crm/portfolio-transfers/preview', ['from_partner_id' => $a['partner']->id, 'to_partner_id' => $a['partner']->id], crmH($t))->assertStatus(422);
    Passport::actingAs($a['user']);
    $this->postJson('/api/v1/crm/portfolio-transfers/preview', ['from_partner_id' => $a['partner']->id, 'to_partner_id' => $inactive->id], crmH($t))->assertStatus(403);
});

// ------------------------------------------------------------ REQ-CRM-004

it('REQ-CRM-004 enforces primary/contingent allocations of 100% and keeps versioned history', function () {
    $a = makeMobileAgentFixture('+237680004060');
    $t = $a['tenant'];
    $c = makeMobileFinanceProposalChain($t);
    $policy = makeMobileTestPolicy($c['proposal'], $t, $c['carrier']->id, $c['party']->id, ['policy_number' => 'POL-BEN-1']);
    $ops = crmStaff($t, '+237670004060', CRM_ALL);
    Passport::actingAs($ops);
    $url = "/api/v1/policies/{$policy->id}/beneficiaries";

    $this->putJson($url, ['reason' => 'x', 'beneficiaries' => [['designation' => 'PRIMARY', 'full_name' => 'A', 'allocation_pct' => 60], ['designation' => 'PRIMARY', 'full_name' => 'B', 'allocation_pct' => 30]]], crmH($t))->assertStatus(422);
    $this->putJson($url, ['reason' => 'x', 'beneficiaries' => [['designation' => 'CONTINGENT', 'full_name' => 'A', 'allocation_pct' => 100]]], crmH($t))->assertStatus(422);
    $this->putJson($url, ['reason' => 'x', 'beneficiaries' => [['designation' => 'PRIMARY', 'full_name' => 'A', 'allocation_pct' => 50], ['designation' => 'PRIMARY', 'full_name' => 'a', 'allocation_pct' => 50]]], crmH($t))->assertStatus(422);

    $v1 = $this->putJson($url, ['reason' => 'Initial', 'beneficiaries' => [
        ['designation' => 'PRIMARY', 'full_name' => 'Spouse', 'relationship' => 'spouse', 'allocation_pct' => 66.67, 'revocable' => false],
        ['designation' => 'PRIMARY', 'party_id' => $c['party']->id, 'allocation_pct' => 33.33],
        ['designation' => 'CONTINGENT', 'full_name' => 'Child', 'allocation_pct' => 100],
    ]], crmH($t))->assertStatus(200)->json('data');
    expect($v1)->toHaveCount(3)->and($v1[0]['set_version'])->toBe(1);

    // Irrevocable beneficiary cannot be dropped without consent evidence.
    $replacement = ['beneficiaries' => [['designation' => 'PRIMARY', 'full_name' => 'New Person', 'allocation_pct' => 100]]];
    $this->putJson($url, $replacement + ['reason' => 'Change'], crmH($t))->assertStatus(422);
    $this->putJson($url, $replacement + ['reason' => 'Change', 'irrevocable_consent_reference' => 'CONSENT-1'], crmH($t))->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.set_version', 2);

    $hist = $this->getJson("{$url}/history", crmH($t))->assertStatus(200)->json('data');
    expect($hist)->toHaveCount(2)->and($hist[0]['status'])->toBe('ACTIVE')->and($hist[1]['status'])->toBe('SUPERSEDED')->and($hist[1]['designations'])->toHaveCount(3);

    // Tenant isolation.
    $other = makeMobileAgentFixture('+237680004061');
    $otherOps = crmStaff($other['tenant'], '+237670004061', CRM_ALL);
    Passport::actingAs($otherOps);
    $this->getJson($url, crmH($other['tenant']))->assertStatus(404);
});
