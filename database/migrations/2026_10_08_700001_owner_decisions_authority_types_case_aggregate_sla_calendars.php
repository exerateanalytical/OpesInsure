<?php

declare(strict_types=1);

use App\Application\Cases\CaseTypeCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Owner decisions 2026-09-25 (docs/spec/OWNER_DECISIONS_2026-09-25.md), additive only.
 *
 *  #12 REQ-AUTH-001  authority_types: extensible catalogue (blueprint 14 + owner additions + legacy codes
 *                    already present in authority_limits); authority_limits.authority_type → FK.
 *  #13 REQ-CAS-001   one Case aggregate: case_family + case_type + case_subtype + domain_reference on `cases`;
 *                    case_families = reporting/navigation families (RECONSTRUCTED_PENDING_OWNER: the blueprint
 *                    names "8 case types" without listing them); existing case types mapped, nothing removed.
 *  #17 REQ-POL-008   premium_cover_rules: configurable premium-to-cover rules evaluated with the Rules
 *                    expression evaluator (no parallel evaluator).
 *  #22 REQ-CAL-001   calendar_breaks: optional per-calendar lunch/break exclusion. Nothing is seeded.
 *  #32 REQ-QUO-006   CARRIER_QUOTE_REQUEST v2: PLATFORM_SLA defaults (4 business hours / 2 / 5 business days),
 *                    pauses in WAITING_FOR_CUSTOMER / WAITING_FOR_EXTERNAL_EVIDENCE; sla_policy_overrides make
 *                    them configurable per insurer, product, case type, branch and market.
 *  Complaints        REQ-CPL-001: every SLA target is labelled PLATFORM_SLA; REGULATORY_DEADLINE needs a legal basis
 *                    (CHECK constraints on sla_clocks and sla_policy_overrides).
 *  Datasets          reference_datasets (+ public_holiday_entries, hazard_zone_entries): versioned institutional
 *                    datasets with source, jurisdiction, effective dates and verification status. No data seeded.
 */
