<?php

declare(strict_types=1);

/**
 * Gap Closure Pack 08 (KYC / AML / PEP / sanctions / fraud) and 09 (regulatory reporting, AML & ICT controls).
 * REQ-KYC-GC-008 REQ-CMP-GC-009 REQ-FRD-001 REQ-CMP-003 REQ-AML-001
 */

use App\Application\Compliance\Catalogue\ComplianceCatalogueSeeder;
use App\Application\Compliance\Catalogue\ComplianceCatalogueService;
use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\NonProductionDataException;
use App\Application\Import\ImportPipeline;
use App\Domain\Tenancy\TenantContext;
use App\Models\RiskAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function gp7File(string $csv): string
{
    $p = tempnam(sys_get_temp_dir(), 'gp7').'.csv';
    file_put_contents($p, $csv);

    return $p;
}

beforeEach(function () {
    $this->tenant = makeAuthTestTenant('GP7');
    app(TenantContext::class)->set($this->tenant->id);
});

it('REQ-KYC-GC-008 seeds the pack baseline idempotently with statuses, never inventing gated values', function () {
    $p8 = ComplianceCatalogueSeeder::pack('08');
    $expected = array_sum(array_map('count', $p8['kyc_document_matrix']['baseline']));
    expect(DB::table('kyc_document_matrix')->count())->toBe($expected)
        ->and(DB::table('kyc_document_matrix')->distinct()->pluck('data_status')->all())->toBe(['UNVERIFIED'])
        ->and(DB::table('kyc_refresh_policies')->whereNull('tenant_id')->count())->toBe(5)
        ->and(DB::table('kyc_refresh_policies')->whereNotNull('refresh_months')->count())->toBe(0)
        ->and(DB::table('fraud_indicators')->count())->toBe(10)
        ->and(DB::table('compliance_controls')->where('framework', 'AML')->count())->toBe(15)
        ->and(DB::table('compliance_controls')->where('framework', 'ICT')->count())->toBe(25)
        ->and(DB::table('compliance_controls')->whereNotNull('requirement_summary')->count())->toBe(0)
        ->and(DB::table('country_risk_ratings')->count())->toBe(0)
        ->and(DB::table('regulatory_report_dictionary_lines')->count())->toBe(0)
        ->and(DB::table('screening_list_sources')->count())->toBe(0);   // no invented PEP / sanctions lists

    expect(app(ComplianceCatalogueSeeder::class)->run())->toBe(['kyc_document_matrix' => 0, 'kyc_refresh_policies' => 0, 'fraud_indicators' => 0, 'compliance_controls' => 0]);
});

it('REQ-KYC-GC-008 refresh policy: maker-checker, only VERIFIED months are used, MEDIUM maps to STANDARD', function () {
    $svc = app(ComplianceCatalogueService::class);
    $maker = makeAuthTestUser($this->tenant, ['compliance.catalogue.configure']);
    $checker = makeAuthTestUser($this->tenant, ['compliance.catalogue.approve']);
    expect($svc->monthsFor($this->tenant->id, 'MEDIUM'))->toBeNull();

    $p = $svc->configureRefresh($this->tenant->id, 'MEDIUM', ['refresh_months' => 24, 'source_policy_id' => 'POL-KYC-01', 'trigger_events' => ['PROFILE_CHANGE']], $maker);
    expect($p->risk_level)->toBe('STANDARD')->and($svc->monthsFor($this->tenant->id, 'MEDIUM'))->toBeNull();
    expect(fn () => $svc->approveRefresh($this->tenant->id, $p->id, $maker))->toThrow(ValidationException::class);
    expect(fn () => $svc->configureRefresh($this->tenant->id, 'LOW', ['refresh_months' => 12, 'source_policy_id' => 'X', 'trigger_events' => ['NOPE']], $maker))->toThrow(ValidationException::class);

    $svc->approveRefresh($this->tenant->id, $p->id, $checker);
    expect($svc->monthsFor($this->tenant->id, 'MEDIUM'))->toBe(24)->and($svc->monthsFor($this->tenant->id, 'STANDARD'))->toBe(24);
});

