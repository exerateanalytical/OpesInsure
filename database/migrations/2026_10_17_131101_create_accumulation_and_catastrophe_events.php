<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent E11 — REQ-CAT-001 exposure zones / risk locations / accumulation snapshots,
 * REQ-CAT-002 capacity limits + capacity checks (LOCK-019), REQ-CAT-003 catastrophe events + large-loss notifier.
 * No capacity limit, retention or large-loss threshold is seeded: all are tenant configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accumulation_zones', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 64);
            $t->string('name', 191);
            $t->string('country_code', 2)->nullable();
            $t->jsonb('geography_codes')->default('[]'); // master data geography codes / region / city names, matched case-insensitively
            $t->jsonb('polygon')->nullable();            // optional [[lat, lng], ...]
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('accumulation_risk_locations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained();
            $t->foreignUuid('policy_risk_id')->nullable()->constrained('policy_risks');
            $t->foreignUuid('risk_asset_id')->nullable()->constrained('risk_assets');
            $t->foreignUuid('zone_id')->nullable()->constrained('accumulation_zones');
            $t->string('resolved_by', 16); // POLYGON | GEOGRAPHY | UNRESOLVED
            $t->jsonb('address')->default('{}');
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->jsonb('perils')->default('[]');
            $t->string('currency', 3);
            $t->bigInteger('gross_sum_insured_minor');
            $t->bigInteger('net_sum_insured_minor');
            $t->timestampTz('coverage_starts_at')->nullable();
            $t->timestampTz('coverage_ends_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'zone_id']);
            $t->index(['policy_id']);
        });

        Schema::create('accumulation_capacity_limits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('zone_id')->nullable()->constrained('accumulation_zones'); // null = any zone
            $t->string('peril_code', 32)->default('ALL');
            $t->string('currency', 3);
            $t->bigInteger('retention_limit_minor')->nullable(); // net (after treaty) retained accumulation
            $t->bigInteger('gross_limit_minor')->nullable();     // hard gross capacity
            $t->string('status', 16)->default('ACTIVE');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'zone_id', 'peril_code']);
        });

        Schema::create('accumulation_snapshots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->timestampTz('as_of');
            $t->string('currency', 3);
            $t->bigInteger('gross_total_minor');
            $t->bigInteger('net_total_minor');
            $t->unsignedInteger('location_count');
            $t->unsignedInteger('unresolved_count');
            $t->uuid('created_by')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });

        Schema::create('accumulation_snapshot_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('snapshot_id')->constrained('accumulation_snapshots')->cascadeOnDelete();
            $t->foreignUuid('zone_id')->nullable()->constrained('accumulation_zones');
            $t->string('peril_code', 32);
            $t->unsignedInteger('location_count');
            $t->bigInteger('gross_minor');
            $t->bigInteger('net_minor');
        });

        Schema::create('accumulation_capacity_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('subject_type', 48)->nullable();
            $t->uuid('subject_id')->nullable();
            $t->foreignUuid('zone_id')->nullable()->constrained('accumulation_zones');
            $t->string('peril_code', 32);
            $t->string('currency', 3);
            $t->bigInteger('requested_minor');
            $t->string('result', 32); // WITHIN_RETENTION | TREATY_COVERED | CAPACITY_EXCEEDED | FACULTATIVE_REQUIRED | NOT_CONFIGURED
            $t->jsonb('details')->default('{}');
            $t->uuid('checked_by')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });

        Schema::create('catastrophe_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 64);
            $t->string('name', 191);
            $t->string('peril_code', 32);
            $t->jsonb('zone_ids')->default('[]');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('currency', 3);
            $t->string('status', 16)->default('DECLARED'); // DECLARED | CLOSED
            $t->bigInteger('gross_loss_minor')->default(0);
            $t->bigInteger('recoverable_minor')->default(0);
            $t->timestampTz('aggregated_at')->nullable();
            $t->uuid('declared_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('catastrophe_event_claims', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained('catastrophe_events')->cascadeOnDelete();
            $t->foreignUuid('claim_id')->unique()->constrained(); // a claim belongs to at most one event
            $t->uuid('linked_by')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });

        // Reinsurance recoveries (REQ-REI recoveries) aggregate by event id.
        Schema::table('claims', function (Blueprint $t) {
            $t->foreignUuid('catastrophe_event_id')->nullable()->constrained('catastrophe_events');
        });

        Schema::create('large_loss_thresholds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('currency', 3);
            $t->bigInteger('threshold_minor');
            $t->jsonb('recipient_user_ids')->default('[]');
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'currency']);
        });

        Schema::create('large_loss_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->unique()->constrained();
            $t->bigInteger('amount_minor');
            $t->bigInteger('threshold_minor');
            $t->string('currency', 3);
            $t->unsignedInteger('recipients');
            $t->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('large_loss_notifications');
        Schema::dropIfExists('large_loss_thresholds');
        Schema::table('claims', fn (Blueprint $t) => $t->dropConstrainedForeignId('catastrophe_event_id'));
        foreach (['catastrophe_event_claims', 'catastrophe_events', 'accumulation_capacity_checks', 'accumulation_snapshot_lines', 'accumulation_snapshots',
            'accumulation_capacity_limits', 'accumulation_risk_locations', 'accumulation_zones'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
