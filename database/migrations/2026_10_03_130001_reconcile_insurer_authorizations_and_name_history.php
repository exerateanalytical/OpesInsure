<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3 / 3A — CIMA reconciliation (additive only).
 *
 * REQ-DUP-017: insurer_regulatory_authorizations + insurer_authorized_branches are canonical.
 *   insurer_authorizations (official register, IARD/LIFE per year) stays the register-year source
 *   and feeds the canonical row through register_authorization_id. Maker-checker goes through the
 *   one approval engine (approval_request_id → approval_requests).
 * REQ-CIMA-005: regulatory_reporting_mappings gains an owner scope (PLATFORM / CARRIER / PARTNER)
 *   and an Art. 557 measure so INS-SET-CIMA-004 and BRK-SET-CIMA-001…004 reuse the one mapping table.
 * REQ-SEED-003: organization_name_histories — effective-dated legal/trade/short name history of
 *   carriers and partners; append-only (a year is never overwritten).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurer_regulatory_authorizations', function (Blueprint $t) {
            $t->foreignUuid('register_authorization_id')->nullable()->constrained('insurer_authorizations')->nullOnDelete();
            $t->uuid('approval_request_id')->nullable()->index();
            $t->string('licence_family', 16)->nullable();   // IARD | LIFE — family of the authorized branches
        });

        Schema::table('regulatory_reporting_mappings', function (Blueprint $t) {
            $t->string('owner_type', 16)->default('PLATFORM');  // PLATFORM | CARRIER | PARTNER
            $t->uuid('owner_id')->nullable();
            // Setup screen that owns the row, e.g. INS-SET-CIMA-004, BRK-SET-CIMA-003.
            $t->string('setup_screen', 24)->nullable();
            $t->string('measure_code', 96)->nullable();         // Art. 557 intermediary measure
            $t->index(['owner_type', 'owner_id']);
        });

        Schema::create('organization_name_histories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('subject_type', 16);                     // CARRIER | PARTNER
            $t->uuid('subject_id');
            $t->string('legal_name')->nullable();
            $t->string('trade_name')->nullable();
            $t->string('short_name', 64)->nullable();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->unsignedSmallInteger('reference_year')->nullable();
            $t->string('source', 24);                           // OFFICIAL_REGISTER | ADMIN | BASELINE
            $t->string('source_authority', 32)->nullable();
            $t->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['subject_type', 'subject_id', 'effective_from']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_name_history_rewrite() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'organization_name_histories is append-only; close a row with effective_until instead';
                    END IF;
                    IF NEW.legal_name IS DISTINCT FROM OLD.legal_name OR NEW.trade_name IS DISTINCT FROM OLD.trade_name
                        OR NEW.short_name IS DISTINCT FROM OLD.short_name OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
                        OR NEW.reference_year IS DISTINCT FROM OLD.reference_year THEN
                        RAISE EXCEPTION 'organization_name_histories rows are never overwritten; record a new effective-dated row';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared('CREATE TRIGGER organization_name_histories_append_only BEFORE UPDATE OR DELETE ON organization_name_histories FOR EACH ROW EXECUTE FUNCTION prevent_name_history_rewrite();');
        }

        // Baseline: today's names become the first history row (source BASELINE), dated from the register year when known.
        $now = now();
        foreach (['carriers' => 'CARRIER', 'partners' => 'PARTNER'] as $table => $type) {
            DB::table($table)->orderBy('id')->chunk(500, function ($rows) use ($type, $now, $table) {
                $insert = [];
                foreach ($rows as $r) {
                    $legal = $r->legal_name ?? null;
                    $trade = $r->trade_name ?? null;
                    if ($legal === null && $trade === null) {
                        continue;
                    }
                    $year = $r->reference_year ?? null;
                    $insert[] = [
                        'id' => (string) \Illuminate\Support\Str::uuid(), 'subject_type' => $type, 'subject_id' => $r->id,
                        'legal_name' => $legal, 'trade_name' => $trade, 'short_name' => $table === 'carriers' ? ($r->short_name ?? null) : null,
                        'effective_from' => $year ? "{$year}-01-01" : substr((string) $r->created_at, 0, 10), 'reference_year' => $year,
                        'source' => ($r->is_official_register ?? false) ? 'OFFICIAL_REGISTER' : 'BASELINE', 'source_authority' => $r->source_authority ?? null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                if ($insert !== []) {
                    DB::table('organization_name_histories')->insert($insert);
                }
            });
        }

        // Approval matrix default for the new catalogued action (the defaults migration already ran in production).
        $code = 'cima.insurer_authorization.approve';
        $a = \App\Application\Approvals\ApprovalActionCatalogue::ACTIONS[$code] ?? null;
        if ($a && Schema::hasTable('approval_matrix_rules') && ! DB::table('approval_matrix_rules')->whereNull('tenant_id')->where('action_code', $code)->exists()) {
            DB::table('approval_matrix_rules')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => null, 'action_code' => $code, 'workflow' => $a['workflow'], 'category' => $a['category'],
                'description' => $a['description'], 'source_refs' => $a['sources'], 'checker_permission' => null,
                'required_approvals' => 1, 'requires_maker_checker' => true, 'exclude_subject_parties' => true, 'priority' => 1000,
                'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Link existing canonical rows to their register-year source (never invents branches or authorizations).
        foreach (DB::table('insurer_regulatory_authorizations')->whereNull('register_authorization_id')->get() as $a) {
            $reg = DB::table('insurer_authorizations')->where('carrier_id', $a->carrier_id)->where('status', 'AUTHORIZED')->orderByDesc('reference_year')->first();
            if ($reg) {
                DB::table('insurer_regulatory_authorizations')->where('id', $a->id)->update(['register_authorization_id' => $reg->id]);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS organization_name_histories_append_only ON organization_name_histories;');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_name_history_rewrite();');
        }
        Schema::dropIfExists('organization_name_histories');
        Schema::table('regulatory_reporting_mappings', function (Blueprint $t) {
            $t->dropIndex(['owner_type', 'owner_id']);
            $t->dropColumn(['owner_type', 'owner_id', 'setup_screen', 'measure_code']);
        });
        Schema::table('insurer_regulatory_authorizations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('register_authorization_id');
            $t->dropColumn(['approval_request_id', 'licence_family']);
        });
    }
};
