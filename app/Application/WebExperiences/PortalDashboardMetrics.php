<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use Illuminate\Support\Facades\DB;

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
    /** @return list<array{key:string, label:string, value:int|string, hint:?string, tone:string, drilldown:?string}> */
    public function for(string $portal, string $tenantId): array
    {
        return match ($portal) {
            'insurer' => $this->insurer($tenantId),
            'broker' => $this->broker($tenantId),
            default => [],
        };
    }

    private function insurer(string $t): array
    {
        $openClaims = DB::table('claims')->where('tenant_id', $t)->whereNull('closed_at')->whereNotIn('status', ['CLOSED', 'CLOSED_PAID', 'REJECTED', 'WITHDRAWN']);
        $reserve = (int) (clone $openClaims)->sum('current_reserve_minor');

        return [
            $this->m('active_policies', DB::table('policies')->where('tenant_id', $t)->where('status', 'ACTIVE')->count(), 'success', 'policies'),
            $this->m('pending_issuance', DB::table('policies')->where('tenant_id', $t)->where('status', 'PAID_PENDING_ISSUANCE')->count(), 'warning', 'policies'),
            $this->m('open_claims', (clone $openClaims)->count(), 'info', 'claims'),
            $this->m('outstanding_reserve', Money::format($reserve, 'XAF'), 'info', 'claims'),
            $this->m('underwriting_queue', DB::table('underwriting_cases')->where('tenant_id', $t)->whereIn('status', ['QUEUED', 'IN_REVIEW', 'REFERRED'])->count(), 'warning', null),
            $this->m('failed_claim_payments', DB::table('claim_payments as p')->join('claims as c', 'c.id', '=', 'p.claim_id')->where('c.tenant_id', $t)->where('p.status', 'FAILED')->count(), 'danger', 'claims'),
        ];
    }

    private function broker(string $t): array
    {
        return [
            $this->m('quotes_30d', DB::table('quotes')->where('tenant_id', $t)->where('created_at', '>=', now()->subDays(30))->count(), 'info', 'quotes'),
            $this->m('proposals_open', DB::table('proposals')->where('tenant_id', $t)->whereNotIn('status', ['ISSUED', 'CANCELLED', 'REJECTED', 'DECLINED', 'EXPIRED'])->count(), 'warning', null),
            $this->m('active_policies', DB::table('policies')->where('tenant_id', $t)->where('status', 'ACTIVE')->count(), 'success', 'policies'),
            $this->m('renewals_due_30d', DB::table('policies')->where('tenant_id', $t)->whereIn('status', ['ACTIVE', 'EXPIRING'])->whereBetween('coverage_ends_at', [now(), now()->addDays(30)])->count(), 'warning', 'policies'),
            $this->m('payments_pending', DB::table('payment_intents')->where('tenant_id', $t)->whereIn('status', ['PENDING', 'REQUESTED', 'STARTED', 'PROCESSING'])->count(), 'warning', null),
            $this->m('open_claims', DB::table('claims')->where('tenant_id', $t)->whereNull('closed_at')->count(), 'info', 'claims'),
        ];
    }

    private function m(string $key, int|string $value, string $tone, ?string $drilldown): array
    {
        return ['key' => $key, 'label' => __('web_experience.metrics.'.$key), 'value' => $value, 'hint' => null, 'tone' => $tone, 'drilldown' => $drilldown];
    }
}
