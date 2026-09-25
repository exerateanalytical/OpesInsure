<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

const B1_RPT = ['regulatory.returns.view', 'regulatory.returns.define', 'regulatory.returns.approve', 'trust.regulatory-reports.prepare',
    'trust.regulatory-reports.approve', 'trust.regulatory-reports.submit', 'trust.regulatory-reports.acknowledge'];

function b1Policy(array $f, string $line, int $premium, string $issued): string
{
    $id = (string) Str::uuid();
    DB::table('policies')->insert(['id' => $id, 'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id,
        'party_id' => $f['party']->id, 'status' => 'ACTIVE', 'coverage_starts_at' => $issued, 'coverage_ends_at' => '2026-01-01 00:00:00+00', 'issued_at' => $issued,
        'terms_snapshot' => json_encode(['line_code' => $line]), 'version' => 1, 'currency' => 'XAF', 'premium_minor' => $premium, 'is_demo' => false,
        'created_at' => $issued, 'updated_at' => $issued]);

    return $id;
}

function b1Definition($test, array $h, array $extra = []): array
{
    return $test->postJson('/api/v1/regulatory/return-definitions', [
        'code' => 'PREMIUM_BY_LINE', 'version' => 1, 'jurisdiction' => 'CM', 'report_type' => 'PREMIUM_SUMMARY', 'regime' => 'CIMA',
        'schema' => ['dataset' => 'policies', 'group_by' => ['line_code'], 'columns' => [
            ['key' => 'line', 'field' => 'line_code'], ['key' => 'written', 'field' => 'premium_minor', 'aggregate' => 'sum'], ['key' => 'n', 'aggregate' => 'count'],
        ]],
        'effective_from' => '2025-01-01', ...$extra,
    ], $h)->assertCreated()->json('data');
}

it('generates a return from an approved definition with row-level lineage and runs approve → submit → acknowledge', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $maker = makeAuthTestUser($f['tenant'], B1_RPT);
    $checker = makeAuthTestUser($f['tenant'], B1_RPT);
    $h = tenantHeader($f['tenant']);
    $a1 = b1Policy($f, 'AUTO', 1000, '2025-02-01 00:00:00+00');
    $a2 = b1Policy($f, 'AUTO', 2500, '2025-03-01 00:00:00+00');
    $m1 = b1Policy($f, 'MRH', 700, '2025-03-05 00:00:00+00');
    b1Policy($f, 'AUTO', 9999, '2025-07-01 00:00:00+00'); // outside period

    Passport::actingAs($maker);
    $def = b1Definition($this, $h);
    $this->postJson("/api/v1/regulatory/return-definitions/{$def['id']}/approve", [], $h)->assertStatus(422); // maker ≠ checker
    Passport::actingAs($checker);
    $this->postJson("/api/v1/regulatory/return-definitions/{$def['id']}/approve", [], $h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');

    Passport::actingAs($maker);
    $body = ['period_key' => '2025-Q1', 'period_from' => '2025-01-01', 'period_to' => '2025-03-31', 'idempotency_key' => 'rq1'];
    $run = $this->postJson("/api/v1/regulatory/return-definitions/{$def['id']}/runs", $body, $h)->assertCreated()->json('data');
    expect($run['status'])->toBe('DRAFT')->and($run['row_count'])->toBe(2)
        ->and($run['payload']['rows'])->toBe([['line' => 'AUTO', 'written' => 3500, 'n' => 2], ['line' => 'MRH', 'written' => 700, 'n' => 1]])
        ->and($run['source_query'])->toContain('from "policies"')->and($run['source_version'])->toStartWith('PREMIUM_BY_LINE@v1#');
    // idempotent replay
    expect($this->postJson("/api/v1/regulatory/return-definitions/{$def['id']}/runs", $body, $h)->assertCreated()->json('data.id'))->toBe($run['id']);

    $lineage = $this->getJson("/api/v1/regulatory/return-runs/{$run['id']}/lineage?row=0", $h)->assertOk()->json('data');
    expect(collect($lineage)->pluck('source_id')->sort()->values()->all())->toBe(collect([$a1, $a2])->sort()->values()->all());
    expect($this->getJson("/api/v1/regulatory/return-runs/{$run['id']}/lineage?row=1", $h)->json('data.0.source_id'))->toBe($m1);

    // acknowledge requires the run to have been submitted
    $this->postJson("/api/v1/trust/regulatory-report-runs/{$run['id']}/acknowledge", ['external_reference' => 'X'], $h)->assertStatus(422);
    $this->postJson("/api/v1/trust/regulatory-report-runs/{$run['id']}/approve", [], $h)->assertStatus(422);
    Passport::actingAs($checker);
    $this->postJson("/api/v1/trust/regulatory-report-runs/{$run['id']}/approve", [], $h)->assertOk();
    $this->postJson("/api/v1/trust/regulatory-report-runs/{$run['id']}/submit", [], $h)->assertOk()->assertJsonPath('status', 'SUBMITTING');
    $this->postJson("/api/v1/trust/regulatory-report-runs/{$run['id']}/acknowledge", ['external_reference' => 'ACK-1'], $h)->assertOk()->assertJsonPath('status', 'ACKNOWLEDGED');
    foreach (['regulatory.report.approved', 'regulatory.report.submitted', 'regulatory.report.acknowledged'] as $e) {
        expect(DB::table('outbox_messages')->where(['event_name' => $e, 'aggregate_id' => $run['id']])->exists())->toBeTrue($e);
    }
});

