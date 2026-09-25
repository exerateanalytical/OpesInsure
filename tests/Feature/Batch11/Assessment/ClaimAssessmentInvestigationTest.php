<?php

declare(strict_types=1);

use App\Application\Cases\Models\WorkCase;
use App\Application\Claims\Assessment\AssessmentDecisionReadiness;
use App\Application\Claims\Assessment\ClaimAssessmentTransitionGuard;
use App\Application\Claims\Assessment\Models\ClaimAssessment;
use App\Models\Claim;
use App\Models\Document;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

/** REQ-CLM-010 / WF-054/055 — assessment (recommendation, never a decision) and investigation. */
function c10World(string $status = 'UNDER_ASSESSMENT'): array
{
    $fx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = makeMobileTestPolicy($fx['proposal'], $fx['tenant'], $fx['carrier']->id, $fx['party']->id);
    $claim = Claim::create(['tenant_id' => $fx['tenant']->id, 'policy_id' => $policy->id, 'claim_number' => 'CLM-C10-'.Str::random(6), 'status' => $status,
        'loss_occurred_at' => now()->subDays(3), 'loss_details' => ['what' => 'collision'], 'currency' => 'XAF']);
    $perms = ['claims.view', 'claims.assessment.record', 'claims.assessment.review', 'claims.investigation.manage', 'claims.investigation.conclude'];
    $assessor = makeAuthTestUser($fx['tenant'], $perms, 'C10_ASSESSOR');
    $reviewer = makeAuthTestUser($fx['tenant'], $perms, 'C10_REVIEWER');

    return [$fx, $claim, $assessor, $reviewer];
}

function c10Payload(array $extra = []): array
{
    return ['heads' => [
        ['head_code' => 'vehicle_damage', 'claimed_minor' => 500000, 'recommended_minor' => 420000, 'note' => 'Bumper and bonnet'],
        ['head_code' => 'TOWING', 'recommended_minor' => 30000],
    ], 'rationale' => 'Repair quote verified against the parts price list.', ...$extra];
}

it('records an assessment as a recommendation per head without deciding the claim', function () {
    [$fx, $claim, $assessor] = c10World();
    $doc = Document::create(['tenant_id' => $fx['tenant']->id, 'category' => 'ADJUSTER_REPORT', 'storage_key' => 'k/'.Str::random(8), 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64)]);
    Passport::actingAs($assessor);

    $res = $this->postJson("/api/v1/claims/{$claim->id}/assessments", c10Payload(['adjuster_report_document_id' => $doc->id]), tenantHeaderFor($fx['tenant']))
        ->assertCreated()->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.recommended_total_minor', 450000)
        ->assertJsonPath('data.heads.0.head_code', 'VEHICLE_DAMAGE')->assertJsonPath('data.assessor_user_id', $assessor->id)
        ->assertJsonPath('data.adjuster_report_document_id', $doc->id);

    $claim->refresh();
    expect($claim->status)->toBe('UNDER_ASSESSMENT')->and($claim->approved_amount_minor)->toBeNull()
        ->and(DB::table('outbox_messages')->where('event_name', 'claim.assessment.recorded')->where('aggregate_id', $claim->id)->exists())->toBeTrue();

    // Unknown / foreign adjuster report and duplicated heads are refused.
    $this->postJson("/api/v1/claims/{$claim->id}/assessments", c10Payload(['adjuster_report_document_id' => (string) Str::uuid()]), tenantHeaderFor($fx['tenant']))->assertUnprocessable();
    $dup = c10Payload();
    $dup['heads'][1]['head_code'] = 'Vehicle_Damage';
    $this->postJson("/api/v1/claims/{$claim->id}/assessments", $dup, tenantHeaderFor($fx['tenant']))->assertUnprocessable();

    // Content is immutable at database level.
    expect(fn () => DB::transaction(fn () => DB::table('claim_assessments')->where('id', $res->json('data.id'))->update(['recommended_total_minor' => 1])))->toThrow(QueryException::class);
});

it('accepts with four eyes, supersedes the previous accepted assessment and gates DECISION_PENDING', function () {
    [$fx, $claim, $assessor, $reviewer] = c10World();
    $readiness = app(AssessmentDecisionReadiness::class);
    expect($readiness->blockingReason($claim))->toBe('CLAIM_ASSESSMENT_REQUIRED');

    Passport::actingAs($assessor);
    $first = $this->postJson("/api/v1/claims/{$claim->id}/assessments", c10Payload(), tenantHeaderFor($fx['tenant']))->json('data.id');
    $this->postJson("/api/v1/claims/assessments/{$first}/accept", [], tenantHeaderFor($fx['tenant']))->assertUnprocessable(); // own assessment
    expect($readiness->blockingReason($claim))->toBe('CLAIM_ASSESSMENT_REQUIRED');

    Passport::actingAs($reviewer);
    $this->postJson("/api/v1/claims/assessments/{$first}/accept", ['note' => 'Agreed.'], tenantHeaderFor($fx['tenant']))->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
    expect($readiness->blockingReason($claim))->toBeNull()
        ->and(AssessmentDecisionReadiness::targetsDecisionPending('any', ['to' => 'DECISION_PENDING']))->toBeTrue()
        ->and(AssessmentDecisionReadiness::targetsDecisionPending('approve', ['to' => 'APPROVED']))->toBeFalse();

    Passport::actingAs($assessor);
    $second = $this->postJson("/api/v1/claims/{$claim->id}/assessments", c10Payload(), tenantHeaderFor($fx['tenant']))->json('data.id');
    Passport::actingAs($reviewer);
    $this->postJson("/api/v1/claims/assessments/{$second}/accept", [], tenantHeaderFor($fx['tenant']))->assertOk();
    expect(ClaimAssessment::find($first)->status)->toBe('SUPERSEDED');
    $this->postJson("/api/v1/claims/assessments/{$second}/reject", ['reason' => 'Too late'], tenantHeaderFor($fx['tenant']))->assertUnprocessable();

    if (interface_exists(\App\Domain\Claims\ClaimTransitionGuard::class)) {
        expect(collect(app()->tagged('claims.transition_guards'))->contains(fn ($g) => $g instanceof ClaimAssessmentTransitionGuard))->toBeTrue();
    }
});

