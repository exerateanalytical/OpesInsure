<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S10 — bulk carrier–broker agreement setup. One row per uploaded file: the DRAFT agreements it created
 * (via CarrierBrokerAgreementService::create), who made them, who submitted them for approval, and who
 * activated them (maker-checker: activation by a different user, enforced by the service and BulkAgreementImporter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_bulk_imports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('carrier_scope_id')->nullable(); // insurer portal: the importing carrier; NULL = platform staff
            $t->string('file_name')->nullable();
            $t->string('status', 24)->default('DRAFT'); // DRAFT | SUBMITTED | ACTIVATED | PARTIAL
            $t->jsonb('agreement_ids')->default('[]');
            $t->jsonb('activation_errors')->default('{}');
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('submitted_by')->nullable()->constrained('users');
            $t->timestampTz('submitted_at')->nullable();
            $t->foreignUuid('activated_by')->nullable()->constrained('users');
            $t->timestampTz('activated_at')->nullable();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_bulk_imports');
    }
};
