<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security Matrix v1 §4–11 (agent D1). Additive only, on the existing catalogue (no parallel registry):
 *  - document_canonical_specs / document_types: assigned watermark, seal and physical profiles;
 *  - documents: §8 duplicate-of and replacement reason (supersedes / superseded_by / status_* already exist);
 *  - document_physical_security_assets: the owner-recorded physical controls (print supplier, secure stock and
 *    hologram serial batches, UV capability, corporate seal artwork). Empty = CONFIG_REQUIRED (D6).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['document_canonical_specs', 'document_types'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->string('watermark_profile_code', 32)->nullable();
                $t->jsonb('seal_profile_codes')->nullable();
                $t->jsonb('physical_profile_codes')->nullable();
                if ($table === 'document_canonical_specs') {
                    $t->jsonb('security_profile_assignment')->nullable(); // derivation method + source hash
                }
            });
        }

        Schema::table('documents', function (Blueprint $t) {
            $t->uuid('duplicate_of_document_id')->nullable()->index();
            $t->text('replacement_reason')->nullable();
        });

        Schema::create('document_physical_security_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // PRINT_SUPPLIER | SECURE_STOCK_BATCH | HOLOGRAM_BATCH | UV_CAPABILITY | SEAL_ARTWORK
            $t->string('asset_kind', 32)->index();
            $t->string('physical_profile_code', 8)->nullable(); // PS-01..PS-04
            $t->string('seal_profile_code', 8)->nullable();     // SEAL-01..SEAL-08 (artwork)
            $t->uuid('carrier_id')->nullable()->index();
            $t->uuid('tenant_id')->nullable()->index();
            $t->string('branch_code', 64)->nullable();
            $t->string('name');
            $t->string('supplier_name')->nullable();
            $t->string('supplier_reference', 120)->nullable(); // contract / PO
            $t->string('batch_reference', 120)->nullable();
            $t->string('serial_prefix', 32)->nullable();
            $t->unsignedBigInteger('serial_from')->nullable();
            $t->unsignedBigInteger('serial_to')->nullable();
            $t->unsignedInteger('quantity_received')->default(0);
            $t->unsignedInteger('quantity_issued')->default(0);
            $t->unsignedInteger('quantity_spoiled')->default(0);
            $t->unsignedInteger('quantity_destroyed')->default(0);
            $t->string('custodian_name')->nullable();
            $t->string('artwork_path')->nullable();
            $t->char('artwork_sha256', 64)->nullable();
            $t->jsonb('applies_to_spec_ids')->nullable();
            // CONFIG_REQUIRED | PENDING_VERIFICATION | VERIFIED | RETIRED — only VERIFIED counts.
            $t->string('status', 32)->default('PENDING_VERIFICATION');
            $t->uuid('recorded_by')->nullable();
            $t->uuid('verified_by')->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->text('notes')->nullable();
            $t->string('source', 64)->default('OWNER_RECORDED');
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_physical_security_assets');
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn(['duplicate_of_document_id', 'replacement_reason']));
        Schema::table('document_types', fn (Blueprint $t) => $t->dropColumn(['watermark_profile_code', 'seal_profile_codes', 'physical_profile_codes']));
        Schema::table('document_canonical_specs', fn (Blueprint $t) => $t->dropColumn(['watermark_profile_code', 'seal_profile_codes', 'physical_profile_codes', 'security_profile_assignment']));
    }
};
