<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partner self-service applications (/partners/apply) and "Claim this organisation" requests on the public directory.
 * Additive only: two new tables, nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $intake = function (Blueprint $t): void {
            // Contact verification (one-time code, hashed) and the applicant's secret status link (hashed).
            $t->string('verification_channel', 16)->nullable();
            $t->string('code_hash', 64)->nullable();
            $t->timestampTz('code_expires_at')->nullable();
            $t->unsignedSmallInteger('code_attempts')->default(0);
            $t->unsignedSmallInteger('code_sent_count')->default(0);
            $t->timestampTz('code_sent_at')->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->string('status_token_hash', 64)->unique();
            // [{document_id, kind}] — files go through the malware-scan queue (documents.scan_status).
            $t->jsonb('documents')->default('[]');
            $t->text('info_request')->nullable();
            $t->text('applicant_response')->nullable();
            // Maker-checker: the reviewer records a recommendation, a different admin decides.
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('reviewed_at')->nullable();
            $t->string('recommendation', 16)->nullable();
            $t->text('review_note')->nullable();
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->jsonb('result')->default('{}');
            $t->string('ip_hash', 64)->nullable();
            $t->timestampsTz();
        };

        if (! Schema::hasTable('partner_applications')) {
            Schema::create('partner_applications', function (Blueprint $t) use ($intake) {
                $t->uuid('id')->primary();
                $t->string('reference', 32)->unique();
                $t->string('type', 16);                      // INSURER | BROKER | AGENT
                $t->string('status', 24)->index();           // UNVERIFIED | SUBMITTED | UNDER_REVIEW | INFO_REQUESTED | APPROVED | REJECTED
                $t->string('legal_name');
                $t->string('trade_name')->nullable();
                $t->string('rccm', 64)->nullable()->index();
                $t->string('niu', 32)->nullable()->index();
                $t->string('licence_number', 64)->nullable();
                $t->date('licence_expires_on')->nullable();
                // AGENT: the brokerage (BROKER tenant) they work under, or independent.
                $t->foreignUuid('brokerage_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $t->string('brokerage_confirmation_hash', 64)->nullable();
                $t->timestampTz('brokerage_confirmed_at')->nullable();
                $t->timestampTz('brokerage_declined_at')->nullable();
                $t->string('city', 120);
                $t->string('address')->nullable();
                $t->string('applicant_name', 160);
                $t->string('applicant_phone', 20)->nullable();
                $t->string('applicant_email', 190);
                $t->string('locale', 2)->default('fr');
                $t->jsonb('duplicate_flags')->default('[]');
                $intake($t);
            });
        }

        if (! Schema::hasTable('organisation_claims')) {
            Schema::create('organisation_claims', function (Blueprint $t) use ($intake) {
                $t->uuid('id')->primary();
                $t->string('reference', 32)->unique();
                $t->string('institution_type', 16);          // INSURER | BROKER
                $t->foreignUuid('carrier_id')->nullable()->constrained('carriers')->nullOnDelete();
                $t->foreignUuid('partner_id')->nullable()->constrained('partners')->nullOnDelete();
                $t->string('institution_key', 64)->index();  // CARRIER:<id> | PARTNER:<id>
                $t->string('institution_name');
                $t->string('status', 24)->index();           // UNVERIFIED | SUBMITTED | UNDER_REVIEW | INFO_REQUESTED | APPROVED | REJECTED | DISPUTED
                $t->boolean('is_dispute')->default(false);
                $t->string('claimant_name', 160);
                $t->string('claimant_position', 120);
                $t->string('claimant_email', 190);
                $t->string('claimant_phone', 20)->nullable();
                $t->string('locale', 2)->default('fr');
                $t->string('verification_mode', 24);         // OFFICIAL_CONTACT | MANUAL
                $t->string('official_destination_masked', 120)->nullable();
                $t->foreignUuid('linked_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $intake($t);
            });

            // Claim lock: one live (non-dispute) claim per institution, enforced by the database too.
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("CREATE UNIQUE INDEX organisation_claims_live_lock ON organisation_claims (institution_key) WHERE is_dispute = false AND status IN ('SUBMITTED','UNDER_REVIEW','INFO_REQUESTED','APPROVED')");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_claims');
        Schema::dropIfExists('partner_applications');
    }
};
