<?php

declare(strict_types=1);

use App\Application\Claims\Closure\ClaimAutoCloseSweep;
use App\Application\Claims\Closure\ClaimClosureChecklist;
use App\Application\Claims\Closure\ClaimClosureService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Policy;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function c14User(string $tenantId, string $role): User
{
    $u = User::create(['full_name' => 'C14 '.$role.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE']);

    return $u;
}

/** A PAID claim with an approved decision, zero reserve and nothing outstanding. */
function c14Claim(string $status = 'PAID'): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = Policy::create([
        'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDay(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now(),
    ]);
    $claim = Claim::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'claimant_party_id' => $f['party']->id, 'claim_number' => 'CLM-C14-'.Str::random(6), 'status' => $status, 'loss_occurred_at' => now(), 'loss_details' => [], 'currency' => 'XAF']);
    $maker = c14User($f['tenant']->id, 'CLAIMS_OFFICER');
    $checker = c14User($f['tenant']->id, 'CLAIMS_MANAGER');
    DB::table('claim_decisions')->insert(['id' => (string) Str::uuid(), 'claim_id' => $claim->id, 'decision' => 'APPROVE', 'approved_amount_minor' => 5000, 'currency' => 'XAF', 'reason_code' => 'COVERED', 'rationale' => 'ok', 'status' => 'APPROVED', 'proposed_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    app(TenantContext::class)->set($f['tenant']->id);

    return ['f' => $f, 'claim' => $claim, 'maker' => $maker, 'checker' => $checker, 'admin' => c14User($f['tenant']->id, 'SYSTEM_ADMIN')];
}

function failing(Claim $c, ?string $reason = null): array
{
    return collect(app(ClaimClosureChecklist::class)->evaluate($c->refresh(), $reason))->reject(fn ($i) => $i['passed'])->pluck('code')->values()->all();
}

it('passes the checklist for a clean settled claim and closes with a reason, recording an append-only closure', function () {
    $x = c14Claim();
    $c = app(ClaimClosureService::class)->close($x['claim'], 'SETTLED_PAID', 'Paid in full', $x['maker']);
    expect($c->status)->toBe('CLOSED')->and($c->closed_at)->not->toBeNull();
    $row = DB::table('claim_closures')->where('claim_id', $c->id)->first();
    expect($row->reason_code)->toBe('SETTLED_PAID')->and($row->from_status)->toBe('PAID')->and((bool) $row->automatic)->toBeFalse();
    expect(DB::table('outbox_messages')->where(['event_name' => 'claim.closed', 'aggregate_id' => $c->id])->exists())->toBeTrue();
});

it('blocks closure on each checklist item', function () {
    $x = c14Claim();
    $c = $x['claim'];
    expect(failing($c))->toBe([]);

    $c->update(['current_reserve_minor' => 100]);
    expect(failing($c))->toBe(['RESERVES_ZERO']);
    $c->update(['current_reserve_minor' => 0]);

    $pay = (string) Str::uuid();
    DB::table('claim_payments')->insert(['id' => $pay, 'claim_id' => $c->id, 'claim_decision_id' => DB::table('claim_decisions')->where('claim_id', $c->id)->value('id'), 'payee_party_id' => $x['f']['party']->id, 'amount_minor' => 10, 'currency' => 'XAF', 'status' => 'PENDING_APPROVAL', 'idempotency_key' => 'k1', 'requested_by' => $x['maker']->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(failing($c))->toBe(['NO_PENDING_PAYMENTS']);
    DB::table('claim_payments')->where('id', $pay)->update(['status' => 'PAID']);

    $rec = (string) Str::uuid();
    DB::table('claim_recoveries')->insert(['id' => $rec, 'claim_id' => $c->id, 'type' => 'SUBROGATION', 'status' => 'OPEN', 'counterparty_name' => 'TP', 'target_amount_minor' => 100, 'currency' => 'XAF', 'reference' => 'RCV-'.Str::random(8), 'opened_by' => $x['maker']->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(failing($c))->toBe(['RECOVERIES_RESOLVED']);
    app(ClaimClosureService::class)->transferRecovery($rec, 'Carrier recovery unit', $x['maker']);
    expect(DB::table('claim_recoveries')->where('id', $rec)->value('status'))->toBe('TRANSFERRED')->and(failing($c))->toBe([]);

    // Fixture tamper: C12's trigger makes an APPROVED decision immutable, so bypass user triggers for this one statement.
    DB::statement('SET session_replication_role = replica');
    DB::table('claim_decisions')->where('claim_id', $c->id)->update(['status' => 'PENDING_APPROVAL', 'approved_by' => null]);
    DB::statement('SET session_replication_role = origin');
    expect(failing($c))->toBe(['DECISION_RECORDED'])->and(failing($c, 'WITHDRAWN'))->toBe([]);

    expect(fn () => app(ClaimClosureService::class)->close($c, 'SETTLED_PAID', null, $x['maker']))->toThrow(ValidationException::class);
    expect($c->refresh()->status)->toBe('PAID')->and(DB::table('claim_closures')->where('claim_id', $c->id)->exists())->toBeFalse();
});

it('rejects unknown closure reasons and the auto reason on a manual close', function () {
    $x = c14Claim();
    expect(fn () => app(ClaimClosureService::class)->close($x['claim'], 'BORED', null, $x['maker']))->toThrow(ValidationException::class);
    expect(fn () => app(ClaimClosureService::class)->close($x['claim'], 'AUTO_INACTIVE_SETTLED', null, $x['maker']))->toThrow(ValidationException::class);
});

it('reopens with reason, maker-checker and authority, restoring the reserve as a new movement', function () {
    $x = c14Claim();
    $svc = app(ClaimClosureService::class);
    $oldMove = (string) Str::uuid();
    DB::table('claim_reserve_changes')->insert(['id' => $oldMove, 'claim_id' => $x['claim']->id, 'previous_amount_minor' => 5000, 'requested_amount_minor' => 0, 'currency' => 'XAF', 'status' => 'APPROVED', 'reason_code' => 'PAID', 'requested_by' => $x['maker']->id, 'approved_by' => $x['checker']->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $svc->close($x['claim'], 'SETTLED_PAID', null, $x['maker']);

    $req = $svc->requestReopen($x['claim'], 'LATE_INVOICE', 'Supplier invoice arrived', 2500, $x['maker']);
    expect($req->status)->toBe('PENDING_APPROVAL');
    expect(fn () => $svc->requestReopen($x['claim'], 'LATE_INVOICE', 'again', 0, $x['maker']))->toThrow(ValidationException::class);
    // Maker cannot approve their own request.
    expect(fn () => $svc->approveReopen($req->id, $x['maker']))->toThrow(ValidationException::class);
    // A claims manager without a RESERVE_APPROVE limit cannot restore a reserve.
    expect(fn () => $svc->approveReopen($req->id, $x['checker']))->toThrow(ValidationException::class);

    $c = $svc->approveReopen($req->id, $x['admin'], 'ok');
    expect($c->status)->toBe('REOPENED')->and((int) $c->current_reserve_minor)->toBe(2500)->and($c->closed_at)->toBeNull();
    $r = DB::table('claim_reopen_requests')->find($req->id);
    expect($r->status)->toBe('APPROVED')->and($r->decided_by)->toBe($x['admin']->id);
    $move = DB::table('claim_reserve_changes')->find($r->reserve_change_id);
    expect($move->reason_code)->toBe('REOPEN_RESTORE')->and((int) $move->previous_amount_minor)->toBe(0)->and((int) $move->requested_amount_minor)->toBe(2500)
        ->and($move->requested_by)->toBe($x['maker']->id)->and($move->approved_by)->toBe($x['admin']->id);
    // History untouched: the prior movement and closure record remain as they were.
    expect((int) DB::table('claim_reserve_changes')->where('id', $oldMove)->value('requested_amount_minor'))->toBe(0)
        ->and(DB::table('claim_closures')->where('claim_id', $c->id)->count())->toBe(1);
});

it('lets a claims manager reopen without a reserve and records rejection', function () {
    $x = c14Claim();
    $svc = app(ClaimClosureService::class);
    $svc->close($x['claim'], 'SETTLED_PAID', null, $x['maker']);
    $req = $svc->requestReopen($x['claim'], 'NEW_EVIDENCE', 'Photos', 0, $x['maker']);
    expect($svc->rejectReopen($req->id, $x['checker'], 'Not material')->status)->toBe('REJECTED');
    expect($x['claim']->refresh()->status)->toBe('CLOSED');
    $req2 = $svc->requestReopen($x['claim'], 'NEW_EVIDENCE', 'More photos', 0, $x['maker']);
    expect($svc->approveReopen($req2->id, $x['checker'])->status)->toBe('REOPENED');
    expect(DB::table('claim_reserve_changes')->where('claim_id', $x['claim']->id)->exists())->toBeFalse();
    expect(fn () => $svc->requestReopen($x['claim']->refresh(), 'NEW_EVIDENCE', 'x', 0, $x['maker']))->toThrow(ValidationException::class);
});

it('auto-closes only inactive settled claims whose checklist passes', function () {
    $x = c14Claim();
    $old = $x['claim'];
    Claim::whereKey($old->id)->update(['updated_at' => now()->subDays(40)]);
    $blocked = Claim::create(['tenant_id' => $old->tenant_id, 'policy_id' => $old->policy_id, 'claimant_party_id' => $old->claimant_party_id, 'claim_number' => 'CLM-C14-B'.Str::random(5), 'status' => 'PAID', 'loss_occurred_at' => now(), 'loss_details' => [], 'currency' => 'XAF', 'current_reserve_minor' => 10]);
    Claim::whereKey($blocked->id)->update(['updated_at' => now()->subDays(40)]);
    $fresh = Claim::create(['tenant_id' => $old->tenant_id, 'policy_id' => $old->policy_id, 'claimant_party_id' => $old->claimant_party_id, 'claim_number' => 'CLM-C14-F'.Str::random(5), 'status' => 'PAID', 'loss_occurred_at' => now(), 'loss_details' => [], 'currency' => 'XAF']);
    app(TenantContext::class)->clear();

    $s = app(ClaimAutoCloseSweep::class)->run(30);
    expect($s)->toMatchArray(['evaluated' => 2, 'closed' => 1, 'blocked' => 1]);
    expect($old->refresh()->status)->toBe('CLOSED')->and($blocked->refresh()->status)->toBe('PAID')->and($fresh->refresh()->status)->toBe('PAID');
    expect((bool) DB::table('claim_closures')->where('claim_id', $old->id)->value('automatic'))->toBeTrue();
    expect(app(ClaimAutoCloseSweep::class)->run(30)['closed'])->toBe(0);
});

it('registers the sweep command, its schedule and the API routes', function () {
    expect(Artisan::all())->toHaveKey('claims:auto-close');
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
    expect($events)->toContain('claims:auto-close');
    expect(Route::getRoutes()->getByAction(App\Application\Claims\Closure\Http\ClaimClosureController::class.'@close'))->not->toBeNull();
});

it('exposes the checklist as a ClaimTransitionGuard when the contract exists', function () {
    $x = c14Claim();
    $guard = app(App\Application\Claims\Closure\ClosureChecklistGuard::class);
    expect($guard->check($x['claim'], 'close', []))->toBeNull();
    $x['claim']->update(['current_reserve_minor' => 1]);
    expect($guard->events())->toBe(['close'])
        ->and($guard->check($x['claim'], 'close', ['to' => 'CLOSED']))->toBe('CLOSURE_RESERVES_ZERO')
        ->and($guard->check($x['claim'], 'close', []))->toBe('CLOSURE_RESERVES_ZERO');
    expect(collect(app()->tagged('claims.transition_guards'))->contains(fn ($g) => $g instanceof App\Application\Claims\Closure\ClosureChecklistGuard))->toBeTrue();
});
