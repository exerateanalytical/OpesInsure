<?php

declare(strict_types=1);

/*
 * Agent C3 — REQ-CLM-003 coverage-at-loss engine.
 */

use App\Application\Claims\Coverage\ClaimCoverageCheckService;
use App\Application\Claims\Coverage\ClaimCoverageTransitionGuard;
use App\Application\Claims\Coverage\CoverageAtLossEngine;
use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\Claim;
use App\Models\PolicyIssuanceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function c3Policy(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => [
        'offer_id' => null, 'quote_id' => $f['quote']->id, 'product_id' => $f['product']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
        'coverage_snapshot' => ['coverages' => [
            ['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 50_000_000, 'deductible_minor' => null],
            ['code' => 'DOM', 'name' => ['en' => 'Own damage'], 'mandatory' => false, 'optional' => true, 'limit_minor' => 8_000_000, 'deductible_minor' => 100_000],
        ]],
    ]]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => $f['payment']->amount_minor,
        'currency' => $f['payment']->currency, 'status' => 'SUCCEEDED',
    ], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
    $approver = User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $f['policy'] = app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-C3'], $approver);
    $f['loss'] = $f['policy']->coverage_starts_at->copy()->addDays(2);

    return $f;
}

function c3Exclusion(array $f, string $code, array $condition, bool $legalText = true): void
{
    $line = DB::table('insurance_lines')->where('code', 'AUTO')->value('id') ?? tap((string) Str::uuid(), fn ($id) => DB::table('insurance_lines')->insert([
        'id' => $id, 'code' => 'AUTO', 'name' => json_encode(['en' => 'Auto']), 'risk_schema' => '{}', 'created_at' => now(), 'updated_at' => now()]));
    $def = (string) Str::uuid();
    DB::table('exclusion_definitions')->insert(['id' => $def, 'insurance_line_id' => $line, 'code' => $code, 'name' => json_encode(['en' => $code]), 'kind' => 'EXCLUSION', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('product_exclusions')->insert(['insurance_product_id' => $f['product']->id, 'exclusion_definition_id' => $def, 'level' => 'PRODUCT', 'condition' => json_encode((object) $condition)]);
    if ($legalText) {
        DB::table('exclusion_legal_texts')->insert(['id' => (string) Str::uuid(), 'exclusion_definition_id' => $def, 'version' => 1, 'text' => json_encode(['en' => 'Racing is excluded.']),
            'legal_reference' => 'Art. 12', 'effective_from' => now()->subYear()->toDateString(), 'status' => 'APPROVED', 'text_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
    }
}

function c3Claim(array $f): Claim
{
    return Claim::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $f['policy']->id, 'claimant_party_id' => $f['party']->id, 'claim_number' => 'CLM-'.Str::random(8),
        'status' => 'CARRIER_REVIEW', 'loss_occurred_at' => $f['loss'], 'loss_details' => ['description' => 'x', 'coverage_code' => 'RC'], 'currency' => 'XAF', 'submitted_at' => now()->addDays(3)]);
}

function c3Codes(array $r): array
{
    return array_column($r['reasons'], 'code');
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-CLM-003 confirms coverage over the ad hoc API and explains why', function () {
    $f = c3Policy();
    Passport::actingAs(makeAuthTestUser($f['tenant'], ['claims.coverage.check']));

    $d = $this->postJson('/api/v1/claims/coverage/check', ['policy_id' => $f['policy']->id, 'loss_occurred_at' => $f['loss']->toIso8601String(), 'reported_at' => now()->addDays(3)->toIso8601String(), 'coverage_code' => 'RC'], tenantHeader($f['tenant']))
        ->assertOk()->json('data');

    expect($d['outcome'])->toBe('COVERAGE_CONFIRMED')->and($d['requires_review'])->toBeFalse()
        ->and($d['policy_version']['version_no'])->toBe(1)->and($d['coverage']['limit_minor'])->toBe(50_000_000)
        ->and(c3Codes($d))->toBe(['COVERAGE_CONFIRMED']);
    expect(DB::table('claim_coverage_checks')->count())->toBe(0);
});

it('REQ-CLM-003 requires the permission and scopes the policy to the tenant', function () {
    $f = c3Policy();
    Passport::actingAs(makeAuthTestUser($f['tenant'], ['claims.view']));
    $this->postJson('/api/v1/claims/coverage/check', ['policy_id' => $f['policy']->id, 'loss_occurred_at' => $f['loss']->toIso8601String()], tenantHeader($f['tenant']))->assertForbidden();

    $other = makeAuthTestTenant('other');
    Passport::actingAs(makeAuthTestUser($other, ['claims.coverage.check']));
    $this->postJson('/api/v1/claims/coverage/check', ['policy_id' => $f['policy']->id, 'loss_occurred_at' => $f['loss']->toIso8601String()], tenantHeader($other))->assertNotFound();
});

it('REQ-CLM-003 flags coverage not held, loss outside the period and suspension as OUTSIDE_COVERAGE without rejecting', function () {
    $f = c3Policy();
    $engine = app(CoverageAtLossEngine::class);

    $r = $engine->evaluate($f['policy'], $f['loss'], now()->addDays(3), 'XYZ');
    expect($r['outcome'])->toBe('OUTSIDE_COVERAGE')->and($r['requires_review'])->toBeTrue()->and($r['auto_rejected'])->toBeFalse()
        ->and(c3Codes($r))->toContain('COVERAGE_NOT_HELD');

    $r = $engine->evaluate($f['policy'], $f['policy']->coverage_ends_at->copy()->addDays(5), now(), 'RC');
    expect($r['outcome'])->toBe('OUTSIDE_COVERAGE')->and(c3Codes($r))->toContain('LOSS_OUTSIDE_POLICY_PERIOD');

    DB::table('policy_suspensions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'policy_id' => $f['policy']->id, 'status' => 'REINSTATED', 'source' => 'MANUAL',
        'reason_code' => 'TEST', 'suspended_at' => $f['loss']->copy()->subDay(), 'reinstated_at' => $f['loss']->copy()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $r = $engine->evaluate($f['policy'], $f['loss'], now()->addDays(3), 'RC');
    expect($r['outcome'])->toBe('OUTSIDE_COVERAGE')->and(c3Codes($r))->toContain('POLICY_SUSPENDED');
    // After reinstatement the policy covers again.
    expect($engine->evaluate($f['policy'], $f['loss']->copy()->addDays(2), now()->addDays(5), 'RC')['outcome'])->toBe('COVERAGE_CONFIRMED');
});

it('REQ-CLM-003 surfaces product exclusions with their legal text; unknown facts need review', function () {
    $f = c3Policy();
    c3Exclusion($f, 'RACING', ['op' => 'EQUAL', 'left' => ['fact' => 'loss.cause'], 'right' => ['value' => 'RACING']]);
    $engine = app(CoverageAtLossEngine::class);

    $r = $engine->evaluate($f['policy'], $f['loss'], now()->addDays(3), 'RC', ['loss' => ['cause' => 'RACING']]);
    expect($r['outcome'])->toBe('POTENTIAL_EXCLUSION')->and(c3Codes($r))->toContain('EXCLUSION_TRIGGERED')
        ->and($r['exclusions'][0]['legal_text']['legal_reference'])->toBe('Art. 12')->and($r['exclusions'][0]['legal_text']['text']['en'])->toBe('Racing is excluded.');

    expect($engine->evaluate($f['policy'], $f['loss'], now()->addDays(3), 'RC', ['loss' => ['cause' => 'COLLISION']])['outcome'])->toBe('COVERAGE_CONFIRMED');

    $r = $engine->evaluate($f['policy'], $f['loss'], now()->addDays(3), 'RC');
    expect($r['outcome'])->toBe('REVIEW_REQUIRED')->and(c3Codes($r))->toContain('EXCLUSION_FACTS_MISSING');
});

it('REQ-CLM-003 reads premium-to-cover state and retroactive chronology changes', function () {
    $f = c3Policy();
    $engine = app(CoverageAtLossEngine::class);

    // Reported before the chronology row was recorded → the contract "as known at report" differs.
    $r = $engine->evaluate($f['policy'], $f['loss'], now()->subDay(), 'RC');
    expect($r['outcome'])->toBe('REVIEW_REQUIRED')->and(c3Codes($r))->toContain('CHRONOLOGY_CHANGED_SINCE_REPORT')
        ->and($r['policy_version_known_at_report'])->toBeNull();

    DB::table('policy_premium_instalments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'policy_id' => $f['policy']->id, 'sequence' => 2,
        'due_date' => $f['loss']->copy()->subDay()->toDateString(), 'amount_minor' => 5000, 'currency' => 'XAF', 'status' => 'OVERDUE', 'created_at' => now(), 'updated_at' => now()]);
    $r = $engine->evaluate($f['policy'], $f['loss'], now()->addDays(3), 'RC');
    expect($r['outcome'])->toBe('REVIEW_REQUIRED')->and(c3Codes($r))->toContain('PREMIUM_COVER_UNDETERMINED')->and($r['premium']['state'])->toBe('UNPAID');

    DB::table('policy_premium_instalments')->where('policy_id', $f['policy']->id)->update(['status' => 'LAPSED', 'lapsed_at' => $f['loss']->copy()->subHour()]);
    expect(c3Codes($engine->evaluate($f['policy'], $f['loss'], now()->addDays(3), 'RC')))->toContain('POLICY_LAPSED');
});

it('REQ-CLM-003 stores an immutable per-claim snapshot, blocks approval until a different handler resolves it', function () {
    $f = c3Policy();
    $claim = c3Claim($f);
    $checker = makeAuthTestUser($f['tenant'], ['claims.coverage.check', 'claims.view', 'claims.coverage.resolve']);
    $resolver = makeAuthTestUser($f['tenant'], ['claims.coverage.resolve']);
    Passport::actingAs($checker);

    $row = $this->postJson("/api/v1/claims/{$claim->id}/coverage-checks", ['facts' => []], tenantHeader($f['tenant']))->assertCreated()->json('data');
    expect($row['outcome'])->toBe('COVERAGE_CONFIRMED')->and($row['coverage_code'])->toBe('RC');

    $row = $this->postJson("/api/v1/claims/{$claim->id}/coverage-checks", ['coverage_code' => 'XYZ'], tenantHeader($f['tenant']))->assertCreated()->json('data');
    expect($row['outcome'])->toBe('OUTSIDE_COVERAGE')->and($row['review_open'])->toBeTrue()->and($row['snapshot']['auto_rejected'])->toBeFalse();
    expect(Claim::find($claim->id)->status)->toBe('CARRIER_REVIEW');
    expect(DB::table('outbox_messages')->where('event_name', 'claim.coverage.checked')->where('aggregate_id', $claim->id)->count())->toBe(2);

    // The guard class needs agent C1's ClaimTransitionGuard contract; the gate logic is exercised either way.
    $gate = interface_exists(\App\Domain\Claims\ClaimTransitionGuard::class)
        ? fn (string $e) => app(ClaimCoverageTransitionGuard::class)->check($claim, $e, [])
        : fn (string $e) => in_array($e, ClaimCoverageCheckService::APPROVAL_EVENTS, true) ? app(ClaimCoverageCheckService::class)->approvalBlocker($claim) : null;
    expect($gate('APPROVED'))->toBe('COVERAGE_REVIEW_UNRESOLVED')->and($gate('CLOSED'))->toBeNull();

    expect(fn () => DB::transaction(fn () => DB::table('claim_coverage_checks')->where('id', $row['id'])->update(['outcome' => 'COVERAGE_CONFIRMED'])))->toThrow(Illuminate\Database\QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('claim_coverage_checks')->where('id', $row['id'])->delete()))->toThrow(Illuminate\Database\QueryException::class);

    $this->postJson("/api/v1/claims/{$claim->id}/coverage-checks/{$row['id']}/resolve", ['resolution' => 'COVERED', 'note' => 'mapped to RC'], tenantHeader($f['tenant']))->assertStatus(422);

    Passport::actingAs($resolver);
    $done = $this->postJson("/api/v1/claims/{$claim->id}/coverage-checks/{$row['id']}/resolve", ['resolution' => 'COVERED', 'note' => 'Loss maps to RC.'], tenantHeader($f['tenant']))->assertOk()->json('data');
    expect($done['resolution'])->toBe('COVERED')->and($done['review_open'])->toBeFalse()->and($gate('APPROVED'))->toBeNull();
    $this->postJson("/api/v1/claims/{$claim->id}/coverage-checks/{$row['id']}/resolve", ['resolution' => 'NOT_COVERED', 'note' => 'changed mind'], tenantHeader($f['tenant']))->assertStatus(422);

    Passport::actingAs($checker);
    expect($this->getJson("/api/v1/claims/{$claim->id}/coverage-checks", tenantHeader($f['tenant']))->assertOk()->json('data'))->toHaveCount(2);
    expect(app(ClaimCoverageCheckService::class)->approvalBlocker($claim))->toBeNull();
});
