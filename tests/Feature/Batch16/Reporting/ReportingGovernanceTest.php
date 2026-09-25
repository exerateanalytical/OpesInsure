<?php

declare(strict_types=1);

/*
 * Agent B2 — REQ-RPT-003 KPI governance catalogue, REQ-RPT-004 dashboard registry + data API (drill-down),
 * REQ-RPT-005 unified report catalogue (REG-001..008 + FR-01..20).
 */

use App\Application\Finance\Reports\FinanceReportRegistry;
use App\Application\Reporting\Dashboards\DashboardRegistry;
use App\Application\Reporting\Kpi\KpiQueryRegistry;
use App\Application\WebExperiences\PortalDashboardMetrics;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b2Policies(Tenant $t, int $n, string $prefix): array
{
    $c = makeMobileFinanceProposalChain($t);
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = makeMobileTestPolicy($c['proposal'], $t, $c['carrier']->id, $c['party']->id, ['policy_number' => $prefix.'-'.$i]);
    }

    return $out;
}

const B2_ALL = ['reporting.kpis.view', 'reporting.kpis.manage', 'reporting.kpis.approve', 'reporting.dashboards.view', 'reporting.reports.view'];

it('REQ-RPT-003 exposes a baseline governed definition for every registered query', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, ['reporting.kpis.view']));

    $data = $this->getJson('/api/v1/reporting/kpis', tenantHeader($t))->assertOk()->json('data');
    expect(array_column($data, 'code'))->toEqualCanonicalizing(array_keys(KpiQueryRegistry::definitions()));
    $row = collect($data)->firstWhere('code', 'premium.written');
    expect($row['status'])->toBe('SYSTEM_BASELINE')->and($row['version'])->toBe(0)->and($row['owner'])->toBe('FINANCE')
        ->and($row['date_basis'])->toBe('policies.issued_at')->and($row['sources'])->toBe(['policies'])->and($row['unit'])->toBe('money');

    $this->getJson('/api/v1/reporting/kpi-queries', tenantHeader($t))->assertOk()->assertJsonFragment(['query_key' => 'claims.open']);
    $this->getJson('/api/v1/reporting/kpis/nope.nope', tenantHeader($t))->assertNotFound();
});

