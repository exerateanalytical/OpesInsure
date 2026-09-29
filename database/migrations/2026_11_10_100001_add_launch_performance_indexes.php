<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R4 launch performance (2026-10-02, 149 brokers): indexes behind the insurer / broker dashboards, the book lists,
 * KPI evaluation, the book scope (customer_attributions) and permission checks. PostgreSQL does not index foreign
 * keys by itself: at 20k policies / 5k claims / 50k payments the carrier and book narrowing, the per-month KPI series
 * and the correlated counts were sequential scans (tests/Feature/Performance/VolumeProfileTest.php).
 *
 * Additive and production-safe: CREATE INDEX CONCURRENTLY IF NOT EXISTS outside a transaction (no write lock on the
 * live tables); an INVALID leftover of an interrupted concurrent build is dropped and rebuilt; a table or column that
 * does not exist is skipped. down() drops only these indexes.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** index name => [table, column list] */
    private const INDEXES = [
        // policies: tenant / status KPIs, issued-at series, book (party) and chain (proposal) joins
        'perf_policies_tenant_status_ends_idx' => ['policies', ['tenant_id', 'status', 'coverage_ends_at']],
        'perf_policies_tenant_issued_idx' => ['policies', ['tenant_id', 'issued_at']],
        'perf_policies_party_idx' => ['policies', ['party_id']],
        'perf_policies_proposal_idx' => ['policies', ['proposal_id']],
        'perf_policies_carrier_issued_idx' => ['policies', ['carrier_id', 'issued_at']],
        // claims
        'perf_claims_policy_idx' => ['claims', ['policy_id']],
        'perf_claims_claimant_idx' => ['claims', ['claimant_party_id']],
        'perf_claims_tenant_created_idx' => ['claims', ['tenant_id', 'created_at']],
        'perf_claims_tenant_closed_idx' => ['claims', ['tenant_id', 'closed_at']],
        'perf_claims_assigned_idx' => ['claims', ['assigned_to']],
        // premium payments
        'perf_payment_intents_proposal_idx' => ['payment_intents', ['proposal_id']],
        'perf_payment_intents_tenant_created_idx' => ['payment_intents', ['tenant_id', 'created_at']],
        'perf_payment_intents_tenant_status_idx' => ['payment_intents', ['tenant_id', 'status']],
        // quote -> offer -> proposal chain
        'perf_proposals_party_idx' => ['proposals', ['party_id']],
        'perf_proposals_tenant_status_idx' => ['proposals', ['tenant_id', 'status']],
        'perf_quote_offers_quote_idx' => ['quote_offers', ['quote_id']],
        'perf_quote_offers_carrier_idx' => ['quote_offers', ['carrier_id']],
        'perf_quote_offers_product_idx' => ['quote_offers', ['product_id']],
        'perf_quotes_party_idx' => ['quotes', ['party_id']],
        'perf_quotes_tenant_created_idx' => ['quotes', ['tenant_id', 'created_at']],
        // book scope and customers
        'perf_customer_attributions_partner_status_idx' => ['customer_attributions', ['partner_id', 'status']],
        'perf_tenant_customers_party_idx' => ['tenant_customers', ['party_id']],
        'perf_kyc_submissions_tenant_status_idx' => ['kyc_submissions', ['tenant_id', 'status']],
        'perf_partners_party_idx' => ['partners', ['party_id']],
        'perf_partners_tenant_idx' => ['partners', ['tenant_id']],
        'perf_commission_accruals_partner_status_idx' => ['commission_accruals', ['partner_id', 'status']],
        // identity (every permission / scope check)
        'perf_tenant_memberships_user_tenant_idx' => ['tenant_memberships', ['user_id', 'tenant_id', 'status']],
        'perf_tenant_memberships_carrier_idx' => ['tenant_memberships', ['carrier_id']],
        // underwriting workbench
        'perf_underwriting_cases_tenant_status_idx' => ['underwriting_cases', ['tenant_id', 'status']],
        'perf_underwriting_cases_carrier_status_idx' => ['underwriting_cases', ['carrier_id', 'status']],
        'perf_underwriting_cases_proposal_idx' => ['underwriting_cases', ['proposal_id']],
        'perf_underwriting_cases_assigned_idx' => ['underwriting_cases', ['assigned_to']],
        'perf_underwriting_decisions_case_idx' => ['underwriting_decisions', ['underwriting_case_id', 'decided_at']],
        'perf_underwriting_referral_tasks_case_idx' => ['underwriting_referral_tasks', ['underwriting_case_id', 'status']],
        // claims workbench / detail
        'perf_claim_events_claim_idx' => ['claim_events', ['claim_id', 'occurred_at']],
        'perf_claim_assignments_tenant_idx' => ['claim_assignments', ['tenant_id', 'status']],
        'perf_claim_payments_status_paid_idx' => ['claim_payments', ['status', 'paid_at']],
        'perf_approval_requests_tenant_idx' => ['approval_requests', ['tenant_id', 'status']],
        'perf_renewal_work_items_tenant_due_idx' => ['renewal_work_items', ['tenant_id', 'renewal_due_on']],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                continue;
            }
            $valid = DB::selectOne('SELECT i.indisvalid AS valid FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = ? AND c.relkind = ?', [$name, 'i']);
            if ($valid !== null && ! $valid->valid) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS \"{$name}\"");
            }
            $cols = implode(', ', array_map(fn ($c) => '"'.$c.'"', $columns));
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS \"{$name}\" ON \"{$table}\" ({$cols})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS \"{$name}\"");
        }
    }
};
