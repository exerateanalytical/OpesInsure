<?php

declare(strict_types=1);

namespace App\Application\Cases;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\Models\CaseType;
use App\Domain\Shared\Clock\Clock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * REQ-CAS-001 case type governance (ICE §6.5): DRAFT → (maker-checker) EFFECTIVE,
 * previous EFFECTIVE version → SUPERSEDED. Open cases keep the version they were
 * opened with (INV-6.2). A definition that fails validation cannot be drafted or approved.
 */
final class CaseTypeService
{
    public function __construct(private readonly AuditWriter $audit, private readonly Clock $clock) {}

    /** @param array<string, mixed> $d code, name, states, transitions, sla_policies, auto_tasks, default_confidentiality, family_code, regulated, valid_from */
    public function draft(array $d, User $maker): CaseType
    {
        $this->validated($d);

        return DB::transaction(function () use ($d, $maker) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['case-type:'.$d['code']]);
            $previous = CaseType::where('code', $d['code'])->orderByDesc('version')->first();
            $type = CaseType::create([
                'code' => $d['code'], 'version' => ($previous?->version ?? 0) + 1, 'name' => $d['name'] ?? $previous?->name ?? $d['code'],
                'family_code' => $d['family_code'] ?? $previous?->family_code, 'status' => 'DRAFT',
                'valid_from' => $d['valid_from'] ?? $this->clock->today()->toDateString(), 'valid_to' => null,
                'states' => $d['states'], 'transitions' => $d['transitions'], 'sla_policies' => $d['sla_policies'] ?? [], 'auto_tasks' => $d['auto_tasks'] ?? [],
                'default_confidentiality' => $d['default_confidentiality'] ?? $previous?->default_confidentiality ?? 'NORMAL',
                'regulated' => (bool) ($d['regulated'] ?? $previous?->regulated ?? false), 'created_by' => $maker->id,
            ]);
            $this->audit->record('case_type.drafted', 'case_type', $type->id, ['code' => $type->code, 'version' => $type->version]);

            return $type;
        });
    }

    public function approve(CaseType $type, User $checker): CaseType
    {
        return DB::transaction(function () use ($type, $checker) {
            $type = CaseType::whereKey($type->id)->lockForUpdate()->firstOrFail();
            if ($type->status !== 'DRAFT') {
                throw CaseProblem::make('CASE_TYPE_NOT_DRAFT', 409, 'Only a DRAFT case type version can be approved.');
            }
            if ($type->created_by !== null && $type->created_by === $checker->id) {
                throw CaseProblem::make('MAKER_CHECKER_REQUIRED', 403, 'The maker of a case type version cannot approve it.');
            }
            $this->validated($type->only(['states', 'transitions', 'sla_policies', 'auto_tasks']));
            $today = $this->clock->today();
            foreach (CaseType::where('code', $type->code)->where('status', 'EFFECTIVE')->lockForUpdate()->get() as $old) {
                $old->update(['status' => 'SUPERSEDED', 'valid_to' => $today->toDateString()]);
            }
            $type->update(['status' => 'EFFECTIVE', 'approved_by' => $checker->id, 'approved_at' => $this->clock->now(), 'valid_from' => $today->toDateString()]); // effective on approval: no gap between versions
            $this->audit->record('case_type.approved', 'case_type', $type->id, ['code' => $type->code, 'version' => $type->version, 'regulated' => $type->regulated]);

            return $type->refresh();
        });
    }

    /** @param array<string, mixed> $d */
    private function validated(array $d): void
    {
        try {
            CaseTypeCatalogue::validate($d);
        } catch (InvalidArgumentException $e) {
            throw CaseProblem::make('CASE_TYPE_INVALID', 422, $e->getMessage());
        }
    }
}