it('rejects unknown datasets/fields and unknown Art. 411/557 category codes (no built-in CIMA formats)', function () {
    $tenant = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($tenant, B1_RPT));
    $h = tenantHeader($tenant);
    $base = ['code' => 'X', 'version' => 1, 'jurisdiction' => 'CM', 'report_type' => 'T', 'effective_from' => '2025-01-01'];
    $this->postJson('/api/v1/regulatory/return-definitions', $base + ['schema' => ['dataset' => 'users', 'columns' => [['key' => 'a', 'field' => 'id']]]], $h)->assertStatus(422);
    $this->postJson('/api/v1/regulatory/return-definitions', $base + ['schema' => ['dataset' => 'policies', 'columns' => [['key' => 'a', 'field' => 'password']]]], $h)->assertStatus(422);
    $this->postJson('/api/v1/regulatory/return-definitions', $base + ['regulatory_category_kind' => 'ART_411_CATEGORY', 'regulatory_category_code' => 'NOPE',
        'schema' => ['dataset' => 'policies', 'columns' => [['key' => 'a', 'field' => 'id']]]], $h)->assertStatus(422)->assertJsonValidationErrors('regulatory_category_code');
    expect(DB::table('regulatory_report_definitions')->count())->toBe(0);
});

it('keeps runs tenant-isolated and requires permissions', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $maker = makeAuthTestUser($f['tenant'], B1_RPT);
    $checker = makeAuthTestUser($f['tenant'], B1_RPT);
    $h = tenantHeader($f['tenant']);
    Passport::actingAs($maker);
    $def = b1Definition($this, $h);
    Passport::actingAs($checker);
    $this->postJson("/api/v1/regulatory/return-definitions/{$def['id']}/approve", [], $h)->assertOk();
    $run = $this->postJson("/api/v1/regulatory/return-definitions/{$def['id']}/runs", ['period_key' => 'P', 'period_from' => '2025-01-01', 'period_to' => '2025-01-31', 'idempotency_key' => 'k'], $h)->assertCreated()->json('data');

    $other = makeAuthTestTenant('o');
    Passport::actingAs(makeAuthTestUser($other, B1_RPT));
    $this->getJson("/api/v1/regulatory/return-runs/{$run['id']}", tenantHeader($other))->assertNotFound();
    Passport::actingAs(makeAuthTestUser($f['tenant'], []));
    $this->getJson("/api/v1/regulatory/return-runs/{$run['id']}/lineage", $h)->assertForbidden();
});