it('REQ-FRD-001 a fraud indicator opens a review, never a determination', function () {
    $actor = makeAuthTestUser($this->tenant, ['fraud.indicators.flag']);
    $subject = (string) Str::uuid();
    $a = app(ComplianceCatalogueService::class)->flag($this->tenant->id, 'FRD_DUPLICATE_CLAIM', 'claim', $subject, ['claim_no' => 'X'], $actor);
    $again = app(ComplianceCatalogueService::class)->flag($this->tenant->id, 'FRD_DUPLICATE_CLAIM', 'claim', $subject, [], $actor);
    expect($a->alert_type)->toBe('FRAUD_INDICATOR_REVIEW')->and($a->status)->toBe('OPEN')->and($a->decision)->toBeNull()
        ->and($a->signals['outcome'])->toBe('REVIEW_REQUIRED')->and($again->id)->toBe($a->id)
        ->and(RiskAlert::count())->toBe(1);
    expect(fn () => DB::table('fraud_indicators')->where('code', 'FRD_DUPLICATE_CLAIM')->update(['is_determination' => true]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('REQ-CMP-GC-009 ICT 010-24 controls cannot be rated until the official requirement is imported (generic import pipeline)', function () {
    $svc = app(ComplianceCatalogueService::class);
    $u = makeAuthTestUser($this->tenant, ['compliance.controls.assess', 'imports.create', 'imports.approve']);
    $checker = makeAuthTestUser($this->tenant, ['imports.approve']);
    $ctl = DB::table('compliance_controls')->where(['framework' => 'ICT', 'control_code' => 'BACKUP_RESTORE'])->first();

    expect(fn () => $svc->assess($this->tenant->id, $ctl->id, ['status' => 'COMPLIANT'], $u))->toThrow(ValidationException::class);
    expect($svc->assess($this->tenant->id, $ctl->id, ['status' => 'REMEDIATION_OPEN'], $u)->status)->toBe('REMEDIATION_OPEN');

    $csv = "framework,control_code,requirement_summary,source_reference,evidence_types\nICT,BACKUP_RESTORE,Fixture requirement text,FIXTURE-DOC-1,restore test|log\nICT,,x,y,\n";
    $pipe = app(ImportPipeline::class);
    $batch = $pipe->upload('compliance_controls', [], gp7File($csv), 'ict.csv', $u, [], $this->tenant->id);
    expect($batch->report['new'])->toBe(['ICT.BACKUP_RESTORE'])->and($batch->report['errors'])->toHaveCount(1);
    $batch = $pipe->upload('compliance_controls', [], gp7File(explode("ICT,,", $csv)[0]), 'ict.csv', $u, [], $this->tenant->id);
    $pipe->approve($pipe->submit($batch, $u, 'official import'), $checker);

    expect($svc->assess($this->tenant->id, $ctl->id, ['status' => 'COMPLIANT', 'evidence' => ['doc-1']], $u)->status)->toBe('COMPLIANT');
    $row = collect($svc->controls('ICT', $this->tenant->id))->firstWhere('control_code', 'BACKUP_RESTORE');
    expect($row['rateable'])->toBeTrue()->and($row['current_status'])->toBe('COMPLIANT')->and($row['evidence_types'])->toBe(['restore test', 'log']);
});

it('REQ-CMP-GC-009 regulatory dictionary and country risk are gated until imported', function () {
    config(['data_readiness.enforce' => true]);
    $svc = app(ComplianceCatalogueService::class);
    expect(fn () => $svc->assertReportUsable('FIXTURE_REPORT'))->toThrow(NonProductionDataException::class);

    $u = makeAuthTestUser($this->tenant, ['imports.create', 'imports.approve']);
    $checker = makeAuthTestUser($this->tenant, ['imports.approve']);
    $pipe = app(ImportPipeline::class);
    $csv = "regulator,report_code,report_name,periodicity,line_code,line_label,data_type,effective_from,source_document_id,signatory_roles\n"
        ."FIXTURE_REG,FIXTURE_REPORT,Fixture report,ANNUAL,L1,Fixture line,AMOUNT,2026-01-01,FIXTURE-DOC,CEO|CFO\n";
    $pipe->approve($pipe->submit($pipe->upload('regulatory_report_dictionary_lines', [], gp7File($csv), 'r.csv', $u, [], $this->tenant->id), $u, 'x'), $checker);
    $svc->assertReportUsable('FIXTURE_REPORT');
    expect(json_decode(DB::table('regulatory_report_dictionary_lines')->value('signatory_roles'), true))->toBe(['CEO', 'CFO']);

    $rows = collect(app(DataReadinessRegistry::class)->domain('kyc_aml'))->keyBy('item');
    expect($rows['country_risk']['status'])->toBe('CONFIG_REQUIRED')->and($rows['pep_sanctions_lists']['status'])->toBe('CONFIG_REQUIRED')
        ->and($rows['aml_control_catalogue']['status'])->toBe('PENDING_SOURCE');
    $b = $pipe->upload('country_risk_ratings', [], gp7File("country_code,risk_level,effective_from,source\nZZ,HIGH,2026-01-01,FIXTURE-SRC\nZZZ,HIGH,2026-01-01,x\n"), 'c.csv', $u, [], $this->tenant->id);
    expect($b->report['errors'])->toHaveCount(1);
    $b = $pipe->upload('country_risk_ratings', [], gp7File("country_code,risk_level,effective_from,source
ZZ,HIGH,2026-01-01,FIXTURE-SRC
"), 'c.csv', $u, [], $this->tenant->id);
    $pipe->approve($pipe->submit($b, $u, 'x'), $checker);
    $rows = collect(app(DataReadinessRegistry::class)->domain('kyc_aml'))->keyBy('item');
    expect($rows['country_risk']['status'])->toBe('VERIFIED');
    expect(collect(app(DataReadinessRegistry::class)->domain('regulatory_reporting'))->firstWhere('item', 'report_dictionary')['status'])->toBe('VERIFIED')
        ->and(collect(app(DataReadinessRegistry::class)->domain('ict_controls'))->firstWhere('item', 'cima_010_24_control_taxonomy')['status'])->toBe('PENDING_SOURCE');
});

it('REQ-CMP-GC-009 API: permissions, catalogue reads, refresh maker-checker, flag and assess', function () {
    $h = ['X-Tenant-Id' => $this->tenant->id];
    $none = makeAuthTestUser($this->tenant, []);
    Passport::actingAs($none);
    $this->getJson('/api/v1/compliance-catalogue/fraud/indicators', $h)->assertForbidden();

    $maker = makeAuthTestUser($this->tenant, ['compliance.catalogue.view', 'compliance.catalogue.configure', 'fraud.indicators.flag', 'compliance.controls.assess']);
    $checker = makeAuthTestUser($this->tenant, ['compliance.catalogue.view', 'compliance.catalogue.approve']);
    Passport::actingAs($maker);
    $this->getJson('/api/v1/compliance-catalogue/fraud/indicators', $h)->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('data.0.is_determination', false);
    $this->getJson('/api/v1/compliance-catalogue/kyc/document-matrix?customer_type=company', $h)->assertOk()->assertJsonCount(6, 'data');
    $this->getJson('/api/v1/compliance-catalogue/controls?framework=ICT', $h)->assertOk()->assertJsonCount(25, 'data');
    $this->getJson('/api/v1/compliance-catalogue/readiness', $h)->assertOk();
    $id = $this->putJson('/api/v1/compliance-catalogue/kyc/refresh-policies/HIGH', ['refresh_months' => 12, 'source_policy_id' => 'POL-1'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/compliance-catalogue/fraud/indicators/FRD_IDENTITY_MISMATCH/flag', ['subject_type' => 'party', 'subject_id' => (string) Str::uuid()], $h)
        ->assertCreated()->assertJsonPath('data.status', 'OPEN');
    $ctl = DB::table('compliance_controls')->where('control_code', 'PEP_SCREENING')->value('id');
    $this->postJson("/api/v1/compliance-catalogue/controls/{$ctl}/assessments", ['status' => 'NON_COMPLIANT'], $h)->assertStatus(422);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/compliance-catalogue/kyc/refresh-policies/{$id}/approve", [], $h)->assertOk()->assertJsonPath('data.data_status', 'VERIFIED');
    $this->getJson('/api/v1/compliance-catalogue/kyc/refresh-policies', $h)->assertOk()->assertJsonPath('data.3.policy.refresh_months', 12);
});