it('REQ-RPT-003 KPI versions go through maker-checker and only use registered queries and whitelisted filters', function () {
    $t = makeAuthTestTenant();
    $maker = makeAuthTestUser($t, B2_ALL, 'MAKER');
    $checker = makeAuthTestUser($t, B2_ALL, 'CHECKER');
    b2Policies($t, 2, 'POL-B2');
    $body = ['code' => 'policies.active', 'name' => 'Active policies (XAF book)', 'definition' => 'Policies in force.', 'query_key' => 'policies.active',
        'filters' => ['currency' => 'XAF'], 'owner' => 'UNDERWRITING'];

    Passport::actingAs($maker);
    $this->postJson('/api/v1/reporting/kpis', ['query_key' => 'SELECT 1'] + $body, tenantHeader($t))->assertUnprocessable()->assertJsonValidationErrors('query_key');
    $this->postJson('/api/v1/reporting/kpis', ['filters' => ['tenant_id' => 'x']] + $body, tenantHeader($t))->assertUnprocessable();
    $this->postJson('/api/v1/reporting/kpis', ['date_basis' => 'policies.created_at'] + $body, tenantHeader($t))->assertUnprocessable();

    $v1 = $this->postJson('/api/v1/reporting/kpis', $body, tenantHeader($t))->assertCreated()->json('data');
    expect($v1['version'])->toBe(1)->and($v1['status'])->toBe('DRAFT');
    Passport::actingAs($checker);
    $this->postJson("/api/v1/reporting/kpi-definitions/{$v1['id']}/approve", [], tenantHeader($t))->assertStatus(409); // not submitted
    Passport::actingAs($maker);
    $this->postJson("/api/v1/reporting/kpi-definitions/{$v1['id']}/submit", [], tenantHeader($t))->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');
    $this->postJson("/api/v1/reporting/kpi-definitions/{$v1['id']}/approve", [], tenantHeader($t))->assertForbidden(); // maker cannot approve

    Passport::actingAs($checker);
    $this->postJson("/api/v1/reporting/kpi-definitions/{$v1['id']}/approve", [], tenantHeader($t))->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    $this->getJson('/api/v1/reporting/kpis/policies.active', tenantHeader($t))->assertOk()
        ->assertJsonPath('data.effective.version', 1)->assertJsonPath('data.effective.filters', ['currency' => 'XAF']);
    $this->getJson('/api/v1/reporting/kpis/policies.active/value', tenantHeader($t))->assertOk()->assertJsonPath('data.value', 2)->assertJsonPath('data.kpi.version', 1);

    // v2 supersedes v1 on approval; rejection path.
    Passport::actingAs($maker);
    $v2 = $this->postJson('/api/v1/reporting/kpis', ['filters' => ['currency' => 'EUR']] + $body, tenantHeader($t))->assertCreated()->json('data');
    $v3 = $this->postJson('/api/v1/reporting/kpis', $body, tenantHeader($t))->assertCreated()->json('data');
    expect($v2['version'])->toBe(2)->and($v3['version'])->toBe(3);
    $this->postJson("/api/v1/reporting/kpi-definitions/{$v2['id']}/submit", [], tenantHeader($t))->assertOk();
    $this->postJson("/api/v1/reporting/kpi-definitions/{$v3['id']}/submit", [], tenantHeader($t))->assertOk();
    Passport::actingAs($checker);
    $this->postJson("/api/v1/reporting/kpi-definitions/{$v2['id']}/approve", [], tenantHeader($t))->assertOk();
    $this->postJson("/api/v1/reporting/kpi-definitions/{$v3['id']}/reject", ['reason' => 'Duplicate of v1'], tenantHeader($t))->assertOk()->assertJsonPath('data.status', 'REJECTED');
    $this->getJson('/api/v1/reporting/kpis/policies.active/value', tenantHeader($t))->assertJsonPath('data.value', 0)->assertJsonPath('data.kpi.version', 2);
    $versions = $this->getJson('/api/v1/reporting/kpis/policies.active', tenantHeader($t))->json('data.versions');
    expect(array_column($versions, 'status'))->toBe(['REJECTED', 'ACTIVE', 'RETIRED']);

    $this->postJson("/api/v1/reporting/kpi-definitions/{$v2['id']}/retire", [], tenantHeader($t))->assertOk();
    $this->getJson('/api/v1/reporting/kpis/policies.active', tenantHeader($t))->assertJsonPath('data.effective.status', 'SYSTEM_BASELINE');

    expect(DB::table('outbox_messages')->where('aggregate_type', 'kpi_definition')->pluck('event_name')->unique()->sort()->values()->all())
        ->toBe(['reporting.kpi_definition.approved', 'reporting.kpi_definition.rejected', 'reporting.kpi_definition.retired', 'reporting.kpi_definition.submitted']);
});

it('REQ-RPT-003 KPI endpoints are permission-gated and tenant-scoped', function () {
    $t = makeAuthTestTenant();
    $other = makeAuthTestTenant('other');
    b2Policies($other, 3, 'POL-OTHER');
    b2Policies($t, 1, 'POL-MINE');

    Passport::actingAs(makeAuthTestUser($t, ['reporting.kpis.view']));
    $this->postJson('/api/v1/reporting/kpis', [], tenantHeader($t))->assertForbidden();
    $this->getJson('/api/v1/reporting/kpis/policies.active/value', tenantHeader($t))->assertJsonPath('data.value', 1);
    $drill = $this->getJson('/api/v1/reporting/kpis/policies.active/drill', tenantHeader($t))->assertOk()->json('data');
    expect($drill['total'])->toBe(1)->and($drill['resource'])->toBe('policies')->and($drill['rows'][0]['policy_number'])->toBe('POL-MINE-1');
    $this->getJson('/api/v1/reporting/kpis/policies.active/drill?filters[tenant_id]=x', tenantHeader($t))->assertUnprocessable();

    Passport::actingAs(makeAuthTestUser($t, []));
    $this->getJson('/api/v1/reporting/kpis', tenantHeader($t))->assertForbidden();
});

