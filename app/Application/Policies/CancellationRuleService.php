<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Audit\AuditWriter;
use App\Models\CancellationRuleVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancellationRuleService
{
    public function __construct(private AuditWriter $audit) {}

    public function create(array $data, User $actor): CancellationRuleVersion
    {
        return DB::transaction(function () use ($data, $actor): CancellationRuleVersion {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['cancellation-rule:'.$data['line_code']]);
            $version = (CancellationRuleVersion::where('line_code', $data['line_code'])->max('version') ?? 0) + 1;

            return CancellationRuleVersion::create([
                ...$data,
                'version' => $version,
                'status' => 'DRAFT',
                'created_by' => $actor->id,
            ]);
        });
    }

    public function approve(CancellationRuleVersion $rule, User $actor): CancellationRuleVersion
    {
        if ($rule->status !== 'DRAFT' || $rule->created_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
        }

        return DB::transaction(function () use ($rule, $actor): CancellationRuleVersion {
            $overlap = CancellationRuleVersion::query()
                ->where('line_code', $rule->line_code)
                ->where('status', 'APPROVED')
                ->where('id', '!=', $rule->id)
                ->whereDate('effective_from', '<=', $rule->effective_until ?? '9999-12-31')
                ->where(function ($query) use ($rule): void {
                    $query->whereNull('effective_until')
                        ->orWhereDate('effective_until', '>=', $rule->effective_from);
                })
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'effective_from' => __('wave5.cancellation_rule_overlap'),
                ]);
            }

            $rule->update([
                'status' => 'APPROVED',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $this->audit->record('cancellation.rule.approved', 'cancellation_rule', $rule->id, [
                'version' => $rule->version,
            ]);

            return $rule->refresh();
        });
    }
}
