<?php

declare(strict_types=1);

namespace App\Application\Approvals;

use App\Models\ApprovalMatrixRule;
use Illuminate\Validation\ValidationException;

/**
 * REQ-RBAC-006 — resolves the governing matrix rule for (action, amount, product, insurer, branch, tenant, date).
 * Most specific match wins (tenant > platform, then number of matched dimensions, then priority).
 * An action with no active rule falls back to the catalogue default: maker-checker, one approval.
 */
final class ApprovalMatrixResolver
{
    /** @param array{amount?: float|string|null, product_id?: string|null, insurer_tenant_id?: string|null, branch_code?: string|null, tenant_id?: string|null, at?: \DateTimeInterface|null} $ctx */
    public function resolve(string $action, array $ctx = []): ?ApprovalMatrixRule
    {
        if (! ApprovalActionCatalogue::has($action)) {
            throw ValidationException::withMessages(['action_code' => "Unknown approval action {$action}."]);
        }
        $date = ($ctx['at'] ?? now())->format('Y-m-d');
        $amount = isset($ctx['amount']) ? (float) $ctx['amount'] : null;
        $tenant = $ctx['tenant_id'] ?? null;

        $candidates = ApprovalMatrixRule::query()->where('action_code', $action)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenant, fn ($q) => $q->orWhere('tenant_id', $tenant)))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->get();

        $matches = $candidates->filter(function (ApprovalMatrixRule $r) use ($amount, $ctx): bool {
            if ($r->min_amount !== null && ($amount === null || $amount < (float) $r->min_amount)) {
                return false;
            }
            if ($r->max_amount !== null && ($amount === null || $amount > (float) $r->max_amount)) {
                return false;
            }
            foreach (['product_id', 'insurer_tenant_id', 'branch_code'] as $dim) {
                if ($r->{$dim} !== null && $r->{$dim} !== ($ctx[$dim] ?? null)) {
                    return false;
                }
            }

            return true;
        });

        return $matches->sortBy(fn (ApprovalMatrixRule $r) => [
            $r->tenant_id === null ? 1 : 0,
            -collect(['min_amount', 'max_amount', 'product_id', 'insurer_tenant_id', 'branch_code'])->filter(fn ($d) => $r->{$d} !== null)->count(),
            $r->priority,
        ])->first();
    }
}
