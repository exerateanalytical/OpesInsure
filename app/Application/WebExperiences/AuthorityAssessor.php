<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Application\Approvals\ApprovalMatrixResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * SSR §29 authority widget. Resolves the governing approval-matrix rule
 * (REQ-RBAC-006 — no second authority table) for (action, amount) and tells
 * the viewer whether the amount is within THEIR authority and whether a
 * referral is required. Rule internals (checker permission/roles, approvals,
 * thresholds) are revealed only to holders of approvals.matrix.view.
 */
final class AuthorityAssessor
{
    public function __construct(private readonly ApprovalMatrixResolver $resolver, private readonly TenantContext $context) {}

    public function assess(User $user, string $action, ?int $amountMinor, ?string $currency = null): array
    {
        $amount = $amountMinor === null ? null : $amountMinor / 100;
        $rule = rescue(fn () => $this->resolver->resolve($action, ['amount' => $amount, 'tenant_id' => $this->context->id()]), null, false);
        $mayViewRules = (bool) rescue(fn () => $user->hasPermission('approvals.matrix.view'), false, false);
        $base = ['action' => $action, 'amount_minor' => $amountMinor, 'currency' => $currency, 'may_view_rules' => $mayViewRules];

        if ($rule === null) {
            // No active rule: the catalogue default is maker-checker, so a referral is required.
            return $base + ['rule_found' => false, 'within_authority' => false, 'referral_required' => true, 'your_authority' => null, 'required_authority' => null];
        }

        $roles = $user->memberships()->where('status', 'ACTIVE')->where('tenant_id', $this->context->id())->pluck('role_code')->all();
        $byPermission = $rule->checker_permission !== null && (bool) rescue(fn () => $user->hasPermission($rule->checker_permission), false, false);
        $byRole = array_intersect((array) ($rule->checker_roles ?? []), $roles) !== [];
        $within = $byPermission || $byRole;

        return [
            ...$base,
            'currency' => $currency ?? $rule->currency,
            'rule_found' => true,
            'within_authority' => $within,
            'referral_required' => ! $within,
            'your_authority' => $mayViewRules ? implode(', ', $roles) : null,
            'required_authority' => $mayViewRules ? array_filter([
                'permission' => $rule->checker_permission,
                'roles' => array_values((array) ($rule->checker_roles ?? [])),
                'approvals' => (int) $rule->required_approvals,
                'min_amount' => $rule->min_amount,
                'max_amount' => $rule->max_amount,
            ], fn ($v) => $v !== null && $v !== []) : null,
        ];
    }
}
