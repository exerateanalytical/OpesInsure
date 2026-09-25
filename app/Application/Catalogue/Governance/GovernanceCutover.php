<?php

declare(strict_types=1);

namespace App\Application\Catalogue\Governance;

use App\Models\InsuranceProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner decision 28 (2026-09-25): new products, new versions and materially changed versions are published only
 * through the governance workflow (TECHNICAL_REVIEW → COMPLIANCE_REVIEW → BUSINESS_APPROVAL → SANDBOX_TESTS →
 * PUBLICATION, ProductGovernanceService). The direct submit / publish path (CatalogueService::submit / publish,
 * POST catalogue/products/{id}/submit|publish) is disabled for them.
 *
 * Grandfathering: every insurance_products row that existed at cutover (migration 2026_10_08_700401) is
 * LEGACY_GRANDFATHERED and keeps the direct path; every version created afterwards is GOVERNED. A new version of a
 * legacy product is a new version, hence GOVERNED. Live products and quotes are untouched.
 */
final class GovernanceCutover
{
    public const GOVERNED = 'GOVERNED';

    public const LEGACY = 'LEGACY_GRANDFATHERED';

    private static int $workflowDepth = 0;

    /** Runs $fn as a governance workflow step: CatalogueService calls made inside it are the workflow's own. */
    public static function withinWorkflow(callable $fn): mixed
    {
        self::$workflowDepth++;
        try {
            return $fn();
        } finally {
            self::$workflowDepth--;
        }
    }

    public static function mode(InsuranceProduct $v): string
    {
        // Read from storage: the flag is set by the cutover migration and never through the model.
        return (string) (($v->exists ? DB::table('insurance_products')->where('id', $v->id)->value('governance_mode') : null)
            ?? $v->getAttribute('governance_mode') ?? self::GOVERNED);
    }

    public static function isLegacy(InsuranceProduct $v): bool
    {
        return self::mode($v) === self::LEGACY;
    }

    /** Refuses the direct submit / publish path for a GOVERNED version outside the governance workflow. */
    public static function assertDirectPathAllowed(InsuranceProduct $v, string $action): void
    {
        if (self::$workflowDepth > 0 || self::isLegacy($v)) {
            return;
        }

        throw ValidationException::withMessages(['governance' => "Direct {$action} is disabled for this product version: new products and new versions must go through the governance workflow "
            .'(TECHNICAL_REVIEW → COMPLIANCE_REVIEW → BUSINESS_APPROVAL → SANDBOX_TESTS → PUBLICATION). Use catalogue/versions/{id}/governance/advance.']);
    }
}
