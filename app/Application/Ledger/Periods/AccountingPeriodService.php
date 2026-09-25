<?php

declare(strict_types=1);

namespace App\Application\Ledger\Periods;

use App\Application\Audit\AuditWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-ACC-003 / ESR FIN-023: OPEN → CLOSING (checklist) → CLOSED → REOPENED (privileged, reason, maker-checker) → CLOSING …
 */
final class AccountingPeriodService
{
    public const PERM_CLOSE = 'ledger.periods.close';

    public const PERM_REOPEN = 'ledger.periods.reopen';

    public function __construct(private readonly AuditWriter $audit, private readonly PreCloseChecklist $checklist) {}

    public function configure(string $tenantId, int $fiscalYearStartMonth): void
    {
        if ($fiscalYearStartMonth < 1 || $fiscalYearStartMonth > 12) {
            throw ValidationException::withMessages(['fiscal_year_start_month' => 'Must be 1-12.']);
        }
        if (DB::table('accounting_periods')->where('tenant_id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['fiscal_year_start_month' => 'Fiscal year cannot change once periods exist.']);
        }
        DB::table('accounting_period_configs')->updateOrInsert(['tenant_id' => $tenantId], ['id' => (string) Str::uuid(), 'fiscal_year_start_month' => $fiscalYearStartMonth, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Idempotently ensure the monthly period containing $date exists (OPEN). */
    public function ensurePeriod(string $tenantId, \DateTimeInterface|string $date): object
    {
        $start = CarbonImmutable::parse($date)->startOfMonth();
        $existing = DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('starts_on', $start->toDateString())->first();
        if ($existing) {
            return $existing;
        }
        $fyStart = (int) (DB::table('accounting_period_configs')->where('tenant_id', $tenantId)->value('fiscal_year_start_month') ?? 1);
        $number = (($start->month - $fyStart + 12) % 12) + 1;
        $fiscalYear = $start->month >= $fyStart ? $start->year : $start->year - 1;
        DB::table('accounting_periods')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'fiscal_year' => $fiscalYear, 'period_number' => $number,
            'starts_on' => $start->toDateString(), 'ends_on' => $start->endOfMonth()->toDateString(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('starts_on', $start->toDateString())->first();
    }

    /** Scheduled: make sure every period-controlled tenant has its current and next month open. */
    public function openUpcoming(?\DateTimeInterface $today = null): int
    {
        $today = CarbonImmutable::parse($today ?? now());
        $created = 0;
        $tenants = DB::table('accounting_periods')->distinct()->pluck('tenant_id')->merge(DB::table('accounting_period_configs')->pluck('tenant_id'))->unique();
        foreach ($tenants as $tenantId) {
            foreach ([$today, $today->startOfMonth()->addMonthNoOverflow()] as $d) {
                $before = DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('starts_on', $d->startOfMonth()->toDateString())->exists();
                $p = $this->ensurePeriod((string) $tenantId, $d);
                if (! $before) {
                    $created++;
                    $this->audit->record('ledger.period.opened', 'accounting_period', $p->id, ['tenant_id' => $tenantId, 'starts_on' => $p->starts_on], null, ['source' => 'scheduler']);
                }
            }
        }

        return $created;
    }

    public function startClose(string $periodId, User $actor): object
    {
        $this->authorize($actor, self::PERM_CLOSE);

        return $this->transition($periodId, ['OPEN', 'REOPENED'], function ($p) use ($actor) {
            $check = $this->checklist->run($p);
            $this->update($p, ['status' => 'CLOSING', 'checklist' => json_encode($check), 'closing_started_by' => $actor->id, 'closing_started_at' => now()]);
            $this->audit->record('ledger.period.closing', 'accounting_period', $p->id, ['checklist' => $check]);
        });
    }

    /** @return array{items: array, blocking: list<string>} */
    public function checklist(string $periodId): array
    {
        return $this->checklist->run(DB::table('accounting_periods')->where('id', $periodId)->firstOrFail());
    }

    public function close(string $periodId, User $actor): object
    {
        $this->authorize($actor, self::PERM_CLOSE);

        return $this->transition($periodId, ['CLOSING'], function ($p) use ($actor) {
            $earlier = DB::table('accounting_periods')->where('tenant_id', $p->tenant_id)->where('starts_on', '<', $p->starts_on)->where('status', '<>', 'CLOSED')->exists();
            if ($earlier) {
                throw ValidationException::withMessages(['period' => 'Earlier periods must be closed first.']);
            }
            $check = $this->checklist->run($p);
            if ($check['blocking'] !== []) {
                throw ValidationException::withMessages(['checklist' => 'Pre-close checklist not clear: '.implode(', ', $check['blocking'])]);
            }
            $this->update($p, ['status' => 'CLOSED', 'checklist' => json_encode($check), 'closed_by' => $actor->id, 'closed_at' => now(), 'reopen_status' => null]);
            $this->audit->record('ledger.period.closed', 'accounting_period', $p->id, ['checklist' => $check]);
        });
    }

    /** Maker: privileged request with a mandatory reason. */
    public function requestReopen(string $periodId, string $reason, User $maker): object
    {
        $this->authorize($maker, self::PERM_REOPEN);
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'A reopening reason of at least 10 characters is required.']);
        }

        return $this->transition($periodId, ['CLOSED'], function ($p) use ($reason, $maker) {
            if ($p->reopen_status === 'PENDING') {
                throw ValidationException::withMessages(['period' => 'A reopening request is already pending.']);
            }
            $this->update($p, ['reopen_status' => 'PENDING', 'reopen_reason' => trim($reason), 'reopen_requested_by' => $maker->id, 'reopen_requested_at' => now(), 'reopen_approved_by' => null]);
            $this->audit->record('ledger.period.reopen_requested', 'accounting_period', $p->id, [], trim($reason));
        });
    }

    /** Checker: a different privileged user approves; the period becomes REOPENED. */
    public function approveReopen(string $periodId, User $checker): object
    {
        $this->authorize($checker, self::PERM_REOPEN);

        return $this->transition($periodId, ['CLOSED'], function ($p) use ($checker) {
            if ($p->reopen_status !== 'PENDING') {
                throw ValidationException::withMessages(['period' => 'No pending reopening request.']);
            }
            if ($p->reopen_requested_by === $checker->id) {
                throw ValidationException::withMessages(['period' => 'Maker-checker: the requester cannot approve the reopening.']);
            }
            $this->update($p, ['status' => 'REOPENED', 'reopen_status' => 'APPROVED', 'reopen_approved_by' => $checker->id, 'reopened_at' => now(), 'reopen_count' => $p->reopen_count + 1]);
            $this->audit->record('ledger.period.reopened', 'accounting_period', $p->id, ['requested_by' => $p->reopen_requested_by], $p->reopen_reason, ['approval_id' => $checker->id]);
        });
    }

    public function rejectReopen(string $periodId, User $checker, string $reason): object
    {
        $this->authorize($checker, self::PERM_REOPEN);

        return $this->transition($periodId, ['CLOSED'], function ($p) use ($checker, $reason) {
            if ($p->reopen_status !== 'PENDING' || $p->reopen_requested_by === $checker->id) {
                throw ValidationException::withMessages(['period' => 'No pending request this user may decide.']);
            }
            $this->update($p, ['reopen_status' => 'REJECTED']);
            $this->audit->record('ledger.period.reopen_rejected', 'accounting_period', $p->id, [], $reason);
        });
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw ValidationException::withMessages(['permission' => "Missing permission {$permission}."]);
        }
    }

    private function transition(string $periodId, array $from, callable $apply): object
    {
        return DB::transaction(function () use ($periodId, $from, $apply) {
            $p = DB::table('accounting_periods')->where('id', $periodId)->lockForUpdate()->first();
            if (! $p || ! in_array($p->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'Invalid period transition from '.($p->status ?? 'missing').'.']);
            }
            $apply($p);

            return DB::table('accounting_periods')->where('id', $periodId)->first();
        });
    }

    private function update(object $p, array $values): void
    {
        DB::table('accounting_periods')->where('id', $p->id)->update($values + ['updated_at' => now()]);
    }
}
