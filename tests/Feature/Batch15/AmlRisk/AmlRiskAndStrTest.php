<?php

declare(strict_types=1);

/**
 * Agent E9 — REQ-AML-002 customer AML risk rating (explainable, EDD case, rescreen by band, transaction monitoring
 * rules → risk_alerts, thresholds unseeded pending OQ-5.2) and REQ-AML-003 STR cases with tipping-off controls.
 */

use App\Application\Cases\Models\WorkCase;
use App\Application\Compliance\Aml\Risk\TransactionMonitoringService;
use App\Application\Compliance\Aml\Str\TippingOffGuard;
use App\Application\Kyc\Models\ScreeningCheck;
use App\Models\FraudRuleVersion;
use App\Models\RiskAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_auth_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

beforeEach(function () {
    $this->fx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->fx['tenant'];
    $this->kyc = makeAuthTestUser($this->tenant, ['kyc.view', 'kyc.manage', 'kyc.review', 'kyc.screen'], 'KYC_MAKER');
    $this->analyst = makeAuthTestUser($this->tenant, ['aml.risk.rate', 'aml.risk.view', 'aml.monitoring.evaluate', 'fraud.rules.manage', 'cases.view', 'cases.restricted.view', 'audit.read'], 'AML_ANALYST');
    $this->mlro = makeAuthTestUser($this->tenant, ['cases.view', 'cases.restricted.view', 'cases.str.view', 'audit.read'], 'COMPLIANCE_OFFICER');
    $this->mlro2 = makeAuthTestUser($this->tenant, ['cases.view', 'cases.str.view'], 'COMPLIANCE_OFFICER');
});

function e9As($user, string $method, string $uri, array $body = [])
{
    Passport::actingAs($user);

    return test()->json($method, '/api/v1/'.$uri, $body, tenantHeaderFor(test()->tenant));
}

function e9Submit(): string
{
    $fx = test()->fx;
    foreach (['ID_FRONT', 'PROOF_OF_ADDRESS'] as $purpose) {
        e9As($fx['user'], 'POST', 'mobile/kyc/documents', ['document_id' => makeMobileTestDocument($fx['tenant'], $fx['party'])->id, 'purpose' => $purpose])->assertStatus(201);
    }
    Passport::actingAs($fx['user']);

    return test()->postJson('/api/v1/mobile/kyc/submission', [], tenantHeaderFor($fx['tenant']) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201)->json('data.id');
}

function e9ConfigureRisk(): void
{
    config(['kyc.risk.factors' => [
        'customer' => ['weight' => 1, 'scores' => ['INDIVIDUAL' => 1]],
        'country' => ['weight' => 1, 'scores' => ['CM' => 1, 'XX' => 3]],
        'product' => ['weight' => 1, 'scores' => ['MOTOR' => 1, 'LIFE_SAVINGS' => 3]],
        'channel' => ['weight' => 1, 'scores' => ['BROKER' => 2]],
    ], 'kyc.risk.bands' => ['LOW' => 1.5, 'MEDIUM' => 2.2], 'kyc.risk.rescreen_months' => ['LOW' => 36, 'MEDIUM' => 24, 'HIGH' => 12, 'UNRATED' => null]]);
}

it('REQ-AML-002: without configuration the AML rating is UNRATED, explained, and opens no EDD case', function () {
    e9Submit();
    $party = $this->fx['party']->id;
    $r = e9As($this->analyst, 'POST', "aml/customers/{$party}/risk-rating", ['reason' => 'Onboarding AML rating'])->assertStatus(201);
    expect($r->json('data.band'))->toBe('UNRATED')->and($r->json('data.edd_required'))->toBeFalse()->and($r->json('data.edd_case_id'))->toBeNull()
        ->and($r->json('data.screening_source'))->toBe('KYC_SCREENING_CHECKS')
        ->and($r->json('data.explanation.summary'))->toContain('UNRATED')
        ->and($r->json('data.explanation.configuration_gaps'))->toContain('factors.country.weight')
        ->and($r->json('data.explanation.rescreen.next_rescreen_at'))->toBeNull();
    e9As($this->analyst, 'GET', "aml/customers/{$party}/risk-rating")->assertOk()->assertJsonPath('data.band', 'UNRATED');
    expect(DB::table('audit_log')->where('action', 'aml.risk.rated')->where('subject_id', $party)->exists())->toBeTrue();
})->group('REQ-AML-002');