return new class extends Migration
{
    /** Blueprint III authority enum (IMPLEMENTATION_BLUEPRINT_V1 §3 table). */
    private const BLUEPRINT_TYPES = [
        'QUOTE' => 'Quote', 'BIND' => 'Bind', 'POLICY_ISSUE' => 'Policy issue', 'DISCOUNT' => 'Discount',
        'PREMIUM_OVERRIDE' => 'Premium override', 'UNDERWRITING' => 'Underwriting', 'CLAIM_RESERVE' => 'Claim reserve',
        'CLAIM_APPROVAL' => 'Claim approval', 'CLAIM_PAYMENT' => 'Claim payment', 'REFUND' => 'Refund', 'WRITE_OFF' => 'Write-off',
        'COMMISSION_ADJUSTMENT' => 'Commission adjustment', 'JOURNAL_APPROVAL' => 'Journal approval', 'REINSURANCE_PLACEMENT' => 'Reinsurance placement',
    ];

    /** Owner decision #12 additions (WRITE_OFF is already a blueprint type). */
    private const OWNER_TYPES = [
        'ENDORSE' => 'Endorse', 'BACKDATE' => 'Backdate', 'CANCEL' => 'Cancel', 'OVERRIDE' => 'Override',
        'RESERVE_APPROVE' => 'Reserve approval', 'CLAIM_SETTLE' => 'Claim settlement', 'REFUND_APPROVE' => 'Refund approval',
        'REINSTATE' => 'Reinstate', 'PAYMENT_OVERRIDE' => 'Payment override', 'JOURNAL_APPROVE' => 'Journal approve',
        'FACULTATIVE_APPROVE' => 'Facultative approval',
    ];

    /** Types written by LegacyAgreementBackfill before this catalogue existed. */
    private const LEGACY_TYPES = ['POLICY_PREMIUM' => 'Policy premium (legacy delegated authority)'];

    public function up(): void
    {
        $now = now();

        // ------------------------------------------------------------------ #12 authority types
        Schema::create('authority_types', function (Blueprint $t): void {
            $t->string('code', 32)->primary();
            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->string('source', 24);           // BLUEPRINT | OWNER_DECISION | LEGACY | ADMIN
            $t->boolean('monetary')->default(true);
            $t->string('status', 16)->default('ACTIVE');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE authority_types ADD CONSTRAINT authority_types_source_allowed CHECK (source IN ('BLUEPRINT','OWNER_DECISION','LEGACY','ADMIN'))");
        DB::statement("ALTER TABLE authority_types ADD CONSTRAINT authority_types_status_allowed CHECK (status IN ('ACTIVE','RETIRED'))");
        DB::statement("ALTER TABLE authority_types ADD CONSTRAINT authority_types_code_format CHECK (code ~ '^[A-Z][A-Z0-9_]*$')");
        $rows = [];
        foreach ([[self::BLUEPRINT_TYPES, 'BLUEPRINT'], [self::OWNER_TYPES, 'OWNER_DECISION'], [self::LEGACY_TYPES, 'LEGACY']] as [$set, $source]) {
            foreach ($set as $code => $name) {
                $rows[$code] = ['code' => $code, 'name' => $name, 'source' => $source, 'monetary' => ! in_array($code, ['BACKDATE', 'CANCEL', 'REINSTATE', 'ENDORSE', 'OVERRIDE'], true), 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        // Any other code already stored in authority_limits is kept as LEGACY (nothing rejected, nothing rewritten).
        if (Schema::hasTable('authority_limits')) {
            foreach (DB::table('authority_limits')->distinct()->pluck('authority_type') as $code) {
                $rows[$code] ??= ['code' => $code, 'name' => $code, 'source' => 'LEGACY', 'monetary' => true, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('authority_types')->insert(array_values($rows));
            Schema::table('authority_limits', fn (Blueprint $t) => $t->foreign('authority_type')->references('code')->on('authority_types')->cascadeOnUpdate());
        } else {
            DB::table('authority_types')->insert(array_values($rows));
        }

        // ------------------------------------------------------------------ #13 one case aggregate
        Schema::create('case_families', function (Blueprint $t): void {
            $t->string('code', 48)->primary();
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->string('source', 32);           // RECONSTRUCTED_PENDING_OWNER | OWNER_CONFIRMED | LEGACY | ADMIN
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE case_families ADD CONSTRAINT case_families_source_allowed CHECK (source IN ('RECONSTRUCTED_PENDING_OWNER','OWNER_CONFIRMED','LEGACY','ADMIN'))");
        $i = 0;
        foreach (CaseTypeCatalogue::FAMILIES as $code => [$name]) {
            DB::table('case_families')->insert(['code' => $code, 'name' => $name, 'source' => 'RECONSTRUCTED_PENDING_OWNER', 'sort_order' => ++$i * 10, 'active' => true,
                'description' => 'Reporting/navigation family (owner decision #13). Working definition until the owner lists the blueprint families.', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('case_types')->whereNotNull('family_code')->distinct()->pluck('family_code') as $code) {
            if (! DB::table('case_families')->where('code', $code)->exists()) {
                DB::table('case_families')->insert(['code' => $code, 'name' => $code, 'source' => 'LEGACY', 'sort_order' => 900, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (CaseTypeCatalogue::FAMILIES as $code => [, $types]) {
            DB::table('case_types')->whereIn('code', $types)->whereNull('family_code')->update(['family_code' => $code]);
        }
        Schema::table('case_types', function (Blueprint $t): void {
            $t->jsonb('subtypes')->default('[]');   // allowed case_subtype codes; [] = free
            $t->foreign('family_code')->references('code')->on('case_families')->cascadeOnUpdate();
        });

        Schema::table('cases', function (Blueprint $t): void {
            $t->string('case_family', 48)->nullable();
            $t->string('case_subtype', 48)->nullable();
            $t->string('domain_reference', 191)->nullable();
            $t->uuid('product_id')->nullable();
            $t->index(['tenant_id', 'case_family']);
            $t->index('domain_reference');
        });
        DB::statement('UPDATE cases c SET case_family = ct.family_code FROM case_types ct WHERE ct.id = c.case_type_id AND c.case_family IS NULL');
        DB::statement("UPDATE cases SET domain_reference = COALESCE(source_type || ':' || source_id::text, subject_type || ':' || subject_id::text) WHERE domain_reference IS NULL");

        // ------------------------------------------------------------------ SLA labels, overrides, breaks
        Schema::table('sla_clocks', function (Blueprint $t): void {
            $t->string('deadline_label', 24)->default('PLATFORM_SLA');
            $t->text('legal_basis')->nullable();
            $t->string('policy_source', 80)->nullable();
        });
        DB::statement("ALTER TABLE sla_clocks ADD CONSTRAINT sla_clocks_label_allowed CHECK (deadline_label IN ('PLATFORM_SLA','REGULATORY_DEADLINE'))");
        DB::statement("ALTER TABLE sla_clocks ADD CONSTRAINT sla_clocks_regulatory_needs_basis CHECK (deadline_label <> 'REGULATORY_DEADLINE' OR legal_basis IS NOT NULL)");

        Schema::create('sla_policy_overrides', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('case_type_code', 48);
            $t->string('metric', 64);
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->uuid('carrier_id')->nullable();         // insurer
            $t->uuid('product_id')->nullable();
            $t->foreignUuid('branch_id')->nullable()->constrained('tenant_branches');
            $t->string('market', 8)->nullable();        // jurisdiction
            $t->string('case_subtype', 48)->nullable();
            $t->unsignedInteger('target_business_minutes')->nullable();
            $t->unsignedSmallInteger('target_business_days')->nullable();
            $t->unsignedTinyInteger('warn_at_pct')->default(80);
            $t->string('escalate_to', 64)->nullable();
            $t->string('deadline_label', 24)->default('PLATFORM_SLA');
            $t->text('legal_basis')->nullable();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['case_type_code', 'metric', 'status']);
        });
        DB::statement("ALTER TABLE sla_policy_overrides ADD CONSTRAINT spo_target_one CHECK ((target_business_minutes IS NULL) <> (target_business_days IS NULL))");
        DB::statement('ALTER TABLE sla_policy_overrides ADD CONSTRAINT spo_target_positive CHECK (COALESCE(target_business_minutes, target_business_days) > 0)');
        DB::statement("ALTER TABLE sla_policy_overrides ADD CONSTRAINT spo_label_allowed CHECK (deadline_label IN ('PLATFORM_SLA','REGULATORY_DEADLINE'))");
        DB::statement("ALTER TABLE sla_policy_overrides ADD CONSTRAINT spo_regulatory_needs_basis CHECK (deadline_label <> 'REGULATORY_DEADLINE' OR (legal_basis IS NOT NULL AND length(trim(legal_basis)) > 0))");
        DB::statement("ALTER TABLE sla_policy_overrides ADD CONSTRAINT spo_status_allowed CHECK (status IN ('ACTIVE','RETIRED'))");
        DB::statement('ALTER TABLE sla_policy_overrides ADD CONSTRAINT spo_warn_range CHECK (warn_at_pct BETWEEN 1 AND 99)');

        Schema::create('calendar_breaks', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('jurisdiction', 8);
            $t->foreignUuid('branch_id')->nullable()->constrained('tenant_branches');
            $t->unsignedTinyInteger('weekday')->nullable(); // NULL = every configured working day
            $t->time('starts');
            $t->time('ends');
            $t->string('label', 120)->default('Break');
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['jurisdiction', 'branch_id']);
        });
        DB::statement('ALTER TABLE calendar_breaks ADD CONSTRAINT calendar_breaks_window CHECK (ends > starts AND (weekday IS NULL OR weekday BETWEEN 1 AND 7))');

        // ------------------------------------------------------------------ institutional datasets
        Schema::create('reference_datasets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('kind', 24);                     // PUBLIC_HOLIDAYS | HAZARD_ZONES
            $t->string('jurisdiction', 8);
            $t->string('code', 80);
            $t->unsignedInteger('version');
            $t->string('source_name', 255);
            $t->string('source_reference', 255)->nullable();
            $t->string('source_url', 500)->nullable();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('verification_status', 16)->default('UNVERIFIED');
            $t->text('verification_note')->nullable();
            $t->string('status', 16)->default('DRAFT');
            $t->string('content_hash', 64)->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['kind', 'jurisdiction', 'code', 'version']);
        });
        DB::statement("ALTER TABLE reference_datasets ADD CONSTRAINT reference_datasets_kind_allowed CHECK (kind IN ('PUBLIC_HOLIDAYS','HAZARD_ZONES'))");
        DB::statement("ALTER TABLE reference_datasets ADD CONSTRAINT reference_datasets_verification_allowed CHECK (verification_status IN ('UNVERIFIED','VERIFIED','DISPUTED'))");
        DB::statement("ALTER TABLE reference_datasets ADD CONSTRAINT reference_datasets_status_allowed CHECK (status IN ('DRAFT','ACTIVE','RETIRED'))");
        DB::statement('ALTER TABLE reference_datasets ADD CONSTRAINT reference_datasets_dates CHECK (effective_until IS NULL OR effective_until >= effective_from)');

        Schema::create('public_holiday_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('dataset_id')->constrained('reference_datasets')->cascadeOnDelete();
            $t->date('date');
            $t->string('label', 160);
            $t->string('holiday_type', 24)->default('PUBLIC');   // PUBLIC | RELIGIOUS_MOVABLE | DECREED
            $t->string('legal_reference', 255)->nullable();
            $t->unique(['dataset_id', 'date', 'label']);
            $t->index('date');
        });

        Schema::create('hazard_zone_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('dataset_id')->constrained('reference_datasets')->cascadeOnDelete();
            $t->string('hazard_type', 32);              // FLOOD, SEISMIC, VOLCANIC, LANDSLIDE, STORM, ...
            $t->string('zone_code', 64);
            $t->string('name', 160);
            $t->string('admin_area_code', 64)->nullable();
            $t->string('hazard_level', 24)->nullable();
            $t->jsonb('geometry')->nullable();          // GeoJSON when the source supplies it
            $t->jsonb('attributes')->default('{}');
            $t->unique(['dataset_id', 'hazard_type', 'zone_code']);
        });

        // ------------------------------------------------------------------ #17 premium-to-cover rules
        Schema::create('premium_cover_rules', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('code', 64);
            $t->string('name', 160);
            $t->uuid('carrier_id')->nullable();
            $t->uuid('product_id')->nullable();
            $t->string('class_code', 40)->nullable();
            $t->string('jurisdiction', 8)->nullable();
            $t->jsonb('premium_statuses')->default('[]');   // [] = any status
            $t->boolean('is_exception')->default(false);
            $t->jsonb('activation_rule');                    // Rules expression (app/Domain/Rules/Expression)
            $t->string('outcome', 24);                       // COVER_ACTIVE | NO_COVER | COVER_SUSPENDED | GRACE
            $t->unsignedSmallInteger('grace_days')->nullable();
            $t->integer('priority')->default(100);
            $t->text('legal_basis')->nullable();
            $t->string('verification_status', 16)->default('UNVERIFIED');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('status', 16)->default('DRAFT');
            $t->uuid('created_by')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->index(['status', 'jurisdiction']);
        });
        DB::statement("ALTER TABLE premium_cover_rules ADD CONSTRAINT pcr_outcome_allowed CHECK (outcome IN ('COVER_ACTIVE','NO_COVER','COVER_SUSPENDED','GRACE'))");
        DB::statement("ALTER TABLE premium_cover_rules ADD CONSTRAINT pcr_status_allowed CHECK (status IN ('DRAFT','ACTIVE','RETIRED'))");
        DB::statement("ALTER TABLE premium_cover_rules ADD CONSTRAINT pcr_verification_allowed CHECK (verification_status IN ('UNVERIFIED','VERIFIED'))");
        DB::statement("ALTER TABLE premium_cover_rules ADD CONSTRAINT pcr_grace_needs_days CHECK (outcome <> 'GRACE' OR grace_days IS NOT NULL)");
        DB::statement('ALTER TABLE premium_cover_rules ADD CONSTRAINT pcr_dates CHECK (effective_until IS NULL OR effective_until >= effective_from)');

        // ------------------------------------------------------------------ #32 CARRIER_QUOTE_REQUEST v2
        $current = DB::table('case_types')->where('code', 'CARRIER_QUOTE_REQUEST')->orderByDesc('version')->first();
        if ($current !== null && ! DB::table('case_types')->where('code', 'CARRIER_QUOTE_REQUEST')->where('version', '>', 1)->exists()) {
            $def = CaseTypeCatalogue::manualQuoteLifecycle();
            DB::table('case_types')->where('code', 'CARRIER_QUOTE_REQUEST')->where('status', 'EFFECTIVE')
                ->update(['status' => 'SUPERSEDED', 'valid_to' => $now->toDateString(), 'updated_at' => $now]);
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => 'CARRIER_QUOTE_REQUEST', 'version' => $current->version + 1, 'family_code' => 'QUOTATION',
                'name' => $current->name, 'status' => 'EFFECTIVE', 'valid_from' => $now->toDateString(), 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                'sla_policies' => json_encode(CaseTypeCatalogue::MANUAL_QUOTE_SLA_DEFAULTS), 'subtypes' => json_encode(['STANDARD', 'COMPLEX', 'REFERRED']),
                'auto_tasks' => '[]', 'default_confidentiality' => $current->default_confidentiality, 'regulated' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['premium_cover_rules', 'hazard_zone_entries', 'public_holiday_entries', 'reference_datasets', 'calendar_breaks', 'sla_policy_overrides'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE sla_clocks DROP CONSTRAINT IF EXISTS sla_clocks_regulatory_needs_basis');
        DB::statement('ALTER TABLE sla_clocks DROP CONSTRAINT IF EXISTS sla_clocks_label_allowed');
        Schema::table('sla_clocks', fn (Blueprint $t) => $t->dropColumn(['deadline_label', 'legal_basis', 'policy_source']));
        Schema::table('cases', function (Blueprint $t): void {
            $t->dropIndex(['tenant_id', 'case_family']);
            $t->dropIndex(['domain_reference']);
            $t->dropColumn(['case_family', 'case_subtype', 'domain_reference', 'product_id']);
        });
        Schema::table('case_types', function (Blueprint $t): void {
            $t->dropForeign(['family_code']);
            $t->dropColumn('subtypes');
        });
        Schema::dropIfExists('case_families');
        if (Schema::hasTable('authority_limits')) {
            Schema::table('authority_limits', fn (Blueprint $t) => $t->dropForeign(['authority_type']));
        }
        Schema::dropIfExists('authority_types');
        // Case type versions are reference data and are never deleted.
    }
};
