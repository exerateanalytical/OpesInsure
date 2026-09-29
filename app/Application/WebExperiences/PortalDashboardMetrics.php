<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Application\Reporting\Dashboards\DashboardRegistry;

/**
 * REQ-UI-002 metric contract for the insurer and broker portal dashboards.
 * Every metric is a real, tenant-scoped query (no snapshots, no demo
 * numbers). Shape: key, label, value, (optional) hint, tone and the resource
 * the tile drills down to.
 *
 * Complements PortalDashboardQuery (the /portal/{portal} raw table counts)
 * rather than duplicating it: that one is table-generic, this one is the
 * curated per-experience KPI set.
 */
final class PortalDashboardMetrics
{
    public function __construct(private DashboardRegistry $dashboards) {}

    /** @return list<array{key:string, label:string, value:int|string, hint:?string, tone:string, drilldown:?string}> */
    public function for(string $portal, string $tenantId): array
    {
        if (! in_array($portal, ['insurer', 'broker'], true)) {
            return [];
        }

        // Agent B2 — REQ-RPT-004: tiles and values come from the governed dashboard registry (same shape as before).
        // Owner rule 2026-09-29 (docs/spec/PORTAL_WRITE_RULES.md): a tile drilling into a book is shown only to a user
        // who may read that book (same read permission as its list page).
        return array_values(array_map(fn (array $t) => $this->m(
            $t['key'],
            ($t['format'] ?? null) === 'money_total' ? Money::format((int) $t['value'], 'XAF') : (int) $t['value'],
            $t['tone'],
            $t['drilldown'],
        ), array_filter($this->tilesWithFormat($portal, $tenantId), fn (array $t) => $this->mayRead($t['drilldown'] ?? null))));
    }

    /** Drill-down list => the read permission its list page requires (PortalAuthorization::READ_PERMISSIONS). */
    private const DRILLDOWN_READS = ['claims' => 'claims.view', 'policies' => 'policies.read', 'quotes' => 'quotes.read'];

    private function mayRead(?string $drilldown): bool
    {
        $permission = self::DRILLDOWN_READS[$drilldown] ?? null;
        $user = auth()->user();

        // No signed-in user = a service / report caller, not a portal screen (the widget always runs authenticated).
        return $permission === null || ! $user instanceof \App\Models\User || PortalAuthorization::allowsRead($user, $permission);
    }

    /** @return list<array<string, mixed>> */
    private function tilesWithFormat(string $portal, string $tenantId): array
    {
        $formats = array_column(DashboardRegistry::get($portal)['tiles'], 'format', 'key');

        return array_map(fn (array $t) => $t + ['format' => $formats[$t['key']] ?? null], $this->dashboards->data($tenantId, $portal)['tiles']);
    }

    private function m(string $key, int|string $value, string $tone, ?string $drilldown): array
    {
        return ['key' => $key, 'label' => __('web_experience.metrics.'.$key), 'value' => $value, 'hint' => null, 'tone' => $tone, 'drilldown' => $drilldown];
    }
}
