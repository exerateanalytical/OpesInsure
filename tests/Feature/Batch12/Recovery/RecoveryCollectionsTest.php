<?php

declare(strict_types=1);

// Agent C15 — REQ-REC-001 recoveries as receivables, REQ-REC-002 litigation, REQ-REC-003 collections.

use App\Application\Claims\Recovery\ClaimRecoveryService;
use App\Application\Claims\Recovery\Litigation\LegalMatterService;
use App\Application\Collections\CollectionService;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Ledger\Posting\DefaultChartOfAccounts;
use App\Models\Claim;
use App\Models\ClaimRecovery;
use App\Models\Party;
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

function c15Staff(Tenant $t, array $perms): User
{
    $u = User::create(['full_name' => 'Rec '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'CLAIMS', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'REC_'.Str::random(5), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

function c15Claim(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party']);
    app(\App\Domain\Tenancy\TenantContext::class)->set($f['tenant']->id);

    return $f + ['policy' => $policy, 'claim' => $claim];
}

function c15Receivable(string $tenantId, string $type, int $amount, string $dueAt): object
{
    return app(ObligationService::class)->create(['tenant_id' => $tenantId, 'kind' => 'RECEIVABLE', 'type' => $type, 'source_type' => 'test', 'source_id' => (string) Str::uuid(),
        'currency' => 'XAF', 'amount_minor' => $amount, 'due_at' => $dueAt]);
}

it('REQ-REC-001: a recovery is a RECEIVABLE obligation; receipts settle it and post claim.recovery.received', function () {
    $f = c15Claim();
    $actor = c15Staff($f['tenant'], ['claims.recovery']);
    $svc = app(ClaimRecoveryService::class);

    expect(DefaultChartOfAccounts::EVENTS)->toHaveKey('claim.recovery.received')
        ->and(DB::table('accounting_event_mappings')->where(['event_code' => 'claim.recovery.received', 'status' => 'ACTIVE'])->whereNull('tenant_id')->first())
        ->debit_account_code->toBe('521000')->credit_account_code->toBe('601000');

    $r = $svc->open($f['claim'], ['type' => 'SUBROGATION', 'counterparty_name' => 'Other Insurer SA', 'target_amount_minor' => 50000, 'due_at' => now()->addDays(10)], $actor);
    expect($r->status)->toBe('EXPECTED');
    $o = DB::table('financial_obligations')->where('id', $r->financial_obligation_id)->first();
    expect($o->kind)->toBe('RECEIVABLE')->and($o->type)->toBe('CLAIM')->and($o->source_type)->toBe('claim_recovery')->and((int) $o->outstanding_minor)->toBe(50000);

    $r = $svc->receive($r, 20000, 'BANK-1', $actor);
    expect($r->status)->toBe('OUTSTANDING')->and((int) $r->recovered_amount_minor)->toBe(20000)
        ->and(app(ObligationService::class)->outstanding($o->id))->toBe(30000);
    // idempotent per reference
    $svc->receive($r, 20000, 'BANK-1', $actor);
    expect(DB::table('claim_recovery_receipts')->where('claim_recovery_id', $r->id)->count())->toBe(1);

    expect(fn () => $svc->receive($r, 40000, 'BANK-2', $actor))->toThrow(ValidationException::class);
    $r = $svc->receive($r, 30000, 'BANK-2', $actor);
    expect($r->status)->toBe('RECEIVED')
        ->and(DB::table('financial_obligations')->where('id', $o->id)->value('status'))->toBe('SETTLED')
        ->and(DB::table('journals')->where('reference_type', 'claim.recovery.received')->count())->toBe(2)
        ->and(DB::table('outbox_messages')->where('event_name', 'claim.recovery.received')->count())->toBe(2);

    $r = $svc->close($r, 'Fully recovered', $actor);
    expect($r->status)->toBe('CLOSED');
});

it('REQ-REC-001: all recovery types, disputes suspend receipts, abandoning cancels the obligation (HTTP)', function () {
    $f = c15Claim();
    $actor = c15Staff($f['tenant'], ['claims.recovery', 'claims.view']);
    Passport::actingAs($actor);
    $h = tenantHeaderFor($f['tenant']);
    foreach (['SUBROGATION', 'SALVAGE', 'CONTRIBUTION', 'DEDUCTIBLE_RECOVERY'] as $type) {
        $this->postJson('/api/v1/claim-recoveries', ['claim_id' => $f['claim']->id, 'type' => $type, 'counterparty_name' => 'X', 'target_amount_minor' => 1000], $h)->assertCreated()->assertJsonPath('data.status', 'EXPECTED');
    }
    $id = ClaimRecovery::where('type', 'SALVAGE')->value('id');
    $this->postJson("/api/v1/claim-recoveries/{$id}/dispute", ['reason' => 'Salvage value contested'], $h)->assertOk()->assertJsonPath('data.status', 'DISPUTED');
    $this->postJson("/api/v1/claim-recoveries/{$id}/receive", ['amount_minor' => 100, 'reference' => 'R1'], $h)->assertStatus(422);
    $this->postJson("/api/v1/claim-recoveries/{$id}/resolve-dispute", ['resolution' => 'Agreed'], $h)->assertOk()->assertJsonPath('data.status', 'EXPECTED');
    $this->postJson("/api/v1/claim-recoveries/{$id}/close", ['reason' => 'Uneconomic'], $h)->assertOk()->assertJsonPath('data.status', 'CLOSED');
    expect(DB::table('financial_obligations')->where(['source_type' => 'claim_recovery', 'source_id' => $id])->value('status'))->toBe('CANCELLED');
    $this->getJson('/api/v1/claim-recoveries?claim_id='.$f['claim']->id, $h)->assertOk()->assertJsonCount(4, 'data');
    // Tenant isolation
    $other = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $stranger = c15Staff($other['tenant'], ['claims.recovery', 'claims.view']);
    Passport::actingAs($stranger);
    $this->getJson("/api/v1/claim-recoveries/{$id}", tenantHeaderFor($other['tenant']))->assertNotFound();
});

it('REQ-REC-002: litigation is a LITIGATION case with court, lawyer, hearings, deadlines, costs and outcome', function () {
    $f = c15Claim();
    $actor = c15Staff($f['tenant'], ['legal.matters.manage', 'legal.matters.view']);
    Passport::actingAs($actor);
    $h = tenantHeaderFor($f['tenant']);
    $lawyer = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Cabinet Avocat', 'status' => 'ACTIVE']);

    $m = $this->postJson('/api/v1/legal-matters', ['role' => 'PLAINTIFF', 'court' => 'Tribunal de Première Instance Douala', 'court_reference' => 'TPI/2026/77',
        'lawyer_party_id' => $lawyer->id, 'claim_id' => $f['claim']->id, 'claimed_amount_minor' => 900000], $h)->assertCreated()->json('data');
    $case = DB::table('cases')->where('id', $m['case_id'])->first();
    expect($case->case_type_code)->toBe('LITIGATION')->and($case->subject_id)->toBe($f['claim']->id);

    $hearing = $this->postJson("/api/v1/legal-matters/{$m['id']}/hearings", ['scheduled_at' => now()->addDays(20)->toIso8601String(), 'location' => 'Salle 2'], $h)->assertCreated()->json('data');
    $this->postJson("/api/v1/legal-hearings/{$hearing['id']}/record", ['status' => 'ADJOURNED', 'result' => 'Postponed'], $h)->assertOk()->assertJsonPath('data.status', 'ADJOURNED');
    $dl = $this->postJson("/api/v1/legal-matters/{$m['id']}/deadlines", ['description' => 'File conclusions', 'due_at' => now()->addDays(5)->toIso8601String()], $h)->assertCreated()->json('data');
    $this->postJson("/api/v1/legal-deadlines/{$dl['id']}/complete", [], $h)->assertOk()->assertJsonPath('data.status', 'MET');
    $late = app(LegalMatterService::class)->addDeadline($f['tenant']->id, $m['id'], 'Appeal window', now()->subDay(), $actor);
    expect(app(LegalMatterService::class)->sweepDeadlines())->toBe(1)
        ->and(DB::table('legal_deadlines')->where('id', $late->id)->value('status'))->toBe('MISSED');

    $cost = $this->postJson("/api/v1/legal-matters/{$m['id']}/costs", ['cost_type' => 'LAWYER_FEE', 'amount_minor' => 150000, 'payee_party_id' => $lawyer->id], $h)->assertCreated()->json('data');
    $po = DB::table('financial_obligations')->where('id', $cost['financial_obligation_id'])->first();
    expect($po->kind)->toBe('PAYABLE')->and($po->creditor_id)->toBe($lawyer->id);

    $this->postJson("/api/v1/legal-matters/{$m['id']}/outcome", ['outcome' => 'WON', 'amount_minor' => 700000], $h)->assertOk()
        ->assertJsonPath('data.status', 'CONCLUDED')->assertJsonPath('data.total_costs_minor', 150000);
    expect(DB::table('cases')->where('id', $m['case_id'])->value('status'))->toBe('RESOLVED');
    $this->postJson("/api/v1/legal-matters/{$m['id']}/hearings", ['scheduled_at' => now()->addDays(30)->toIso8601String()], $h)->assertStatus(422);
});

it('REQ-REC-003: dunning stages issue one notice per stage and escalate to a RECOVERY case', function () {
    $f = c15Claim();
    $t = $f['tenant']->id;
    $premium = c15Receivable($t, 'PREMIUM', 100000, now()->subDays(3)->toDateString());
    $clawback = c15Receivable($t, 'COMMISSION', 20000, now()->subDays(70)->toDateString());
    $notDue = c15Receivable($t, 'PREMIUM', 5000, now()->addDays(3)->toDateString());
    $svc = app(CollectionService::class);

    $s = $svc->run($t);
    expect($s['notices'])->toBe(2)->and($s['escalated'])->toBe(1);
    expect(DB::table('collection_accounts')->where('financial_obligation_id', $premium->id)->value('stage'))->toBe('REMINDER_1');
    $acc = DB::table('collection_accounts')->where('financial_obligation_id', $clawback->id)->first();
    expect($acc->stage)->toBe('ESCALATED')->and(DB::table('cases')->where('id', $acc->case_id)->value('case_type_code'))->toBe('RECOVERY')
        ->and(DB::table('collection_accounts')->where('financial_obligation_id', $notDue->id)->exists())->toBeFalse();

    // Re-running on the same day is idempotent.
    expect($svc->run($t)['notices'])->toBe(0);
    // Later: the premium reaches REMINDER_2.
    expect($svc->run($t, now()->addDays(13))['notices'])->toBeGreaterThanOrEqual(1);
    expect(DB::table('collection_accounts')->where('financial_obligation_id', $premium->id)->value('stage'))->toBe('REMINDER_2');

    // Settled obligations close their account.
    app(ObligationService::class)->settle($premium->id, 100000, 'PAY-1');
    expect($svc->run($t)['closed'])->toBe(1)
        ->and(DB::table('collection_accounts')->where('financial_obligation_id', $premium->id)->value('status'))->toBe('SETTLED');
});

it('REQ-REC-003: a promise-to-pay suspends dunning and is evaluated as kept or broken', function () {
    $f = c15Claim();
    $t = $f['tenant']->id;
    $actor = c15Staff($f['tenant'], ['collections.manage']);
    $svc = app(CollectionService::class);
    $o = c15Receivable($t, 'INSTALMENT', 60000, now()->subDays(20)->toDateString());

    $svc->promise($t, $o->id, 30000, now()->addDays(5)->toDateString(), 'Will pay half', $actor);
    expect($svc->run($t)['notices'])->toBe(0);

    app(ObligationService::class)->settle($o->id, 30000, 'PAY-P');
    $s = $svc->run($t, now()->addDays(6));
    expect($s['promises_kept'])->toBe(1)->and($s['notices'])->toBe(1);

    $svc->promise($t, $o->id, 30000, now()->addDays(10)->toDateString(), null, $actor);
    $s = $svc->run($t, now()->addDays(11));
    expect($s['promises_broken'])->toBe(1)
        ->and(DB::table('outbox_messages')->where('event_name', 'collections.promise.broken')->count())->toBe(1);
});

it('REQ-REC-003: write-off is maker-checker via ObligationService::writeOff and closes a claim recovery', function () {
    $f = c15Claim();
    $maker = c15Staff($f['tenant'], ['collections.manage', 'claims.recovery']);
    $checker = c15Staff($f['tenant'], ['collections.write_off.approve']);
    $r = app(ClaimRecoveryService::class)->open($f['claim'], ['type' => 'SALVAGE', 'counterparty_name' => 'Scrap yard', 'target_amount_minor' => 8000, 'due_at' => now()->subDays(100)], $maker);
    $h = tenantHeaderFor($f['tenant']);

    Passport::actingAs($maker);
    $req = $this->postJson("/api/v1/collections/{$r->financial_obligation_id}/write-off-requests", ['reason' => 'Debtor insolvent'], $h)->assertCreated()->json('data');
    $this->postJson("/api/v1/collections/{$r->financial_obligation_id}/write-off-requests", ['reason' => 'again'], $h)->assertStatus(422);
    $this->postJson("/api/v1/collections/write-off-requests/{$req['id']}/approve", [], $h)->assertForbidden();

    // Maker holding the checker permission still cannot approve their own request.
    $both = c15Staff($f['tenant'], ['collections.manage', 'collections.write_off.approve']);
    Passport::actingAs($both);
    $o2 = c15Receivable($f['tenant']->id, 'PREMIUM', 1000, now()->subDays(2)->toDateString());
    $req2 = $this->postJson("/api/v1/collections/{$o2->id}/write-off-requests", ['reason' => 'small'], $h)->assertCreated()->json('data');
    $this->postJson("/api/v1/collections/write-off-requests/{$req2['id']}/approve", [], $h)->assertStatus(422);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/collections/write-off-requests/{$req['id']}/approve", ['note' => 'OK'], $h)->assertOk()->assertJsonPath('data.status', 'APPROVED');
    expect(DB::table('financial_obligations')->where('id', $r->financial_obligation_id)->value('status'))->toBe('WRITTEN_OFF')
        ->and($r->refresh()->status)->toBe('CLOSED');
});

it('REQ-REC-003: disputed recoveries are not dunned; overdue recoveries become OUTSTANDING', function () {
    $f = c15Claim();
    $actor = c15Staff($f['tenant'], ['claims.recovery']);
    $svc = app(ClaimRecoveryService::class);
    $a = $svc->open($f['claim'], ['type' => 'SUBROGATION', 'counterparty_name' => 'A', 'target_amount_minor' => 1000, 'due_at' => now()->subDays(5)], $actor);
    $b = $svc->open($f['claim'], ['type' => 'CONTRIBUTION', 'counterparty_name' => 'B', 'target_amount_minor' => 1000, 'due_at' => now()->subDays(5)], $actor);
    $svc->dispute($b, 'Liability denied', $actor);

    $s = app(CollectionService::class)->run($f['tenant']->id);
    expect($s['recoveries_overdue'])->toBe(1)->and($s['notices'])->toBe(1)
        ->and($a->refresh()->status)->toBe('OUTSTANDING')
        ->and(DB::table('collection_accounts')->where('financial_obligation_id', $b->financial_obligation_id)->exists())->toBeFalse();
});