it('opens an investigation through the case engine, keeps indicators immutable and blocks the decision while open', function () {
    [$fx, $claim, $assessor, $reviewer] = c10World();
    Passport::actingAs($assessor);
    $a = $this->postJson("/api/v1/claims/{$claim->id}/assessments", c10Payload(), tenantHeaderFor($fx['tenant']))->json('data.id');
    Passport::actingAs($reviewer);
    $this->postJson("/api/v1/claims/assessments/{$a}/accept", [], tenantHeaderFor($fx['tenant']))->assertOk();

    $inv = $this->postJson("/api/v1/claims/{$claim->id}/investigations", ['reason_code' => 'FRAUD_INDICATORS', 'reason' => 'Loss reported two days after inception.'], tenantHeaderFor($fx['tenant']))
        ->assertCreated()->assertJsonPath('data.status', 'OPEN')->json('data');
    $case = WorkCase::withoutGlobalScopes()->find($inv['case_id']);
    expect($case->case_type_code)->toBe('CLAIM_INVESTIGATION')->and($case->subject_id)->toBe($claim->id)->and($case->confidentiality)->toBe('RESTRICTED')
        ->and(app(AssessmentDecisionReadiness::class)->blockingReason($claim))->toBe('CLAIM_INVESTIGATION_OPEN');
    $this->postJson("/api/v1/claims/{$claim->id}/investigations", ['reason_code' => 'X', 'reason' => 'Second one'], tenantHeaderFor($fx['tenant']))->assertUnprocessable();

    $ind = [['indicator_id' => 'IND-1', 'indicator_code' => 'EARLY_CLAIM', 'snapshot' => ['score' => 70, 'rule' => 'days_since_inception<30']]];
    $this->postJson("/api/v1/claims/investigations/{$inv['id']}/indicators", ['indicators' => $ind], tenantHeaderFor($fx['tenant']))->assertCreated()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/claims/investigations/{$inv['id']}/indicators", ['indicators' => $ind], tenantHeaderFor($fx['tenant']))->assertCreated()->assertJsonCount(0, 'data');
    expect(fn () => DB::transaction(fn () => DB::table('claim_investigation_indicators')->where('indicator_id', 'IND-1')->update(['snapshot' => '{}'])))->toThrow(QueryException::class);

    $this->postJson("/api/v1/claims/investigations/{$inv['id']}/conclude", ['outcome' => 'NO_FRAUD_FOUND', 'summary' => 'Nothing found'], tenantHeaderFor($fx['tenant']))->assertUnprocessable(); // no findings
    $this->postJson("/api/v1/claims/investigations/{$inv['id']}/findings", ['findings' => 'Garage invoice and police report are consistent.'], tenantHeaderFor($fx['tenant']))->assertOk();
    $this->postJson("/api/v1/claims/investigations/{$inv['id']}/conclude", ['outcome' => 'NO_FRAUD_FOUND', 'summary' => 'Genuine loss.'], tenantHeaderFor($fx['tenant']))
        ->assertOk()->assertJsonPath('data.status', 'CONCLUDED')->assertJsonPath('data.indicators.0.snapshot.score', 70);

    expect(app(AssessmentDecisionReadiness::class)->blockingReason($claim))->toBeNull()
        ->and(fn () => DB::transaction(fn () => DB::table('claim_investigations')->where('id', $inv['id'])->update(['outcome' => 'FRAUD_CONFIRMED'])))->toThrow(QueryException::class);
    $this->postJson("/api/v1/claims/investigations/{$inv['id']}/findings", ['findings' => 'Late edit attempt'], tenantHeaderFor($fx['tenant']))->assertUnprocessable();
    expect(DB::table('outbox_messages')->where('aggregate_id', $claim->id)->whereIn('event_name', ['claim.investigation.opened', 'claim.investigation.indicators_attached', 'claim.investigation.concluded'])->count())->toBe(3);
});

it('enforces permissions and tenant isolation', function () {
    [$fx, $claim, $assessor] = c10World();
    $viewer = makeAuthTestUser($fx['tenant'], ['claims.view'], 'C10_VIEWER');
    Passport::actingAs($viewer);
    $this->postJson("/api/v1/claims/{$claim->id}/assessments", c10Payload(), tenantHeaderFor($fx['tenant']))->assertForbidden();
    $this->getJson("/api/v1/claims/{$claim->id}/assessments", tenantHeaderFor($fx['tenant']))->assertOk()->assertJsonPath('data.decision_blocker', 'CLAIM_ASSESSMENT_REQUIRED');

    [$other, , $otherUser] = c10World();
    Passport::actingAs($otherUser);
    $this->postJson("/api/v1/claims/{$claim->id}/assessments", c10Payload(), tenantHeaderFor($other['tenant']))->assertNotFound();
});
