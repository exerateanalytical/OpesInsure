<?php

declare(strict_types=1);

use App\Application\Regulatory\CimaCoverageRules;
use App\Application\Regulatory\CimaLegacyAuthorizationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decisions 2026-09-25, items 1-9 and 16 (docs/spec/OWNER_DECISIONS_2026-09-25.md, ADR-003 amendment).
 *
 *  - Item 16: `insurance_branches` becomes the canonical CIMA branch table. `regulatory_branches` is RENAMED
 *    (no data copied, no duplicate rows) and a compatibility view `regulatory_branches` is left behind so raw
 *    SQL readers keep working. New columns: regulatory_regime_id (FK, filled by trigger) and `name`
 *    (generated from label_fr, so it can never drift).
 *  - Items 1-6: coverage-level mapping (PRODUCT -> COVERAGE -> CIMA BRANCH). regulatory_class_defaults gains
 *    mapping_level (PRODUCT | COVERAGE); product_regulatory_mappings gains coverage_code plus the
 *    complementary-cover terms (separate premium, dates, conditions). The owner's Q1 mappings are seeded as rules.
 *  - Items 7-8: authorization record fields (source authority, revocation date, evidence, verification status)
 *    and the LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION register for products already live.
 *  - Item 9: product_regulatory_mappings.branch_allocation_status (PENDING_CARRIER_ALLOCATION by default).
 */