it('REQ-AML-002: geography, product and channel are scored and explained; HIGH opens one EDD case and schedules rescreen by band', function () {
    e9ConfigureRisk();
    e9Submit();
    $party = $this->fx['party']->id;
    $low = e9As($this->analyst, 'POST', "aml/customers/{$party}/risk-rating", ['country_code' => 'CM', 'product_codes' => ['MOTOR'], 'channel' => 'broker', 'reason' => 'Facts'])->assertStatus(201);
    expect($low->json('data.band'))->toBe('LOW')->and($low->json('data.edd_case_id'))->toBeNull()
        ->and(collect($low->json('data.explanation.factors'))->firstWhere('factor', 'country')['explanation'])->toContain('Geography CM')
        ->and(substr((string) $low->json('data.explanation.rescreen.next_rescreen_at'), 0, 7))->toBe(now()->addMonthsNoOverflow(36)->format('Y-m'));

    $high = e9As($this->analyst, 'POST', "aml/customers/{$party}/risk-rating", ['country_code' => 'XX', 'product_codes' => ['LIFE_SAVINGS'], 'reason' => 'Life savings'])->assertStatus(201);
    expect($high->json('data.band'))->toBe('HIGH')->and($high->json('data.edd_required'))->toBeTrue()
        ->and($high->json('data.explanation.sources_of_funds_wealth.source_of_funds'))->toBe('MISSING')
        ->and(substr((string) $high->json('data.explanation.rescreen.next_rescreen_at'), 0, 7))->toBe(now()->addMonthsNoOverflow(12)->format('Y-m'));
    $case = WorkCase::withoutGlobalScopes()->findOrFail($high->json('data.edd_case_id'));
    expect($case->case_type_code)->toBe('AML_EDD')->and($case->confidentiality)->toBe('RESTRICTED')->and($case->subject_id)->toBe($party);

    // Re-rating while the EDD case is open reuses it.
    $again = e9As($this->analyst, 'POST', "aml/customers/{$party}/risk-rating", ['reason' => 'Re-rate'])->assertStatus(201);
    expect($again->json('data.edd_case_id'))->toBe($case->id)->and(WorkCase::withoutGlobalScopes()->where('case_type_code', 'AML_EDD')->count())->toBe(1);
})->group('REQ-AML-002');

it('REQ-AML-002: a PEP screening match is a hard trigger with a PEP explanation', function () {
    $id = e9Submit();
    $pep = ScreeningCheck::where('subject_id', $id)->where('check_type', 'PEP')->firstOrFail();
    e9As($this->kyc, 'POST', "kyc/submissions/{$id}/screenings/{$pep->id}", ['status' => 'CONFIRMED_MATCH', 'list_reference' => 'Reviewer consulted list'])->assertOk();
    $r = e9As($this->analyst, 'POST', "aml/customers/{$this->fx['party']->id}/risk-rating", ['reason' => 'After screening'])->assertStatus(201);
    expect($r->json('data.band'))->toBe('HIGH')->and($r->json('data.explanation.hard_triggers'))->toBe(['PEP_CONFIRMED_MATCH'])
        ->and($r->json('data.explanation.screening.facts'))->toContain('PEP_CONFIRMED_MATCH')->and($r->json('data.edd_case_id'))->not->toBeNull();
})->group('REQ-AML-002');

