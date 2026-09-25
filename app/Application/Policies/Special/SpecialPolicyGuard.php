<?php

declare(strict_types=1);

namespace App\Application\Policies\Special;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** REQ-PRD-011 — shared read-only lookups on policies / profiles (never writes policy rows). */
final class SpecialPolicyGuard
{
    public const CLOSED_POLICY_STATUSES = ['CANCELLED', 'EXPIRED', 'LAPSED', 'TERMINATED', 'DRAFT'];

    public function policy(string $tenantId, string $policyId): object
    {
        $p = DB::table('policies')->where('id', $policyId)->where('tenant_id', $tenantId)->first();
        if (! $p) {
            abort(404, 'Policy not found.');
        }

        return $p;
    }

    public function openPolicy(string $tenantId, string $policyId): object
    {
        $p = $this->policy($tenantId, $policyId);
        if (in_array(strtoupper((string) $p->status), self::CLOSED_POLICY_STATUSES, true)) {
            throw ValidationException::withMessages(['policy' => "Policy status {$p->status} does not accept schedule changes."]);
        }

        return $p;
    }

    public function profile(string $tenantId, string $policyId, ?string $kind = null): object
    {
        $pr = DB::table('special_policy_profiles')->where('tenant_id', $tenantId)->where('policy_id', $policyId)->first();
        if (! $pr) {
            abort(404, 'Policy has no special product profile.');
        }
        if ($kind !== null && $pr->kind !== $kind) {
            throw ValidationException::withMessages(['policy' => "Policy profile is {$pr->kind}, expected {$kind}."]);
        }
        if ($pr->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['policy' => 'Special product profile is closed.']);
        }

        return $pr;
    }

    /** Pro-rata of an annual amount for the days from $from to cover end, over the policy term (integer minor units). */
    public static function proRata(int $annualMinor, object $policy, string $from): int
    {
        $start = \Carbon\CarbonImmutable::parse($policy->coverage_starts_at)->startOfDay();
        $end = \Carbon\CarbonImmutable::parse($policy->coverage_ends_at)->startOfDay();
        $f = \Carbon\CarbonImmutable::parse($from)->startOfDay();
        $term = max(1, (int) $start->diffInDays($end));
        $remaining = max(0, (int) $f->diffInDays($end, false));

        return intdiv($annualMinor * min($remaining, $term), $term);
    }

    public static function assertWithinCover(object $policy, string $date, string $field): void
    {
        $d = \Carbon\CarbonImmutable::parse($date)->toDateString();
        $s = \Carbon\CarbonImmutable::parse($policy->coverage_starts_at)->toDateString();
        $e = \Carbon\CarbonImmutable::parse($policy->coverage_ends_at)->toDateString();
        if ($d < $s || $d > $e) {
            throw ValidationException::withMessages([$field => "Date must fall within the policy cover period ({$s} – {$e})."]);
        }
    }
}
