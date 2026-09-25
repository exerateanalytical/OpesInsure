<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3 / 3E — additive only.
 *
 * REQ-SET-001  platform identity on the existing platform_settings singleton (country, currency, languages, zone).
 * REQ-SET-004  configuration_overrides: published per-level values (Platform → Insurer → Broker agreement →
 *              Broker internal → Branch → User), written only by the ConfigurationGovernanceService applier.
 * REQ-SEED-004 / REQ-DUP-023  delegated_authority_agreements is split into
 *              carrier_broker_agreements (+ carrier_broker_agreement_products) for distribution and
 *              authority_limits for monetary authority. The legacy table is kept and backfilled from.
 * REQ-SEED-001 provenance (data_origin, is_demo) on transactional tables the demo layer writes to.
 */
return new class extends Migration
{
    /** Tables that receive data_origin + is_demo when they exist and lack them. */
    private const PROVENANCE_TABLES = [
        'tenants', 'tenant_branches', 'parties', 'tenant_customers', 'risk_assets', 'quotes', 'quote_offers', 'proposals',
        'underwriting_cases', 'payment_intents', 'policies', 'policy_issuance_requests', 'claims', 'commission_accruals',
    ];

    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $t): void {
            $t->string('platform_name', 120)->nullable();
            $t->string('country_code', 2)->default('CM');
            $t->string('currency_code', 3)->default('XAF');
            $t->string('default_locale', 8)->default('fr');
            $t->jsonb('supported_locales')->default('["fr","en"]');
            $t->string('regulatory_zone', 16)->default('CIMA');
            $t->timestampTz('setup_completed_at')->nullable();
            $t->uuid('setup_completed_by')->nullable();
        });

        Schema::create('configuration_overrides', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('scope_level', 24);          // PLATFORM | INSURER | BROKER_AGREEMENT | BROKER | BRANCH | USER
            $t->uuid('scope_id')->nullable();       // carrier / agreement / partner / branch / user id; NULL for PLATFORM
            $t->string('config_key', 120);
            $t->jsonb('value');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('status', 16)->default('ACTIVE'); // ACTIVE | SUPERSEDED
            $t->foreignUuid('change_set_id')->nullable()->constrained('configuration_change_sets');
            $t->timestampsTz();
            $t->index(['config_key', 'scope_level', 'scope_id', 'status']);
        });
        DB::statement("ALTER TABLE configuration_overrides ADD CONSTRAINT configuration_overrides_level_check CHECK (scope_level IN ('PLATFORM','INSURER','BROKER_AGREEMENT','BROKER','BRANCH','USER'))");
        DB::statement("ALTER TABLE configuration_overrides ADD CONSTRAINT configuration_overrides_scope_check CHECK ((scope_level = 'PLATFORM') = (scope_id IS NULL))");

        Schema::create('carrier_broker_agreements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('legacy_delegated_authority_agreement_id')->nullable()->unique();
            $t->foreignUuid('carrier_id')->constrained();
            $t->foreignUuid('partner_id')->constrained();
            $t->string('agreement_number', 80)->unique();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('status', 24)->default('DRAFT'); // DRAFT | ACTIVE | SUSPENDED | TERMINATED | EXPIRED
            $t->jsonb('territories')->default('[]');
            $t->jsonb('channels')->default('[]');
            $t->string('data_origin', 24)->nullable();
            $t->boolean('is_demo')->default(false);
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->index(['carrier_id', 'partner_id', 'status']);
        });
        DB::statement("ALTER TABLE carrier_broker_agreements ADD CONSTRAINT cba_period_valid CHECK (effective_until IS NULL OR effective_until >= effective_from)");
        DB::statement("ALTER TABLE carrier_broker_agreements ADD CONSTRAINT cba_maker_checker CHECK (approved_by IS NULL OR created_by IS NULL OR approved_by <> created_by)");

        Schema::create('carrier_broker_agreement_products', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('agreement_id')->constrained('carrier_broker_agreements')->cascadeOnDelete();
            $t->string('line_code', 32);
            $t->foreignUuid('insurance_product_id')->nullable()->constrained('insurance_products'); // NULL = every product of the line
            $t->boolean('can_quote')->default(true);
            $t->boolean('can_bind')->default(false);
            $t->boolean('can_collect_premium')->default(false);
            $t->boolean('requires_carrier_approval')->default(true);
            $t->foreignUuid('commission_rule_version_id')->nullable()->constrained('commission_rule_versions');
            $t->unsignedInteger('commission_basis_points')->nullable();
            $t->string('status', 24)->default('ACTIVE');
            $t->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX cbap_agreement_line_product_unique ON carrier_broker_agreement_products (agreement_id, line_code, COALESCE(insurance_product_id, '00000000-0000-0000-0000-000000000000'::uuid))");
        DB::statement('ALTER TABLE carrier_broker_agreement_products ADD CONSTRAINT cbap_commission_valid CHECK (commission_basis_points IS NULL OR commission_basis_points <= 10000)');
        DB::statement('ALTER TABLE carrier_broker_agreement_products ADD CONSTRAINT cbap_bind_requires_quote CHECK (NOT can_bind OR can_quote)');

        Schema::create('authority_limits', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_broker_agreement_id')->nullable()->constrained('carrier_broker_agreements')->cascadeOnDelete();
            $t->uuid('legacy_delegated_authority_agreement_id')->nullable();
            $t->foreignUuid('carrier_id')->constrained();
            $t->string('holder_type', 16);          // PARTNER | USER | ROLE
            $t->string('holder_id', 64);
            $t->string('authority_type', 32);       // POLICY_PREMIUM | CLAIM_PAYMENT
            $t->string('line_code', 32)->nullable(); // NULL = every line of the agreement
            $t->bigInteger('max_amount_minor');
            $t->string('currency', 3)->default('XAF');
            $t->jsonb('territories')->default('[]');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('status', 24)->default('ACTIVE');
            $t->timestampsTz();
            $t->index(['holder_type', 'holder_id', 'authority_type', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX authority_limits_legacy_unique ON authority_limits (legacy_delegated_authority_agreement_id, authority_type) WHERE legacy_delegated_authority_agreement_id IS NOT NULL");
        DB::statement('ALTER TABLE authority_limits ADD CONSTRAINT authority_limits_amount_valid CHECK (max_amount_minor >= 0)');

        foreach (self::PROVENANCE_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table): void {
                if (! Schema::hasColumn($table, 'data_origin')) {
                    $t->string('data_origin', 24)->nullable();
                }
                if (! Schema::hasColumn($table, 'is_demo')) {
                    $t->boolean('is_demo')->default(false);
                }
            });
        }

        // REQ-DUP-023: one backfill implementation, also run by `opesinsure:agreements:sync-legacy`.
        app(\App\Application\CarrierOperations\Agreements\LegacyAgreementBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_limits');
        Schema::dropIfExists('carrier_broker_agreement_products');
        Schema::dropIfExists('carrier_broker_agreements');
        Schema::dropIfExists('configuration_overrides');
        Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn(['platform_name', 'country_code', 'currency_code', 'default_locale', 'supported_locales', 'regulatory_zone', 'setup_completed_at', 'setup_completed_by']));
        // Provenance columns on shared tables are left in place (additive; other code may read them).
    }
};
