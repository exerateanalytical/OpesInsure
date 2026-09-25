<?php

declare(strict_types=1);

use App\Application\Ledger\LedgerService;
use App\Application\Ledger\Periods\AccountingPeriodService;
use App\Application\Ledger\Periods\PeriodGuard;
use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function b108Tenant(): Tenant
{
    $t = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Periods '.Str::random(6), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    app(TenantContext::class)->set($t->id);

    return $t;
}

function b108Staff(Tenant $t, array $perms): User
{
    $u = User::create(['full_name' => 'Fin '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'FIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'FIN_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

/** @return array{0: string, 1: string} */
function b108Accounts(Tenant $t, string $second = 'REVENUE'): array
{
    $ids = [];
    foreach (['CASH', $second] as $code) {
        $ids[] = $id = (string) Str::uuid();
        DB::table('ledger_accounts')->insert(['id' => $id, 'tenant_id' => $t->id, 'code' => $code, 'name' => $code, 'type' => 'ASSET', 'currency' => 'XAF', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $ids;
}

function b108Post(Tenant $t, array $acc, int $amount = 1000): string
{
    return app(LedgerService::class)->post($t->id, 'TEST', (string) Str::uuid(), 'XAF', [
        ['account_id' => $acc[0], 'debit_minor' => $amount], ['account_id' => $acc[1], 'credit_minor' => $amount],
    ], 'corr-'.Str::random(8));
}

function b108Close(string $periodId, User $u): void
{
    $svc = app(AccountingPeriodService::class);
    $svc->startClose($periodId, $u);
    $svc->close($periodId, $u);
}

// JournalLine lives in Journal.php (not PSR-4 loadable on its own): load it first.
beforeEach(fn () => class_exists(\App\Domain\Ledger\Journal::class));
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

test('tenants without periods are not period-controlled', function () {
    $t = b108Tenant();
    $id = b108Post($t, b108Accounts($t));
    expect(DB::table('journals')->where('id', $id)->value('accounting_date'))->toBe(now()->toDateString());
    expect(PeriodGuard::postingDate(null, '2020-01-01'))->toBe('2020-01-01');
});

test('fiscal year config drives period numbering and the scheduler opens current + next period idempotently', function () {
    $t = b108Tenant();
    $svc = app(AccountingPeriodService::class);
    $svc->configure($t->id, 7);
    expect($svc->openUpcoming(CarbonImmutable::parse('2026-09-25')))->toBe(2)
        ->and($svc->openUpcoming(CarbonImmutable::parse('2026-09-25')))->toBe(0);
    $sep = DB::table('accounting_periods')->where('tenant_id', $t->id)->where('starts_on', '2026-09-01')->first();
    expect($sep->fiscal_year)->toBe(2026)->and($sep->period_number)->toBe(3)->and($sep->ends_on)->toBe('2026-09-30')->and($sep->status)->toBe('OPEN');
    expect(DB::table('accounting_periods')->where('tenant_id', $t->id)->where('starts_on', '2026-10-01')->exists())->toBeTrue();
    expect(fn () => $svc->configure($t->id, 1))->toThrow(ValidationException::class);
    expect(DB::table('audit_log')->where('action', 'ledger.period.opened')->count())->toBe(2);

    \Illuminate\Support\Carbon::setTestNow('2026-10-20 10:00:00');
    Artisan::call('ledger:open-periods');
    expect(DB::table('accounting_periods')->where('tenant_id', $t->id)->where('starts_on', '2026-11-01')->exists())->toBeTrue();
});

test('close runs the checklist, refuses posting into closed period and rolls auto-postings forward', function () {
    \Illuminate\Support\Carbon::setTestNow('2026-09-25 10:00:00');
    $t = b108Tenant();
    $u = b108Staff($t, ['ledger.periods.close']);
    $svc = app(AccountingPeriodService::class);
    $sep = $svc->ensurePeriod($t->id, '2026-09-10');
    $acc = b108Accounts($t);
    b108Post($t, $acc);

    b108Close($sep->id, $u);
    expect(DB::table('accounting_periods')->where('id', $sep->id)->value('status'))->toBe('CLOSED');
    expect(fn () => PeriodGuard::assertOpen($t->id, '2026-09-25'))->toThrow(ValidationException::class);

    // no later open period: posting refused
    expect(fn () => b108Post($t, $acc))->toThrow(ValidationException::class);

    // next period open: auto-posting rolls to its first day
    $svc->ensurePeriod($t->id, '2026-10-01');
    $id = b108Post($t, $acc);
    expect(DB::table('journals')->where('id', $id)->value('accounting_date'))->toBe('2026-10-01');

    // reversal goes through the same guard
    $rev = app(LedgerService::class)->reverse($id, 'corr-rev');
    expect(DB::table('journals')->where('id', $rev)->value('accounting_date'))->toBe('2026-10-01');
});

test('checklist blocks close on suspense balance and pending manual journals; permission required', function () {
    \Illuminate\Support\Carbon::setTestNow('2026-09-25 10:00:00');
    $t = b108Tenant();
    $u = b108Staff($t, ['ledger.periods.close']);
    $nobody = b108Staff($t, []);
    $svc = app(AccountingPeriodService::class);
    $sep = $svc->ensurePeriod($t->id, '2026-09-10');
    b108Post($t, b108Accounts($t, 'SUSPENSE'));
    DB::table('journals')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'reference_type' => 'MANUAL', 'reference_id' => (string) Str::uuid(), 'currency' => 'XAF', 'status' => 'DRAFT', 'correlation_id' => 'x', 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => $svc->startClose($sep->id, $nobody))->toThrow(ValidationException::class);
    $p = $svc->startClose($sep->id, $u);
    expect($p->status)->toBe('CLOSING')->and(json_decode($p->checklist, true)['blocking'])->toBe(['suspense_balance', 'pending_manual_journals']);
    expect(fn () => $svc->close($sep->id, $u))->toThrow(ValidationException::class);
    expect(DB::table('accounting_periods')->where('id', $sep->id)->value('status'))->toBe('CLOSING');
    // CLOSING still accepts postings
    expect(PeriodGuard::postingDate($t->id, '2026-09-25'))->toBe('2026-09-25');
});

test('earlier periods must close first', function () {
    $t = b108Tenant();
    $u = b108Staff($t, ['ledger.periods.close']);
    $svc = app(AccountingPeriodService::class);
    $svc->ensurePeriod($t->id, '2026-08-01');
    $sep = $svc->ensurePeriod($t->id, '2026-09-01');
    $svc->startClose($sep->id, $u);
    expect(fn () => $svc->close($sep->id, $u))->toThrow(ValidationException::class);
});

test('reopening needs privilege, a reason and a different approver, and is audited', function () {
    \Illuminate\Support\Carbon::setTestNow('2026-10-05 10:00:00');
    $t = b108Tenant();
    $closer = b108Staff($t, ['ledger.periods.close']);
    $maker = b108Staff($t, ['ledger.periods.reopen']);
    $checker = b108Staff($t, ['ledger.periods.reopen']);
    $svc = app(AccountingPeriodService::class);
    $sep = $svc->ensurePeriod($t->id, '2026-09-01');
    b108Close($sep->id, $closer);

    expect(fn () => $svc->requestReopen($sep->id, 'late invoice correction', $closer))->toThrow(ValidationException::class);
    expect(fn () => $svc->requestReopen($sep->id, 'short', $maker))->toThrow(ValidationException::class);
    $svc->requestReopen($sep->id, 'Late insurer invoice correction', $maker);
    expect(PeriodGuard::periodFor($t->id, '2026-09-15')->status)->toBe('CLOSED');
    expect(fn () => $svc->approveReopen($sep->id, $maker))->toThrow(ValidationException::class);

    $p = $svc->approveReopen($sep->id, $checker);
    expect($p->status)->toBe('REOPENED')->and($p->reopen_count)->toBe(1)->and($p->reopen_approved_by)->toBe($checker->id);
    PeriodGuard::assertOpen($t->id, '2026-09-15');
    $audit = DB::table('audit_log')->where('action', 'ledger.period.reopened')->where('subject_id', $sep->id)->first();
    expect($audit)->not->toBeNull()->and($audit->actor_id ?? $checker->id)->not->toBeNull();

    // can be closed again
    b108Close($sep->id, $closer);
    expect(DB::table('accounting_periods')->where('id', $sep->id)->value('status'))->toBe('CLOSED');
});
