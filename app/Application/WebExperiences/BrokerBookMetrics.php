<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Application\Partners\BookScope;
use App\Application\Reporting\Kpi\KpiQueryRegistry;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Broker portal (/broker) dashboards, launch 2026-10-02 (BRK-002 operations, BRK-004 customers, BRK-006 claims,
 * BRK-019 KYC). Every number is a live query on the portal tenant, narrowed to the caller's book exactly as the
 * lists are (PortalScope::narrowTable for policies / quotes / proposals / claims, BookScope::parties for customers
 * and KYC, the caller's own partner for commissions). Base record sets are the governed KPI definitions
 * (KpiQueryRegistry::base) wherever one exists, so a tile here and the same KPI in /reports agree.
 * A tile is returned only to a user who may read its source (the list page's read permission).
 *
 * Shape of a tile: ['key' => string, 'label' => string, 'value' => string, 'tone' => string].
 */
final class BrokerBookMetrics
{
    private const L = 'broker_screens_a.metrics.';

    /** @return list<array{key:string,label:string,value:string,tone:string}> */
    public function operations(string $tenantId): array
    {
        $out = [];
        if ($this->may('policies.read')) {
            $written = (int) $this->book('policies', DB::table('policies')->where('policies.tenant_id', $tenantId)->whereNotNull('policies.issued_at')
                ->where('policies.issued_at', '>=', now()->subYear()))->sum('policies.premium_minor');
            $out[] = $this->t('premium_written_12m', Money::format($written, 'XAF'), 'success');
            $out[] = $this->t('policies_active', $this->count('policies', KpiQueryRegistry::base('policies.active', $tenantId)), 'success');
            $renewals = $this->count('policies', KpiQueryRegistry::base('policies.expiring_30d', $tenantId));
            $out[] = $this->t('renewals_due_30d', $renewals, $renewals > 0 ? 'warning' : 'gray');
        }
        if ($this->may('quotes.read')) {
            $out[] = $this->t('quotes_pipeline', $this->count('quotes', $this->openQuotes($tenantId)), 'info');
            $out[] = $this->t('quote_conversion_90d', $this->conversion($tenantId), 'info');
            $out[] = $this->t('proposals_open', $this->count('proposals', KpiQueryRegistry::base('proposals.open', $tenantId)), 'warning');
        }
        if ($this->may('claims.view')) {
            $claims = $this->count('claims', KpiQueryRegistry::base('claims.open', $tenantId));
            $out[] = $this->t('claims_open', $claims, $claims > 0 ? 'warning' : 'gray');
        }
        if ($this->may('broker.finance.read')) {
            $out[] = $this->t('commissions_outstanding', Money::format($this->commissionOutstanding($tenantId), 'XAF'), 'info');
        }

        return $out;
    }

    /** @return list<array{key:string,label:string,value:string,tone:string}> */
    public function customers(string $tenantId): array
    {
        $customers = fn () => $this->inBook(DB::table('tenant_customers')->where('tenant_customers.tenant_id', $tenantId), 'tenant_customers.party_id');
        $active = DB::table('policies')->where('policies.tenant_id', $tenantId)->where('policies.status', 'ACTIVE')->select('policies.party_id');

        return [
            $this->t('customers_total', $customers()->count(), 'info'),
            $this->t('customers_new_30d', $customers()->where('tenant_customers.created_at', '>=', now()->subDays(30))->count(), 'success'),
            $this->t('customers_insured', $customers()->whereIn('tenant_customers.party_id', $active)->count(), 'success'),
            $this->t('customers_without_policy', $customers()->whereNotIn('tenant_customers.party_id', $active)->count(), 'warning'),
            $this->t('customers_kyc_approved', $this->kyc($tenantId)->where('status', 'APPROVED')->whereNull('superseded_by_submission_id')->count(), 'success'),
            $this->t('customers_kyc_pending', $this->kyc($tenantId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'REVIEWING', 'MORE_INFO_REQUIRED', 'PENDING_APPROVAL'])->count(), 'warning'),
        ];
    }

    /** @return list<array{key:string,label:string,value:string,tone:string}> */
    public function claims(string $tenantId): array
    {
        $all = fn () => $this->book('claims', KpiQueryRegistry::base('claims.reported', $tenantId));
        $open = $this->book('claims', KpiQueryRegistry::base('claims.open', $tenantId));
        $failed = $this->book('claim_payments', KpiQueryRegistry::base('claims.failed_payments', $tenantId));

        return [
            $this->t('claims_open', (clone $open)->count(), 'warning'),
            $this->t('claims_reported_30d', $all()->where('claims.created_at', '>=', now()->subDays(30))->count(), 'info'),
            $this->t('claims_closed_30d', $all()->where('claims.closed_at', '>=', now()->subDays(30))->count(), 'success'),
            $this->t('claims_reserve_open', Money::format((int) (clone $open)->sum('claims.current_reserve_minor'), 'XAF'), 'info'),
            $this->t('claims_failed_payments', $failed->count(), 'danger'),
        ];
    }

    /** @return list<array{key:string,label:string,value:string,tone:string}> */
    public function kycSummary(string $tenantId): array
    {
        $by = fn (array $s) => $this->kyc($tenantId)->whereIn('status', $s)->count();

        return [
            $this->t('kyc_draft', $by(['DRAFT']), 'gray'),
            $this->t('kyc_in_review', $by(['SUBMITTED', 'REVIEWING', 'PENDING_APPROVAL']), 'warning'),
            $this->t('kyc_info_requested', $by(['MORE_INFO_REQUIRED']), 'danger'),
            $this->t('kyc_approved', $this->kyc($tenantId)->where('status', 'APPROVED')->whereNull('superseded_by_submission_id')->count(), 'success'),
            $this->t('kyc_rejected', $by(['REJECTED']), 'danger'),
            $this->t('kyc_expiring_60d', $this->kyc($tenantId)->where('status', 'APPROVED')->whereNull('superseded_by_submission_id')->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays(60))->count(), 'warning'),
        ];
    }

    /** KYC submissions of the portal tenant, the caller's book only (same rows as the KYC screens). */
    public function kyc(string $tenantId): Builder
    {
        return $this->inBook(DB::table('kyc_submissions')->where('kyc_submissions.tenant_id', $tenantId), 'kyc_submissions.party_id');
    }

    /** Quotes still in the pipeline: live (not expired) and not yet turned into a proposal. */
    private function openQuotes(string $tenantId): Builder
    {
        return KpiQueryRegistry::base('quotes.created', $tenantId)->where(fn ($w) => $w->whereNull('quotes.expires_at')->orWhere('quotes.expires_at', '>', now()))
            ->whereNotExists(fn ($p) => $p->from('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')->whereColumn('quote_offers.quote_id', 'quotes.id'));
    }

    /** Share of the quotes created in the last 90 days that became a proposal. */
    private function conversion(string $tenantId): string
    {
        $recent = fn () => $this->book('quotes', KpiQueryRegistry::base('quotes.created', $tenantId)->where('quotes.created_at', '>=', now()->subDays(90)));
        $total = $recent()->count();
        if ($total === 0) {
            return '—';
        }
        $converted = $recent()->whereExists(fn ($p) => $p->from('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')->whereColumn('quote_offers.quote_id', 'quotes.id'))->count();

        return round($converted * 100 / $total).' %';
    }

    /** Commission still owed to the caller's own partner (same rows as GET mobile/broker/receivables). */
    private function commissionOutstanding(string $tenantId): int
    {
        $partner = PortalScope::partnerId();

        return $partner === null ? 0 : (int) DB::table('commission_accruals')->where('tenant_id', $tenantId)->where('partner_id', $partner)
            ->whereIn('status', ['PENDING', 'AVAILABLE'])->sum(DB::raw('amount_minor - paid_minor - clawed_back_minor'));
    }

    private function book(string $table, Builder $q): Builder
    {
        return PortalScope::narrowTable($q, $table);
    }

    private function count(string $table, Builder $q): int
    {
        // R4: same SQL as the KPI tiles of the page (KpiEvaluator) — counted once per request.
        return \App\Application\Identity\Rbac\RequestMemo::count($this->book($table, $q));
    }

    private function inBook(Builder $q, string $column): Builder
    {
        $user = auth()->user();
        $parties = $user instanceof User && PortalScope::panel() === 'broker' ? app(BookScope::class)->parties($user) : null;

        return $parties === null ? $q : $q->whereIn($column, $parties);
    }

    private function may(string $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && PortalAuthorization::allowsRead($user, $permission);
    }

    private function t(string $key, int|string $value, string $tone): array
    {
        return ['key' => $key, 'label' => __(self::L.$key), 'value' => (string) $value, 'tone' => $tone];
    }
}