return new class extends Migration
{
    public function up(): void
    {
        $pg = DB::getDriverName() === 'pgsql';

        // ---- Item 16: canonical insurance_branches ------------------------------------------------------
        Schema::rename('regulatory_branches', 'insurance_branches');
        Schema::table('insurance_branches', function (Blueprint $t) {
            $t->foreignUuid('regulatory_regime_id')->nullable()->after('id')->constrained('regulatory_regimes')->restrictOnDelete();
        });
        if ($pg) {
            DB::statement('ALTER TABLE insurance_branches ADD COLUMN name varchar(255) GENERATED ALWAYS AS (label_fr) STORED');
            DB::statement('UPDATE insurance_branches b SET regulatory_regime_id = (SELECT r.id FROM regulatory_regimes r WHERE r.code = b.regime ORDER BY r.effective_from DESC, r.regulatory_version DESC LIMIT 1)');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION insurance_branches_fill_regime() RETURNS trigger AS $$
                BEGIN
                    IF NEW.regulatory_regime_id IS NULL THEN
                        SELECT r.id INTO NEW.regulatory_regime_id FROM regulatory_regimes r WHERE r.code = NEW.regime ORDER BY r.effective_from DESC, r.regulatory_version DESC LIMIT 1;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER insurance_branches_fill_regime BEFORE INSERT OR UPDATE OF regime ON insurance_branches FOR EACH ROW EXECUTE FUNCTION insurance_branches_fill_regime();
                CREATE VIEW regulatory_branches AS SELECT * FROM insurance_branches;
                COMMENT ON VIEW regulatory_branches IS 'Compatibility view (ADR-003 amendment 2026-09-25): insurance_branches is canonical.';
            SQL);
        }

        // ---- Items 1-6: coverage-level mapping ---------------------------------------------------------
        Schema::table('regulatory_class_defaults', function (Blueprint $t) {
            $t->string('mapping_level', 16)->default('PRODUCT');   // PRODUCT | COVERAGE
            $t->boolean('separate_premium')->default(false);
        });
        Schema::table('regulatory_class_defaults', function (Blueprint $t) {
            $t->dropUnique('reg_class_defaults_unique');
        });
        if ($pg) {
            DB::statement("CREATE UNIQUE INDEX reg_class_defaults_unique ON regulatory_class_defaults (line_code, branch_code, relationship_type, COALESCE(requires_coverage_code, ''), regulatory_version)");
        }

        Schema::table('product_regulatory_mappings', function (Blueprint $t) {
            $t->string('coverage_code', 64)->nullable();            // null = product-level mapping
            $t->boolean('separate_premium')->default(false);        // item 6: complementary cover priced separately
            $t->jsonb('conditions')->nullable();                     // item 6: complementary cover conditions
            // item 9: PENDING_CARRIER_ALLOCATION | CARRIER_ALLOCATED | ESTIMATED_NON_REGULATORY
            $t->string('branch_allocation_status', 32)->default('PENDING_CARRIER_ALLOCATION');
            $t->index(['insurance_product_id', 'coverage_code']);
        });

        CimaCoverageRules::seed();

        // ---- Items 7-8: authorization record fields ------------------------------------------------------
        Schema::table('insurer_regulatory_authorizations', function (Blueprint $t) {
            $t->string('source_authority', 64)->nullable();          // e.g. CIMA, MINFI — as written on the decision
            $t->date('revocation_date')->nullable();
            $t->jsonb('evidence')->nullable();
            // UNVERIFIED | VERIFIED | REJECTED | DEMO
            $t->string('verification_status', 24)->default('UNVERIFIED');
        });
        DB::table('insurer_regulatory_authorizations')->where('is_demo', true)->update(['verification_status' => 'DEMO']);
        DB::table('insurer_regulatory_authorizations')->where('is_demo', false)->where('status', 'ACTIVE')->whereNotNull('approved_at')->update(['verification_status' => 'VERIFIED']);
        DB::table('insurer_regulatory_authorizations')->where('status', 'REVOKED')->whereNull('revocation_date')->update(['revocation_date' => DB::raw('effective_until')]);

        Schema::create('legacy_product_authorizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products')->restrictOnDelete();
            $t->foreignUuid('carrier_id')->constrained('carriers')->restrictOnDelete();
            $t->string('product_code', 64);
            $t->uuid('product_family_id')->nullable();
            $t->jsonb('branch_codes');                               // the unverified branches the product was already selling
            // LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION | VERIFIED | WITHDRAWN
            $t->string('status', 64)->default('LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION');
            $t->timestamp('recorded_at');
            $t->timestamp('resolved_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique('insurance_product_id');
            $t->index(['carrier_id', 'product_code', 'status']);
        });

        // Item 8: products already live with an unverified insurer authorization.
        app(CimaLegacyAuthorizationService::class)->register();
    }

    public function down(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        Schema::dropIfExists('legacy_product_authorizations');
        Schema::table('insurer_regulatory_authorizations', fn (Blueprint $t) => $t->dropColumn(['source_authority', 'revocation_date', 'evidence', 'verification_status']));
        Schema::table('product_regulatory_mappings', function (Blueprint $t) {
            $t->dropIndex(['insurance_product_id', 'coverage_code']);
            $t->dropColumn(['coverage_code', 'separate_premium', 'conditions', 'branch_allocation_status']);
        });
        if ($pg) {
            DB::statement('DROP INDEX IF EXISTS reg_class_defaults_unique');
        }
        DB::table('regulatory_class_defaults')->where('source_reference', CimaCoverageRules::SOURCE)->update(['status' => 'SUPERSEDED']);
        Schema::table('regulatory_class_defaults', fn (Blueprint $t) => $t->dropColumn(['mapping_level', 'separate_premium']));
        if ($pg) {
            DB::unprepared('DROP VIEW IF EXISTS regulatory_branches; DROP TRIGGER IF EXISTS insurance_branches_fill_regime ON insurance_branches; DROP FUNCTION IF EXISTS insurance_branches_fill_regime();');
            DB::statement('ALTER TABLE insurance_branches DROP COLUMN IF EXISTS name');
        }
        Schema::table('insurance_branches', function (Blueprint $t) {
            $t->dropConstrainedForeignId('regulatory_regime_id');
        });
        Schema::rename('insurance_branches', 'regulatory_branches');
    }
};
