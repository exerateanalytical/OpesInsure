<?php

declare(strict_types=1);

use App\Application\Claims\ClaimLifecycleService;
use App\Application\Claims\Types\ClaimReportingService;
use App\Application\Claims\Types\ClaimTypeCatalogue;
use App\Domain\Claims\ClaimTransitionBlocked;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function c8Staff(Tenant $t, array $perms): User
{
    $u = User::create(['full_name' => 'C8 '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'CLAIMS_OFFICER', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'C8_'.Str::random(5), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

/** Motor (quote line AUTO) policy + staff FNOL through the one FNOL path. */
function c8World(): array
{
    $f = makeMobileCustomerFixture('+2376702'.random_int(10000, 99999));
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);
    $staff = c8Staff($f['tenant'], ['claims.view', 'claims.create', 'claims.transition', 'claims.types.manage', 'claims.types.approve', 'claims.late_report.recommend', 'claims.late_report.approve']);
    app(TenantContext::class)->set($f['tenant']->id);

    return $f + ['policy' => $policy, 'staff' => $staff];
}

function c8Fnol(array $w, int $daysAgo, array $extra = []): Claim
{
    Passport::actingAs($w['staff']);
    $res = test()->postJson('/api/v1/claims/fnol', array_merge(['policy_id' => $w['policy']->id, 'claimant_party_id' => $w['party']->id,
        'loss_occurred_at' => now()->subDays($daysAgo)->toIso8601String(), 'loss_details' => ['description' => 'Collision at a junction'],
        'channel' => 'PHONE', 'idempotency_key' => (string) Str::uuid()], $extra), tenantHeaderFor($w['tenant']))->assertStatus(201);
    app(TenantContext::class)->set($w['tenant']->id);

    return Claim::findOrFail($res->json('data.id'));
}

it('seeds platform-default claim types for motor, health, property, life and travel (labels en/fr, coverages, evidence pack, reserve)', function () {
    $cat = app(ClaimTypeCatalogue::class);
    foreach (['MOTOR', 'HEALTH', 'PROPERTY', 'LIFE', 'TRAVEL'] as $line) {
        $types = $cat->effective(null, $line);
        expect($types)->not->toBeEmpty()
            ->and(collect($types)->where('is_default', true))->toHaveCount(1);
        foreach ($types as $t) {
            expect($t['labels'])->toHaveKeys(['en', 'fr'])->and($t['source'])->toBe('PLATFORM_DEFAULT')
                ->and($t['evidence_pack_code'])->toContain('CLAIM')
                ->and($t['reporting_deadline_notice'])->toContain('not a legal deadline');
        }
    }
    $motor = collect($cat->effective(null, 'AUTO'))->keyBy('code');
    expect($motor->keys()->all())->toBe(['MOTOR_DAMAGE', 'MOTOR_TP_BODILY_INJURY', 'MOTOR_TP_PROPERTY_DAMAGE', 'THEFT'])
        ->and($motor['MOTOR_DAMAGE']['reporting_deadline_days'])->toBe(5)->and($motor['MOTOR_DAMAGE']['default_reserve_minor'])->toBe(500000)
        ->and($motor['MOTOR_DAMAGE']['applicable_coverages'])->toContain('OWN_DAMAGE');
});

it('flags (never refuses) a claim reported after the reporting period and opens a late-report case', function () {
    $w = c8World();
    $late = c8Fnol($w, 20);
    $check = DB::table('claim_reporting_checks')->where('claim_id', $late->id)->first();
    expect($late->status)->toBe('SUBMITTED')
        ->and($check->claim_type_code)->toBe('MOTOR_DAMAGE')->and($check->line_code)->toBe('MOTOR')
        ->and((bool) $check->late)->toBeTrue()->and((int) $check->deadline_days)->toBe(5)->and((int) $check->days_late)->toBe(15)
        ->and($check->approval_status)->toBe('PENDING')->and($check->case_id)->not->toBeNull();
    $case = DB::table('cases')->where('id', $check->case_id)->first();
    expect($case->case_type_code)->toBe('CLAIM_COVERAGE_REVIEW')->and($case->case_subtype)->toBe('LATE_REPORTING')->and($case->subject_id)->toBe($late->id)
        ->and(DB::table('outbox_messages')->where(['event_name' => 'claim.reported_late', 'aggregate_id' => $late->id])->exists())->toBeTrue();

    $onTime = c8Fnol($w, 1, ['loss_details' => ['description' => 'Hit a parked car', 'claim_type' => 'MOTOR_TP_PROPERTY_DAMAGE']]);
    $c2 = DB::table('claim_reporting_checks')->where('claim_id', $onTime->id)->first();
    expect((bool) $c2->late)->toBeFalse()->and($c2->approval_status)->toBe('NOT_REQUIRED')->and($c2->claim_type_code)->toBe('MOTOR_TP_PROPERTY_DAMAGE');

    $this->getJson("/api/v1/claims/{$late->id}/reporting-check", tenantHeaderFor($w['tenant']))->assertOk()
        ->assertJsonPath('data.approval_status', 'PENDING')->assertJsonPath('meta.notice', ClaimTypeCatalogue::DEADLINE_NOTICE);
});

it('blocks start_assessment on a late claim until a checker other than the maker approves it', function () {
    $w = c8World();
    $claim = c8Fnol($w, 20);
    $checker = c8Staff($w['tenant'], ['claims.view', 'claims.late_report.approve']);
    $life = app(ClaimLifecycleService::class);
    $claim = $life->transition($claim, 'ACKNOWLEDGED', 'TRIAGED', [], $w['staff']);

    try {
        $life->transition($claim, 'ASSESSMENT', 'START', [], $w['staff']);
        $this->fail('late claim was not blocked');
    } catch (ClaimTransitionBlocked $e) {
        expect($e->reasonCode)->toBe('LATE_CLAIM_APPROVAL_REQUIRED')->and($e->event)->toBe('start_assessment');
    }

    // maker recommends (HTTP), maker cannot decide, checker approves
    $h = tenantHeaderFor($w['tenant']);
    Passport::actingAs($w['staff']);
    $this->postJson("/api/v1/claims/{$claim->id}/late-report/decide", ['decision' => 'APPROVE', 'rationale' => 'Reported late due to hospitalisation.'], $h)->assertStatus(422);
    $this->postJson("/api/v1/claims/{$claim->id}/late-report/recommend", ['recommendation' => 'ACCEPT', 'rationale' => 'Insured was hospitalised after the accident.'], $h)
        ->assertOk()->assertJsonPath('data.approval_status', 'RECOMMENDED');
    $this->postJson("/api/v1/claims/{$claim->id}/late-report/decide", ['decision' => 'APPROVE', 'rationale' => 'Maker approving own recommendation.'], $h)
        ->assertStatus(422)->assertJsonValidationErrors('decided_by');
    Passport::actingAs($checker);
    $this->postJson("/api/v1/claims/{$claim->id}/late-report/decide", ['decision' => 'APPROVE', 'rationale' => 'Hospital record supports the delay.'], $h)
        ->assertOk()->assertJsonPath('data.approval_status', 'APPROVED');

    app(TenantContext::class)->set($w['tenant']->id);
    $check = DB::table('claim_reporting_checks')->where('claim_id', $claim->id)->first();
    expect(DB::table('case_decisions')->where(['case_id' => $check->case_id, 'decision_type' => 'LATE_CLAIM_REPORT', 'outcome' => 'APPROVED'])->exists())->toBeTrue()
        ->and($life->transition($claim, 'ASSESSMENT', 'START', [], $w['staff'])->status)->toBe('ASSESSMENT');
});

it('keeps a rejected late claim out of assessment', function () {
    $w = c8World();
    $claim = c8Fnol($w, 30);
    $checker = c8Staff($w['tenant'], ['claims.view']);
    $svc = app(ClaimReportingService::class);
    $svc->recommend($claim, 'REJECT', 'No reason given for the delay.', $w['staff']);
    $svc->decide($claim, 'REJECT', 'Delay prejudiced the investigation.', $checker);
    $claim = app(ClaimLifecycleService::class)->transition($claim, 'ACKNOWLEDGED', 'TRIAGED', [], $w['staff']);

    expect(fn () => app(ClaimLifecycleService::class)->transition($claim, 'ASSESSMENT', 'START', [], $w['staff']))
        ->toThrow(ClaimTransitionBlocked::class);
    expect($svc->assessmentBlocker($claim))->toBe('LATE_CLAIM_REJECTED');
});

it('lets an insurer override the platform default reporting period (maker-checker, versioned)', function () {
    $w = c8World();
    $checker = c8Staff($w['tenant'], ['claims.view', 'claims.types.approve']);
    $h = tenantHeaderFor($w['tenant']);
    Passport::actingAs($w['staff']);
    $draft = $this->postJson('/api/v1/claim-types', ['code' => 'MOTOR_DAMAGE', 'line_code' => 'MOTOR', 'reporting_deadline_days' => 30], $h)
        ->assertStatus(201)->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.source', 'INSURER_OVERRIDE')
        ->assertJsonPath('data.labels.fr', 'Dommages au véhicule')->json('data');
    $this->postJson("/api/v1/claim-types/{$draft['id']}/approve", [], $h)->assertStatus(422);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/claim-types/{$draft['id']}/approve", [], $h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    $this->getJson('/api/v1/claim-types?line_code=AUTO', $h)->assertOk()->assertJsonPath('data.0.code', 'MOTOR_DAMAGE')
        ->assertJsonPath('data.0.reporting_deadline_days', 30)->assertJsonPath('data.0.scope', 'TENANT:'.$w['tenant']->id);
    // platform default untouched for other tenants
    expect(collect(app(ClaimTypeCatalogue::class)->effective(null, 'MOTOR'))->firstWhere('code', 'MOTOR_DAMAGE')['reporting_deadline_days'])->toBe(5);

    $claim = c8Fnol($w, 20);
    expect((bool) DB::table('claim_reporting_checks')->where('claim_id', $claim->id)->value('late'))->toBeFalse();
});

it('rejects an unknown claim type code for the line and requires the permission to override', function () {
    $w = c8World();
    expect(fn () => app(ClaimTypeCatalogue::class)->resolve(makeMobileTestClaim($w['tenant'], $w['policy'], $w['party']), 'LIFE_DEATH'))
        ->toThrow(ValidationException::class);
    Passport::actingAs(c8Staff($w['tenant'], ['claims.view']));
    $this->postJson('/api/v1/claim-types', ['code' => 'MOTOR_DAMAGE', 'line_code' => 'MOTOR'], tenantHeaderFor($w['tenant']))->assertStatus(403);
});