it('REQ-AML-002: transaction monitoring is inactive until a rule is configured (OQ-5.2), then raises idempotent explainable risk alerts', function () {
    $txn = ['subject_type' => 'PAYMENT', 'subject_id' => (string) Str::uuid(), 'party_id' => $this->fx['party']->id, 'facts' => ['amount_minor' => 9_000_000, 'channel' => 'CASH']];
    expect(FraudRuleVersion::where('scope', TransactionMonitoringService::SCOPE)->count())->toBe(0);   // nothing seeded
    e9As($this->analyst, 'GET', 'aml/transaction-monitoring/rules')->assertOk()->assertJsonPath('data.status', 'INACTIVE_NOT_CONFIGURED');
    e9As($this->analyst, 'POST', 'aml/transaction-monitoring/evaluate', $txn)->assertOk()->assertJsonPath('data.status', 'INACTIVE_NOT_CONFIGURED')->assertJsonPath('data.alerts', []);

    // A compliance officer configures the threshold through the existing fraud-rules endpoint (scope AML_TRANSACTION).
    $conditions = ['op' => 'all', 'conditions' => [['op' => 'gte', 'fact' => 'amount_minor', 'value' => 5_000_000], ['op' => 'eq', 'fact' => 'channel', 'value' => 'CASH']]];
    $rid = e9As($this->analyst, 'POST', 'fraud-rules', ['code' => 'AML-CASH-TEST', 'scope' => 'AML_TRANSACTION', 'risk_points' => 70, 'conditions' => $conditions,
        'effective_from' => now()->subDay()->toDateString()])->assertStatus(201)->json('data.id');
    e9As($this->analyst, 'POST', 'aml/transaction-monitoring/evaluate', $txn)->assertOk()->assertJsonPath('data.status', 'INACTIVE_NOT_CONFIGURED');   // DRAFT only
    FraudRuleVersion::whereKey($rid)->update(['status' => 'ACTIVE', 'approved_by' => $this->mlro->id, 'approved_at' => now()]);

    $r = e9As($this->analyst, 'POST', 'aml/transaction-monitoring/evaluate', $txn)->assertOk();
    expect($r->json('data.status'))->toBe('EVALUATED')->and($r->json('data.alerts'))->toHaveCount(1)
        ->and($r->json('data.alerts.0.reasons'))->toContain('amount_minor = 9000000 >= 5000000');
    $alert = RiskAlert::findOrFail($r->json('data.alerts.0.id'));
    expect($alert->alert_type)->toBe('AML_TRANSACTION_MONITORING')->and($alert->severity)->toBe('HIGH')->and($alert->status)->toBe('OPEN')
        ->and($alert->fraud_rule_version_id)->toBe($rid)->and($alert->signals['rule']['code'])->toBe('AML-CASH-TEST');
    e9As($this->analyst, 'POST', 'aml/transaction-monitoring/evaluate', $txn)->assertOk();
    expect(RiskAlert::where('alert_type', 'AML_TRANSACTION_MONITORING')->count())->toBe(1);
    e9As($this->analyst, 'POST', 'aml/transaction-monitoring/evaluate', ['facts' => ['amount_minor' => 10, 'channel' => 'CASH']] + $txn)->assertOk()->assertJsonPath('data.status', 'EVALUATED');
    expect(DB::table('outbox_messages')->where('event_name', 'like', 'aml.%')->exists())->toBeFalse();
})->group('REQ-AML-002');

it('REQ-AML-003: STR cases are drafted and submitted only by compliance officers (four eyes); others get 404', function () {
    $body = ['party_id' => $this->fx['party']->id, 'grounds' => 'Structured cash premium payments inconsistent with profile.'];
    e9As($this->analyst, 'POST', 'aml/str-reports', $body)->assertNotFound();
    $str = e9As($this->mlro, 'POST', 'aml/str-reports', $body)->assertStatus(201)->json('data');
    $case = WorkCase::withoutGlobalScopes()->findOrFail($str['case_id']);
    expect($case->case_type_code)->toBe('STR')->and($case->confidentiality)->toBe('STR_RESTRICTED')->and($str['status'])->toBe('DRAFT');

    e9As($this->analyst, 'GET', 'aml/str-reports')->assertNotFound();
    e9As($this->analyst, 'GET', "aml/str-reports/{$str['id']}")->assertNotFound();
    e9As($this->analyst, 'GET', "cases/{$case->id}")->assertNotFound();
    e9As($this->mlro, 'GET', "cases/{$case->id}")->assertOk();
    e9As($this->mlro, 'POST', "aml/str-reports/{$str['id']}/submit", ['regulator_reference' => 'ANIF-TEST-1'])->assertStatus(422);
    e9As($this->mlro2, 'POST', "aml/str-reports/{$str['id']}/submit", ['regulator_reference' => 'ANIF-TEST-1'])->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
    e9As($this->mlro, 'GET', 'aml/str-reports')->assertOk()->assertJsonCount(1, 'data');
})->group('REQ-AML-003');

