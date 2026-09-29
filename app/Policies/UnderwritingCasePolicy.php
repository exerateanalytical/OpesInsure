<?php

declare(strict_types=1);

namespace App\Policies;

use App\Application\WebExperiences\PortalAuthorization;
use App\Domain\Tenancy\TenantContext;
use App\Models\{UnderwritingCase, User};

/**
 * Permission-based (owner rule 2026-09-29, no role-name shortcuts), consistent with the API:
 *   GET underwriting/cases[/{c}]   underwriting.decide
 *   GET underwriting/referrals     carrier.referrals.read
 * Always inside the current tenant. Updates go through the workflow actions (UnderwritingCaseActions), never a form.
 */
final class UnderwritingCasePolicy
{
    private function reads(User $u): bool
    {
        return PortalAuthorization::allowsRead($u, 'underwriting.decide') || PortalAuthorization::allowsRead($u, 'carrier.referrals.read');
    }

    public function viewAny(User $u): bool
    {
        return app(TenantContext::class)->id() !== null && $this->reads($u);
    }

    public function view(User $u, UnderwritingCase $m): bool
    {
        return $this->viewAny($u) && $m->tenant_id === app(TenantContext::class)->id();
    }

    public function create(User $u): bool
    {
        return false;
    }

    public function update(User $u, UnderwritingCase $m): bool
    {
        return $this->view($u, $m) && $m->status !== 'DECIDED' && (bool) rescue(fn () => $u->hasPermission('underwriting.decide'), false, false);
    }

    public function delete(User $u, UnderwritingCase $m): bool
    {
        return false;
    }
}
