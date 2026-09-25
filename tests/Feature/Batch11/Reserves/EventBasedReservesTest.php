<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function c5Staff(Tenant $t, array $perms): User
{
    $u = User::create(['full_name' => 'Res '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'CLM', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'C5_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

/** @return array{f: array, claim: string, maker: User, checker: User} */
function c5World(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = (string) Str::uuid();
    DB::table('policies')->insert([
        'id' => $policy, 'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(), 'issued_at' => now()->subMonth(),
        'terms_snapshot' => json_encode(['line_code' => 'AUTO']), 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 50000, 'is_demo' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $claim = (string) Str::uuid();
    DB::table('claims')->insert(['id' => $claim, 'tenant_id' => $f['tenant']->id, 'policy_id' => $policy, 'claimant_party_id' => $f['party']->id, 'claim_number' => 'CLM-'.Str::random(8),
        'status' => 'UNDER_REVIEW', 'loss_occurred_at' => now()->subDays(3), 'loss_details' => '{}', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        'currency' => 'XAF', 'priority' => 'NORMAL', 'current_reserve_minor' => 0, 'version' => 1, 'is_demo' => false]);
    $perms = ['claims.view', 'claims.reserve.request', 'claims.reserve.approve'];

    return ['f' => $f, 'claim' => $claim, 'maker' => c5Staff($f['tenant'], $perms), 'checker' => c5Staff($f['tenant'], $perms)];
}

function c5Request(array $w, array $body): array
{
    Passport::actingAs($w['maker']);

    return test()->postJson("/api/v1/claims/{$w['claim']}/reserves", $body, tenantHeaderFor($w['f']['tenant']))->assertCreated()->json('data');
}

function c5Approve(array $w, string $id, ?User $as = null)
{
    Passport::actingAs($as ?? $w['checker']);

    return test()->postJson("/api/v1/claims/{$w['claim']}/reserves/{$id}/approve", [], tenantHeaderFor($w['f']['tenant']));
}

it('keeps INITIAL/CURRENT/FINAL reserves per head with signed movements and a claim total', function () {
    $w = c5World();
    $r1 = c5Request($w, ['amount_minor' => 100000, 'reason_code' => 'INITIAL_ESTIMATE', 'reserve_head' => 'INDEMNITY']);
    expect($r1['reserve_stage'])->toBe('INITIAL');
    c5Approve($w, $r1['id'])->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.movement_minor', 100000);

    $e1 = c5Request($w, ['amount_minor' => 20000, 'reason_code' => 'LEGAL_DEVELOPMENT', 'reserve_type' => 'LEGAL']);
    expect($e1['reserve_head'])->toBe('EXPENSE')->and($e1['reserve_stage'])->toBe('INITIAL');
    c5Approve($w, $e1['id'])->assertOk();

    $r2 = c5Request($w, ['amount_minor' => 70000, 'reason_code' => 'EXPERT_REPORT', 'reserve_head' => 'INDEMNITY']);
    expect($r2['reserve_stage'])->toBe('CURRENT')->and($r2['previous_amount_minor'])->toBe(100000);
    c5Approve($w, $r2['id'])->assertOk()->assertJsonPath('data.movement_minor', -30000);

    expect((int) DB::table('claims')->where('id', $w['claim'])->value('current_reserve_minor'))->toBe(90000);

    $fin = c5Request($w, ['amount_minor' => 65000, 'reason_code' => 'FINAL_SETTLEMENT', 'reserve_head' => 'INDEMNITY', 'final' => true]);
    expect($fin['reserve_stage'])->toBe('FINAL');
    c5Approve($w, $fin['id'])->assertOk();
    Passport::actingAs($w['maker']);
    $this->postJson("/api/v1/claims/{$w['claim']}/reserves", ['amount_minor' => 1, 'reason_code' => 'CORRECTION', 'reserve_head' => 'INDEMNITY'], tenantHeaderFor($w['f']['tenant']))
        ->assertStatus(422);

    $pos = $this->getJson("/api/v1/claims/{$w['claim']}/reserve-position", tenantHeaderFor($w['f']['tenant']))->assertOk()->json('data');
    expect($pos['total_reserve_minor'])->toBe(85000)->and($pos['positions'])->toHaveCount(2)->and($pos['movements'])->toHaveCount(4);

    // Outbox + event catalogue events.
    expect(DB::table('outbox_messages')->where('event_name', 'claim.reserve.changed')->count())->toBe(4)
        ->and(DB::table('outbox_messages')->where('event_name', 'claim.reserve.requested')->count())->toBe(4);
});

it('rejects unknown reason codes on head/coverage movements and coverages not on the policy', function () {
    $w = c5World();
    Passport::actingAs($w['maker']);
    $h = tenantHeaderFor($w['f']['tenant']);
    $this->postJson("/api/v1/claims/{$w['claim']}/reserves", ['amount_minor' => 5, 'reason_code' => 'WHATEVER', 'reserve_head' => 'INDEMNITY'], $h)->assertStatus(422);

    $pv = (string) Str::uuid();
    $policy = DB::table('claims')->where('id', $w['claim'])->value('policy_id');
    DB::table('policy_versions')->insert(['id' => $pv, 'tenant_id' => $w['f']['tenant']->id, 'policy_id' => $policy, 'version_no' => 1, 'kind' => 'ISSUANCE',
        'valid_from' => now()->subMonth(), 'recorded_at' => now()->subMonth(), 'schema_version' => 1, 'snapshot' => '{}', 'snapshot_hash' => str_repeat('0', 64)]);
    DB::table('policy_coverages')->insert(['id' => (string) Str::uuid(), 'policy_id' => $policy, 'policy_version_id' => $pv, 'coverage_code' => 'TPL', 'currency' => 'XAF', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addYear()]);
    $this->postJson("/api/v1/claims/{$w['claim']}/reserves", ['amount_minor' => 5, 'reason_code' => 'INITIAL_ESTIMATE', 'coverage_code' => 'FIRE'], $h)->assertStatus(422);
    $this->postJson("/api/v1/claims/{$w['claim']}/reserves", ['amount_minor' => 5, 'reason_code' => 'INITIAL_ESTIMATE', 'coverage_code' => 'TPL'], $h)->assertCreated();
    // Legacy call shape (no head/coverage) still works with free reason codes; separate slot from TPL.
    $this->postJson("/api/v1/claims/{$w['claim']}/reserves", ['amount_minor' => 5, 'reason_code' => 'ANYTHING'], $h)->assertCreated();
    // One pending movement per head/coverage.
    $this->postJson("/api/v1/claims/{$w['claim']}/reserves", ['amount_minor' => 6, 'reason_code' => 'ANYTHING'], $h)->assertStatus(422);
});

it('enforces maker-checker', function () {
    $w = c5World();
    $r = c5Request($w, ['amount_minor' => 1000, 'reason_code' => 'INITIAL_ESTIMATE', 'reserve_head' => 'INDEMNITY']);
    c5Approve($w, $r['id'], $w['maker'])->assertStatus(422);
});

it('posts claim.reserve.changed journals: increase on the mapping, release reversed', function () {
    $w = c5World();
    $a = c5Request($w, ['amount_minor' => 40000, 'reason_code' => 'INITIAL_ESTIMATE', 'reserve_head' => 'INDEMNITY']);
    c5Approve($w, $a['id'])->assertOk();
    $b = c5Request($w, ['amount_minor' => 10000, 'reason_code' => 'NEW_INFORMATION', 'reserve_head' => 'INDEMNITY']);
    c5Approve($w, $b['id'])->assertOk();

    $lines = fn (string $ref) => DB::table('journals')->join('journal_lines', 'journal_lines.journal_id', '=', 'journals.id')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.account_id')
        ->where('journals.reference_type', 'claim.reserve.changed')->where('journals.reference_id', $ref)
        ->get(['ledger_accounts.code', 'journal_lines.debit_minor', 'journal_lines.credit_minor'])->keyBy('code');

    $up = $lines($a['id']);
    expect((int) $up['601000']->debit_minor)->toBe(40000)->and((int) $up['481500']->credit_minor)->toBe(40000);
    $down = $lines($b['id']);
    expect((int) $down['481500']->debit_minor)->toBe(30000)->and((int) $down['601000']->credit_minor)->toBe(30000);
    expect(DB::table('claim_reserve_changes')->where('id', $b['id'])->value('journal_id'))->not->toBeNull();
});

it('refers an approval over the RESERVE_APPROVE limit instead of failing, and a senior approver completes it', function () {
    $w = c5World();
    $limit = fn (User $u, int $max) => DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $w['f']['carrier']->id, 'holder_type' => 'USER',
        'holder_id' => $u->id, 'authority_type' => 'RESERVE_APPROVE', 'line_code' => null, 'max_amount_minor' => $max, 'currency' => 'XAF',
        'effective_from' => now()->subYear()->toDateString(), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $limit($w['checker'], 50000);
    $senior = c5Staff($w['f']['tenant'], ['claims.reserve.approve']);
    $limit($senior, 1000000);

    $r = c5Request($w, ['amount_minor' => 80000, 'reason_code' => 'INITIAL_ESTIMATE', 'reserve_head' => 'INDEMNITY']);
    $res = c5Approve($w, $r['id'])->assertStatus(202)->assertJsonPath('data.status', 'REFERRED')->json('data');
    expect($res['referral_case_id'])->not->toBeNull()
        ->and(DB::table('cases')->where('id', $res['referral_case_id'])->value('case_type_code'))->toBe('AUTHORITY_REFERRAL')
        ->and(DB::table('authority_checks')->where('subject_id', $r['id'])->value('outcome'))->toBe('REFERRED')
        ->and((int) DB::table('claims')->where('id', $w['claim'])->value('current_reserve_minor'))->toBe(0)
        ->and(DB::table('journals')->where('reference_id', $r['id'])->exists())->toBeFalse();

    c5Approve($w, $r['id'], $senior)->assertOk()->assertJsonPath('data.status', 'APPROVED');
    expect((int) DB::table('claims')->where('id', $w['claim'])->value('current_reserve_minor'))->toBe(80000)
        ->and(DB::table('authority_checks')->where('subject_id', $r['id'])->where('outcome', 'ALLOWED')->value('reason'))->toBe('WITHIN_LIMIT');
});
