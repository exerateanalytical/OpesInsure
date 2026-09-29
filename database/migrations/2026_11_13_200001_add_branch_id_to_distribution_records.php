<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S2 branch scoping (2026-09-29): quotes, proposals, policies, claims, renewal cases and commission accruals get a
 * nullable, indexed branch_id (tenant_branches) so the BRANCH_MANAGER screens (BRM-002..016) can read their own branch.
 *
 * Stamping at creation:
 *  - quotes: QuoteService::submit stamps the acting user's membership branch (BranchStamp); the trigger below falls
 *    back to the selling partner's branch, then to the assisted-sale agent's membership branch;
 *  - proposals: the creator's (created_by) membership branch, else the quote's branch;
 *  - policies: the proposal's branch; claims, renewal cases, commission accruals: the policy's branch.
 * These fallbacks are BEFORE INSERT triggers so every creation path (services, DB::table inserts, connectors) is
 * covered; an explicitly supplied branch_id is never overwritten. A membership branch is used only when the user has
 * exactly one branch among their ACTIVE memberships in that tenant (otherwise it is not determinable → NULL).
 *
 * Backfill: the same rules applied to existing rows where branch_id IS NULL, in dependency order (idempotent).
 * Additive: down() drops the triggers, functions and columns only.
 */
return new class extends Migration
{
    private const TABLES = ['quotes', 'proposals', 'policies', 'claims', 'renewal_cases', 'commission_accruals'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'branch_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->foreignUuid('branch_id')->nullable()->constrained('tenant_branches')->nullOnDelete();
                $t->index(['tenant_id', 'branch_id'], $table.'_tenant_branch_idx');
            });
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION opes_membership_branch(p_user uuid, p_tenant uuid) RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT CASE WHEN count(DISTINCT branch_id) = 1 THEN min(branch_id::text)::uuid END
    FROM tenant_memberships
    WHERE user_id = p_user AND tenant_id = p_tenant AND status = 'ACTIVE' AND branch_id IS NOT NULL
$$;

CREATE OR REPLACE FUNCTION opes_quote_branch(p_partner uuid, p_context jsonb, p_tenant uuid) RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT COALESCE(
        (SELECT branch_id FROM partners WHERE id = p_partner AND tenant_id = p_tenant),
        CASE WHEN (p_context ->> 'agent_user_id') ~* '^[0-9a-f-]{36}$'
             THEN opes_membership_branch((p_context ->> 'agent_user_id')::uuid, p_tenant) END
    )
$$;

CREATE OR REPLACE FUNCTION opes_stamp_branch() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.branch_id IS NOT NULL THEN
        RETURN NEW;
    END IF;
    IF TG_TABLE_NAME = 'quotes' THEN
        NEW.branch_id := opes_quote_branch(NEW.partner_id, NEW.comparison_context::jsonb, NEW.tenant_id);
    ELSIF TG_TABLE_NAME = 'proposals' THEN
        NEW.branch_id := COALESCE(
            CASE WHEN NEW.created_by IS NOT NULL THEN opes_membership_branch(NEW.created_by, NEW.tenant_id) END,
            (SELECT q.branch_id FROM quote_offers o JOIN quotes q ON q.id = o.quote_id WHERE o.id = NEW.quote_offer_id AND q.tenant_id = NEW.tenant_id));
    ELSIF TG_TABLE_NAME = 'policies' THEN
        NEW.branch_id := (SELECT branch_id FROM proposals WHERE id = NEW.proposal_id AND tenant_id = NEW.tenant_id);
    ELSE
        NEW.branch_id := (SELECT branch_id FROM policies WHERE id = NEW.policy_id AND tenant_id = NEW.tenant_id);
    END IF;
    RETURN NEW;
END
$$;
SQL);

        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_stamp_branch ON {$table}; CREATE TRIGGER {$table}_stamp_branch BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION opes_stamp_branch();");
        }

        // Backfill, dependency order, only rows still unset (idempotent).
        DB::unprepared(<<<'SQL'
UPDATE proposals p SET branch_id = opes_membership_branch(p.created_by, p.tenant_id)
    WHERE p.branch_id IS NULL AND p.created_by IS NOT NULL AND opes_membership_branch(p.created_by, p.tenant_id) IS NOT NULL;
UPDATE quotes q SET branch_id = s.branch_id FROM (
        SELECT o.quote_id, CASE WHEN count(DISTINCT pr.branch_id) = 1 THEN min(pr.branch_id::text)::uuid END AS branch_id
        FROM proposals pr JOIN quote_offers o ON o.id = pr.quote_offer_id WHERE pr.branch_id IS NOT NULL GROUP BY o.quote_id
    ) s WHERE q.id = s.quote_id AND q.branch_id IS NULL AND s.branch_id IS NOT NULL;
UPDATE quotes q SET branch_id = opes_quote_branch(q.partner_id, q.comparison_context::jsonb, q.tenant_id)
    WHERE q.branch_id IS NULL AND opes_quote_branch(q.partner_id, q.comparison_context::jsonb, q.tenant_id) IS NOT NULL;
UPDATE proposals p SET branch_id = q.branch_id FROM quote_offers o JOIN quotes q ON q.id = o.quote_id
    WHERE p.branch_id IS NULL AND o.id = p.quote_offer_id AND q.tenant_id = p.tenant_id AND q.branch_id IS NOT NULL;
UPDATE policies x SET branch_id = p.branch_id FROM proposals p
    WHERE x.branch_id IS NULL AND p.id = x.proposal_id AND p.tenant_id = x.tenant_id AND p.branch_id IS NOT NULL;
UPDATE claims c SET branch_id = x.branch_id FROM policies x
    WHERE c.branch_id IS NULL AND x.id = c.policy_id AND x.tenant_id = c.tenant_id AND x.branch_id IS NOT NULL;
UPDATE renewal_cases c SET branch_id = x.branch_id FROM policies x
    WHERE c.branch_id IS NULL AND x.id = c.policy_id AND x.tenant_id = c.tenant_id AND x.branch_id IS NOT NULL;
UPDATE commission_accruals c SET branch_id = x.branch_id FROM policies x
    WHERE c.branch_id IS NULL AND x.id = c.policy_id AND x.tenant_id = c.tenant_id AND x.branch_id IS NOT NULL;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_stamp_branch ON {$table};");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS opes_stamp_branch(); DROP FUNCTION IF EXISTS opes_quote_branch(uuid, jsonb, uuid); DROP FUNCTION IF EXISTS opes_membership_branch(uuid, uuid);');
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) use ($table): void {
                    $t->dropIndex($table.'_tenant_branch_idx');
                    $t->dropConstrainedForeignId('branch_id');
                });
            }
        }
    }
};
