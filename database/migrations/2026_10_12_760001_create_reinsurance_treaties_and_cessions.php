<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Batch 13C — REQ-REI-001 (reinsurer profiles, treaties with effective versions + participants) and
 | REQ-REI-002 (risk cessions: gross/net exposure, ceded premium / commission / brokerage / taxes, bordereaux).
 | Codes follow App\Application\FinancialDistribution\ReinsuranceReference (REINSURANCE_TYPES, TREATY_TYPES).
 | Cessions read policies (premium_minor, currency, terms_snapshot) and never write to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reinsurers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('party_id')->nullable()->constrained();
            $t->string('code', 64);
            $t->string('name');
            $t->string('role', 32);                    // REINSURER | REINSURANCE_BROKER | RETROCESSIONAIRE
            $t->string('country_code', 2)->nullable();
            $t->string('rating', 16)->nullable();
            $t->string('rating_agency', 64)->nullable();
            $t->string('status', 16)->default('ACTIVE'); // ACTIVE | SUSPENDED | INACTIVE
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('reinsurance_treaties', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 64);
            $t->string('name');
            $t->string('reinsurance_type', 32);        // TREATY | FACULTATIVE_OBLIGATORY
            $t->string('treaty_type', 32);             // QUOTA_SHARE | SURPLUS | EXCESS_OF_LOSS | STOP_LOSS | OTHER
            $t->string('currency', 3);
            $t->unsignedSmallInteger('underwriting_year')->nullable();
            $t->string('status', 16)->default('DRAFT'); // DRAFT | ACTIVE | CANCELLED
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('reinsurance_treaty_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('treaty_id')->constrained('reinsurance_treaties');
            $t->unsignedInteger('version');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('status', 16)->default('DRAFT'); // DRAFT | ACTIVE | SUPERSEDED
            $t->jsonb('scope')->default('{}');          // line_codes filter; empty = all lines
            $t->bigInteger('retention_minor')->nullable();
            $t->decimal('cession_percent', 9, 4)->nullable();
            $t->unsignedSmallInteger('lines')->nullable();
            $t->bigInteger('max_capacity_minor')->nullable();
            $t->decimal('commission_percent', 9, 4)->default(0);
            $t->decimal('brokerage_percent', 9, 4)->default(0);
            $t->decimal('tax_percent', 9, 4)->default(0);
            $t->jsonb('layers')->default('[]');         // XL: [{layer, attachment_minor, limit_minor, rate_percent, reinstatements}]
            $t->decimal('rate_percent', 9, 4)->nullable(); // STOP_LOSS premium rate on net retained premium
            $t->decimal('attachment_ratio', 9, 4)->nullable();
            $t->decimal('limit_ratio', 9, 4)->nullable();
            $t->string('notes', 1000)->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('activated_at')->nullable();
            $t->timestampsTz();
            $t->unique(['treaty_id', 'version']);
            $t->index(['tenant_id', 'status', 'effective_from']);
        });

        Schema::create('reinsurance_treaty_participants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('treaty_version_id')->constrained('reinsurance_treaty_versions')->cascadeOnDelete();
            $t->foreignUuid('reinsurer_id')->constrained('reinsurers');
            $t->foreignUuid('broker_id')->nullable()->constrained('reinsurers');
            $t->decimal('share_percent', 9, 4);
            $t->boolean('is_lead')->default(false);
            $t->timestampsTz();
            $t->unique(['treaty_version_id', 'reinsurer_id']);
        });

        Schema::create('reinsurance_cessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained();
            $t->unsignedInteger('policy_version');
            $t->unsignedInteger('run');
            $t->foreignUuid('treaty_id')->constrained('reinsurance_treaties');
            $t->foreignUuid('treaty_version_id')->constrained('reinsurance_treaty_versions');
            $t->string('treaty_type', 32);
            $t->string('currency', 3);
            $t->bigInteger('sum_insured_minor')->nullable();
            $t->bigInteger('gross_premium_minor');
            $t->bigInteger('subject_sum_minor')->nullable();   // exposure presented to this treaty
            $t->bigInteger('ceded_sum_minor')->nullable();
            $t->decimal('ceded_percent', 9, 4);
            $t->bigInteger('ceded_premium_minor');
            $t->bigInteger('commission_minor');
            $t->bigInteger('brokerage_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('net_ceded_premium_minor');
            $t->jsonb('layers')->default('[]');
            $t->string('status', 16)->default('CALCULATED');  // CALCULATED | SUPERSEDED
            $t->uuid('calculated_by')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['policy_id', 'run', 'treaty_version_id']);
            $t->index(['tenant_id', 'treaty_id', 'status']);
        });

        Schema::create('reinsurance_cession_shares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('cession_id')->constrained('reinsurance_cessions')->cascadeOnDelete();
            $t->foreignUuid('reinsurer_id')->constrained('reinsurers');
            $t->decimal('share_percent', 9, 4);
            $t->bigInteger('ceded_sum_minor')->nullable();
            $t->bigInteger('ceded_premium_minor');
            $t->bigInteger('commission_minor');
            $t->bigInteger('brokerage_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('net_premium_minor');
            $t->timestampTz('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE reinsurers ADD CONSTRAINT reinsurers_role_check CHECK (role IN ('REINSURER','REINSURANCE_BROKER','RETROCESSIONAIRE'))");
            DB::statement("ALTER TABLE reinsurance_treaties ADD CONSTRAINT rei_treaty_type_check CHECK (treaty_type IN ('QUOTA_SHARE','SURPLUS','EXCESS_OF_LOSS','STOP_LOSS','OTHER'))");
            DB::statement("ALTER TABLE reinsurance_treaties ADD CONSTRAINT rei_reinsurance_type_check CHECK (reinsurance_type IN ('TREATY','FACULTATIVE_OBLIGATORY'))");
            DB::statement('ALTER TABLE reinsurance_treaty_participants ADD CONSTRAINT rei_participant_share_check CHECK (share_percent > 0 AND share_percent <= 100)');
            DB::statement('ALTER TABLE reinsurance_treaty_versions ADD CONSTRAINT rei_version_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reinsurance_cession_shares');
        Schema::dropIfExists('reinsurance_cessions');
        Schema::dropIfExists('reinsurance_treaty_participants');
        Schema::dropIfExists('reinsurance_treaty_versions');
        Schema::dropIfExists('reinsurance_treaties');
        Schema::dropIfExists('reinsurers');
    }
};