it('REQ-RPT-004 dashboards bind every tile to a governed KPI, serve data and drill down to the records', function () {
    foreach (DashboardRegistry::definitions() as $d) {
        foreach ($d['tiles'] as $tile) {
            expect(KpiQueryRegistry::has($tile['kpi']))->toBeTrue();
        }
    }
    $t = makeAuthTestTenant();
    b2Policies($t, 2, 'POL-DASH');
    Passport::actingAs(makeAuthTestUser($t, ['reporting.dashboards.view']));

    expect(array_column($this->getJson('/api/v1/reporting/dashboards', tenantHeader($t))->assertOk()->json('data'), 'code'))->toBe(['insurer', 'broker', 'finance', 'compliance']);
    $data = $this->getJson('/api/v1/reporting/dashboards/insurer', tenantHeader($t))->assertOk()->json('data');
    $tile = collect($data['tiles'])->firstWhere('key', 'active_policies');
    expect($tile['value'])->toBe(2)->and($tile['kpi'])->toMatchArray(['code' => 'policies.active', 'version' => 0, 'status' => 'SYSTEM_BASELINE']);

    $drill = $this->getJson('/api/v1/reporting/dashboards/insurer/tiles/active_policies/drill?per_page=1', tenantHeader($t))->assertOk()->json('data');
    expect($drill['total'])->toBe(2)->and($drill['rows'])->toHaveCount(1)->and($drill['kpi']['code'])->toBe('policies.active');
    $this->getJson('/api/v1/reporting/dashboards/insurer/tiles/nope/drill', tenantHeader($t))->assertNotFound();
    $this->getJson('/api/v1/reporting/dashboards/nope', tenantHeader($t))->assertNotFound();
    $this->getJson('/api/v1/reporting/dashboards/finance', tenantHeader($t))->assertOk();
    $this->getJson('/api/v1/reporting/dashboards/compliance', tenantHeader($t))->assertOk();
    $this->getJson('/api/v1/reporting/dashboards/broker', tenantHeader($t))->assertOk();
});

it('REQ-RPT-004 existing portal dashboards read from the registry and keep their response shape', function () {
    $t = makeAuthTestTenant();
    b2Policies($t, 1, 'POL-PORTAL');
    foreach (['insurer', 'broker'] as $portal) {
        $m = app(PortalDashboardMetrics::class)->for($portal, $t->id);
        expect(array_column($m, 'key'))->toBe(array_column(DashboardRegistry::get($portal)['tiles'], 'key'));
        foreach ($m as $row) {
            expect(array_keys($row))->toBe(['key', 'label', 'value', 'hint', 'tone', 'drilldown']);
        }
    }
    $insurer = collect(app(PortalDashboardMetrics::class)->for('insurer', $t->id))->keyBy('key');
    expect($insurer['active_policies']['value'])->toBe(1)->and($insurer['outstanding_reserve']['value'])->toBeString();
    expect(app(PortalDashboardMetrics::class)->for('customer', $t->id))->toBe([]);
});

it('REQ-RPT-005 one catalogue unifies REG-001..008 and the finance registry FR-01..20 without duplicating it', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, ['reporting.reports.view']));

    $data = $this->getJson('/api/v1/reporting/reports', tenantHeader($t))->assertOk()->json('data');
    $codes = array_column($data, 'code');
    expect(array_slice($codes, 0, 8))->toBe(['REG-001', 'REG-002', 'REG-003', 'REG-004', 'REG-005', 'REG-006', 'REG-007', 'REG-008'])
        ->and(array_slice($codes, 8))->toBe(array_keys(FinanceReportRegistry::definitions()));

    $fr20 = collect($data)->firstWhere('code', 'FR-20');
    expect($fr20['status'])->toBe(FinanceReportRegistry::NOT_AVAILABLE)->and($fr20['reason'])->not->toBeNull();
    $fr15 = collect($data)->firstWhere('code', 'FR-15');
    expect($fr15['sources'][0]['also_in'])->toBe(['REG-006']);

    $reg6 = $this->getJson('/api/v1/reporting/reports/reg-006', tenantHeader($t))->assertOk()->json('data');
    expect(array_column($reg6['sources'], 'ref'))->toBe(['FR-15', 'commissions.pending'])->and($reg6['status'])->toBe('AVAILABLE');
    $this->getJson('/api/v1/reporting/reports/REG-099', tenantHeader($t))->assertNotFound();
});
