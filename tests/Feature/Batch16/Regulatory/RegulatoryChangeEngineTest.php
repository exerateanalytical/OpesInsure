<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

const B1_RULES = ['regulatory.rules.view', 'regulatory.rules.draft', 'regulatory.rules.review', 'regulatory.rules.approve'];

function b1Rule($test, array $h, array $extra = []): array
{
    return $test->postJson('/api/v1/regulatory/rules', ['code' => 'AUTO_MIN_LIMIT', 'title' => 'Motor minimum limit', 'rule_type' => 'LIMIT',
        'scope' => ['line_codes' => ['AUTO']], 'content' => ['configured' => true], 'effective_from' => '2025-01-01', ...$extra], $h)->assertCreated()->json('data');
}

it('runs DRAFT → REVIEWED → APPROVED → EFFECTIVE with separation of duties, impact analysis and supersession', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $h = tenantHeader($f['tenant']);
    [$author, $reviewer, $approver] = [makeAuthTestUser($f['tenant'], B1_RULES), makeAuthTestUser($f['tenant'], B1_RULES), makeAuthTestUser($f['tenant'], B1_RULES)];
    $line = DB::table('insurance_products')->where('id', $f['product']->id)->value('line_code');

    Passport::actingAs($author);
    $v1 = b1Rule($this, $h, ['scope' => ['line_codes' => [$line]]]);
    expect($v1['status'])->toBe('DRAFT')->and($v1['version'])->toBe(1);
    $this->postJson("/api/v1/regulatory/rules/{$v1['id']}/review", [], $h)->assertStatus(422); // author cannot review

    Passport::actingAs($reviewer);
    $reviewed = $this->postJson("/api/v1/regulatory/rules/{$v1['id']}/review", ['notes' => 'ok'], $h)->assertOk()->json('data');
    expect($reviewed['status'])->toBe('REVIEWED')->and($reviewed['impact']['summary']['products'])->toBeGreaterThanOrEqual(1)
        ->and(collect($reviewed['impact']['products'])->pluck('id'))->toContain($f['product']->id);
    $this->postJson("/api/v1/regulatory/rules/{$v1['id']}/approve", ['impact_hash' => $reviewed['impact_hash']], $h)->assertStatus(422); // reviewer cannot approve

    Passport::actingAs($approver);
    $this->postJson("/api/v1/regulatory/rules/{$v1['id']}/approve", ['impact_hash' => str_repeat('0', 64)], $h)->assertStatus(422)->assertJsonValidationErrors('impact_hash');
    $impact = $this->getJson("/api/v1/regulatory/rules/{$v1['id']}/impact", $h)->assertOk()->json('impact_hash');
    $this->postJson("/api/v1/regulatory/rules/{$v1['id']}/approve", ['impact_hash' => $impact], $h)->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $this->postJson("/api/v1/regulatory/rules/{$v1['id']}/activate", [], $h)->assertOk()->assertJsonPath('data.status', 'EFFECTIVE');

    // v2 supersedes v1; impact of v2 lists v1 as a related rule
    Passport::actingAs($author);
    $v2 = b1Rule($this, $h, ['effective_from' => '2025-06-01']);
    expect($v2['version'])->toBe(2);
    Passport::actingAs($reviewer);
    $r2 = $this->postJson("/api/v1/regulatory/rules/{$v2['id']}/review", [], $h)->assertOk()->json('data');
    expect(collect($r2['impact']['rules'])->pluck('id'))->toContain($v1['id']);
    Passport::actingAs($approver);
    $this->postJson("/api/v1/regulatory/rules/{$v2['id']}/approve", ['impact_hash' => $r2['impact_hash']], $h)->assertOk();
    $this->postJson("/api/v1/regulatory/rules/{$v2['id']}/activate", [], $h)->assertOk()->assertJsonPath('data.supersedes_id', $v1['id']);
    $old = DB::table('regulatory_rules')->find($v1['id']);
    expect($old->status)->toBe('SUPERSEDED')->and($old->effective_until)->toBe('2025-05-31');
    foreach (['regulatory.rule.reviewed', 'regulatory.rule.approved', 'regulatory.rule.effective', 'regulatory.rule.superseded'] as $e) {
        expect(DB::table('outbox_messages')->where('event_name', $e)->exists())->toBeTrue($e);
    }
});

it('refuses activation before effective_from and unknown reference sets, and reports reference-set impact', function () {
    $tenant = makeAuthTestTenant();
    $h = tenantHeader($tenant);
    [$author, $reviewer, $approver] = [makeAuthTestUser($tenant, B1_RULES), makeAuthTestUser($tenant, B1_RULES), makeAuthTestUser($tenant, B1_RULES)];
    Passport::actingAs($author);
    $this->postJson('/api/v1/regulatory/rules', ['code' => 'R', 'title' => 'T', 'rule_type' => 'X', 'reference_set_code' => 'NOPE', 'effective_from' => '2025-01-01'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('reference_set_code');
    $set = (string) Str::uuid();
    DB::table('regulatory_reference_sets')->insert(['id' => $set, 'jurisdiction' => 'CM', 'code' => 'TAX_RATES', 'version' => 1, 'status' => 'ACTIVE',
        'effective_from' => '2025-01-01', 'entries' => '[]', 'content_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
    $rule = b1Rule($this, $h, ['code' => 'TAX', 'reference_set_code' => 'TAX_RATES', 'scope' => [], 'effective_from' => now()->addMonth()->toDateString()]);
    expect($this->getJson("/api/v1/regulatory/reference-sets/{$set}/impact", $h)->assertOk()->json('data.rules.0.id'))->toBe($rule['id']);

    Passport::actingAs($reviewer);
    $hash = $this->postJson("/api/v1/regulatory/rules/{$rule['id']}/review", [], $h)->assertOk()->json('data.impact_hash');
    Passport::actingAs($approver);
    $this->postJson("/api/v1/regulatory/rules/{$rule['id']}/approve", ['impact_hash' => $hash], $h)->assertOk();
    $this->postJson("/api/v1/regulatory/rules/{$rule['id']}/activate", [], $h)->assertStatus(422)->assertJsonValidationErrors('effective_from');

    Passport::actingAs(makeAuthTestUser($tenant, ['regulatory.rules.view']));
    $this->postJson('/api/v1/regulatory/rules', ['code' => 'R', 'title' => 'T', 'rule_type' => 'X', 'effective_from' => '2025-01-01'], $h)->assertForbidden();
});