it('REQ-AML-003 Reg. 003-25: no STR data leaks through customer / partner endpoints, integrations or audit logs of non-compliance users', function () {
    e9Submit();
    $str = e9As($this->mlro, 'POST', 'aml/str-reports', ['party_id' => $this->fx['party']->id, 'grounds' => 'Suspicious third-party premium funding pattern.'])->assertStatus(201)->json('data');
    e9As($this->mlro2, 'POST', "aml/str-reports/{$str['id']}/submit", ['regulator_reference' => 'ANIF-TEST-2'])->assertOk();
    $case = WorkCase::withoutGlobalScopes()->findOrFail($str['case_id']);
    $needles = [$str['id'], $case->id, $case->case_number, 'aml.str', 'STR_RESTRICTED', 'ANIF-TEST-2', 'Suspicious third-party'];
    $clean = function (string $body) use ($needles): void {
        foreach ($needles as $n) {
            expect(str_contains($body, $n))->toBeFalse("leaked: {$n}");
        }
    };

    // Customer-facing (the STR subject) and broker / partner staff without cases.str.view.
    $broker = makeAuthTestUser($this->tenant, ['cases.view', 'cases.restricted.view', 'kyc.view', 'audit.read'], 'BROKER_ADMIN');
    foreach (['mobile/kyc/profile', 'mobile/notifications', 'mobile/support/cases', 'me/work', 'aml/str-reports', "aml/str-reports/{$str['id']}"] as $uri) {
        $clean((string) e9As($this->fx['user'], 'GET', $uri)->getContent());
    }
    foreach (['cases', "cases/{$case->id}", "cases/{$case->id}/events", 'me/work', 'compliance/audit-log?per_page=100', 'compliance/audit-log?subject_type=aml_str',
        'compliance/audit-log?subject_type=case', 'aml/str-reports', "aml/str-reports/{$str['id']}"] as $uri) {
        $clean((string) e9As($broker, 'GET', $uri)->getContent());
    }
    expect(DB::table('notification_deliveries')->where('party_id', $this->fx['party']->id)->exists())->toBeFalse()
        ->and(DB::table('user_notifications')->where('user_id', $this->fx['user']->id)->exists())->toBeFalse();

    // Compliance officers do see the trail.
    $audit = (string) e9As($this->mlro, 'GET', 'compliance/audit-log?per_page=100')->assertOk()->getContent();
    expect($audit)->toContain('aml.str.drafted')->toContain($case->id);

    // Integrations: every outbox row about the STR case is withheld from partner webhooks.
    $rows = DB::table('outbox_messages')->where('aggregate_id', $case->id)->get();
    expect($rows)->not->toBeEmpty()->and($rows->every(fn ($m) => TippingOffGuard::withholdFromIntegrations($m)))->toBeTrue()
        ->and(DB::table('outbox_messages')->where('payload', 'like', '%'.$str['id'].'%')->where('aggregate_id', '<>', $case->id)->exists())->toBeFalse();
    $normal = e9As($this->mlro, 'POST', 'aml/str-reports', ['grounds' => 'Second report for guard contrast check only.'])->json('data.case_id');
    DB::table('cases')->where('id', $normal)->update(['confidentiality' => 'RESTRICTED']);
    expect(TippingOffGuard::withholdFromIntegrations((object) ['event_name' => 'queue.routed', 'aggregate_type' => 'case', 'aggregate_id' => $normal, 'payload' => '{}']))->toBeFalse();
})->group('REQ-AML-003');
