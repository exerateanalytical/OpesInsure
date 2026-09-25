<?php

declare(strict_types=1);

namespace App\Application\Policies\Endorsements;

use App\Models\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-END-001 — rules per endorsement type (endorsement_type_rules). A tenant row overrides the platform
 * default (tenant_id NULL). GENERAL keeps the legacy free-form behaviour (any path, manual premium delta).
 */
final class EndorsementTypeRules
{
    public const DEFAULT_TYPE = 'GENERAL';

    public function resolve(string $code, string $tenantId): object
    {
        $rule = DB::table('endorsement_type_rules')->where('code', $code)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')->first();
        if (! $rule) {
            throw ValidationException::withMessages(['endorsement_type' => "Unknown or inactive endorsement type {$code}."]);
        }
        $rule->change_paths = json_decode((string) $rule->change_paths, true) ?: [];
        $rule->required_documents = json_decode((string) $rule->required_documents, true) ?: [];

        return $rule;
    }

    /** Validates the requested changes and effective date against the rule. */
    public function validate(object $rule, Policy $policy, array $changes, CarbonImmutable $effectiveAt, CarbonImmutable $now): void
    {
        if ($rule->financial_effect !== 'MANUAL' && $rule->code !== self::DEFAULT_TYPE && $changes === []) {
            throw ValidationException::withMessages(['requested_changes' => 'An endorsement must change at least one term.']);
        }
        if (! in_array('*', $rule->change_paths, true)) {
            foreach (array_keys(Arr::dot($changes)) as $path) {
                $ok = false;
                foreach ($rule->change_paths as $prefix) {
                    if ($path === $prefix || str_starts_with($path, $prefix.'.')) {
                        $ok = true;
                        break;
                    }
                }
                if (! $ok) {
                    throw ValidationException::withMessages(['requested_changes' => "{$rule->code} cannot change {$path}."]);
                }
            }
        }
        $earliest = $now->startOfDay()->subDays((int) $rule->max_backdate_days);
        if ($effectiveAt->lessThan($earliest)) {
            throw ValidationException::withMessages(['effective_at' => "{$rule->code} cannot be backdated more than {$rule->max_backdate_days} days."]);
        }
    }
}
