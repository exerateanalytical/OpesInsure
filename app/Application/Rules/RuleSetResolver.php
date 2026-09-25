<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Application\Rules\Models\RuleSet;
use Illuminate\Support\Collection;

/**
 * Effective-dated resolution (REQ-RUL-002): for a domain, every APPROVED rule set whose effective range covers the
 * reference date, at PLATFORM, LINE and PRODUCT_VERSION scope — the highest version per code. All layers apply
 * (platform floor, line default, product version specifics); the domain combiner decides the outcome.
 */
final class RuleSetResolver
{
    /** @return Collection<int, RuleSet> ordered PLATFORM → LINE → PRODUCT_VERSION, then code */
    public function resolve(string $domain, ?string $productId, ?string $lineCode, \DateTimeInterface $at, ?string $operation = null): Collection
    {
        $day = \Carbon\CarbonImmutable::instance($at)->toDateString();
        $line = $lineCode ? strtoupper($lineCode) : null;

        $sets = RuleSet::with('rules')->where('domain', $domain)->where('status', 'APPROVED')
            ->when($operation, fn ($q) => $q->where('operation', $operation))
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day))
            ->where(function ($q) use ($productId, $line) {
                $q->where('scope_type', 'PLATFORM');
                if ($line) {
                    $q->orWhere(fn ($x) => $x->where('scope_type', 'LINE')->where('line_code', $line));
                }
                if ($productId) {
                    $q->orWhere(fn ($x) => $x->where('scope_type', 'PRODUCT_VERSION')->where('insurance_product_id', $productId));
                }
            })
            ->orderByDesc('version')->get();

        $order = ['PLATFORM' => 0, 'LINE' => 1, 'PRODUCT_VERSION' => 2];

        return $sets->unique('code')->sortBy(fn (RuleSet $s) => sprintf('%d|%s', $order[$s->scope_type] ?? 9, $s->code))->values();
    }
}
