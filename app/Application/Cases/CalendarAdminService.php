<?php

declare(strict_types=1);

namespace App\Application\Cases;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\Models\CalendarBusinessHours;
use App\Application\Cases\Models\CalendarException;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-CAS-001 / REQ-CAL-001 — business-calendar administration (business hours, holidays / closures, breaks), shared
 * by the admin API (CaseAdminController / CaseConfigurationController, routes/cases.php) and the staff desktop.
 * Rows are never deleted: hours and breaks are end-dated so history stays explainable. Every write is audited.
 */
final class CalendarAdminService
{
    public function __construct(private readonly AuditWriter $audit) {}

    /** @param array{jurisdiction: string, branch_id?: ?string, weekday: int, opens: string, closes: string, valid_from: string, valid_to?: ?string} $d */
    public function addHours(array $d, User $actor): CalendarBusinessHours
    {
        $this->assertBranch($d['branch_id'] ?? null);
        $row = CalendarBusinessHours::create($d + ['created_by' => $actor->id]);
        $this->audit->record('calendar.business_hours.added', 'calendar_business_hours', $row->id, $d);

        return $row;
    }

    public function endHours(string $id, string $validTo): CalendarBusinessHours
    {
        $row = CalendarBusinessHours::findOrFail($id);
        if ($row->branch_id) {
            $this->assertBranch($row->branch_id);
        }
        $old = ['valid_to' => $row->valid_to?->toDateString()];
        $row->update(['valid_to' => $validTo]);
        $this->audit->recordChange('calendar.business_hours.ended', 'calendar_business_hours', $row->id, $old, ['valid_to' => $validTo], 'END_DATED');

        return $row->refresh();
    }

    /** @param array{jurisdiction: string, branch_id?: ?string, date: string, kind: string, label: string, source_reference?: ?string} $d */
    public function addException(array $d, User $actor): CalendarException
    {
        $this->assertBranch($d['branch_id'] ?? null);
        $row = CalendarException::create($d + ['created_by' => $actor->id]);
        $this->audit->record('calendar.exception.added', 'calendar_exception', $row->id, $d);

        return $row;
    }

    /** @param array{jurisdiction: string, branch_id?: ?string, weekday?: ?int, starts: string, ends: string, label?: ?string, valid_from: string, valid_to?: ?string} $d */
    public function addBreak(array $d, User $actor): object
    {
        $this->assertBranch($d['branch_id'] ?? null);
        $id = (string) Str::uuid();
        DB::table('calendar_breaks')->insert($d + ['id' => $id, 'label' => $d['label'] ?? 'Break', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('calendar.break.added', 'calendar_break', $id, $d);

        return DB::table('calendar_breaks')->find($id);
    }

    /** Breaks are end-dated, never deleted. */
    public function endBreak(string $id, string $validTo): object
    {
        $row = DB::table('calendar_breaks')->find($id) ?? abort(404);
        if ($row->branch_id && ! $this->branchExists($row->branch_id)) {
            abort(404);
        }
        DB::table('calendar_breaks')->where('id', $id)->update(['valid_to' => $validTo, 'updated_at' => now()]);
        $this->audit->recordChange('calendar.break.ended', 'calendar_break', $id, ['valid_to' => $row->valid_to], ['valid_to' => $validTo], 'END_DATED');

        return DB::table('calendar_breaks')->find($id);
    }

    private function assertBranch(?string $branchId): void
    {
        if ($branchId && ! $this->branchExists($branchId)) {
            throw CaseProblem::make('BRANCH_NOT_FOUND', 422, 'Branch not found in this tenant.');
        }
    }

    private function branchExists(string $branchId): bool
    {
        return DB::table('tenant_branches')->where('tenant_id', app(TenantContext::class)->id())->where('id', $branchId)->exists();
    }
}
