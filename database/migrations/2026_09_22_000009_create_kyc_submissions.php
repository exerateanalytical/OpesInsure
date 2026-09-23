<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs customer-facing KYC verification (Wave 12 KYC batch — see
 * App\Application\Kyc\MobileKycService). A submission never stores files
 * itself: it references Documents already uploaded through the existing
 * POST /mobile/documents endpoint (MobileDocumentService), the same way
 * risk_asset_documents already references Documents for insured-asset
 * evidence (see database/migrations/2026_09_20_000003_complete_batch_two_insurance_core.php).
 *
 * status starts at DRAFT while the customer is attaching documents, moves to
 * SUBMITTED once they submit (see MobileKycService::submit — requires at
 * least one attached document, all scanned CLEAN). APPROVED/REJECTED are
 * reserved for a future staff-side decision endpoint that does not exist
 * yet — this batch only ever writes DRAFT/SUBMITTED; see the batch report
 * for that gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_submissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('party_id')->constrained();
            $t->string('status', 24)->default('DRAFT');
            $t->text('notes')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->index(['tenant_id', 'party_id']);
        });

        // Mirrors risk_asset_documents exactly: composite primary key, no
        // surrogate id, a single 'purpose' column tagging what the document
        // is evidence of (e.g. ID_FRONT, ID_BACK, PROOF_OF_ADDRESS, SELFIE).
        Schema::create('kyc_submission_documents', function (Blueprint $t) {
            $t->foreignUuid('kyc_submission_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $t->string('purpose', 64);
            $t->primary(['kyc_submission_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_submission_documents');
        Schema::dropIfExists('kyc_submissions');
    }
};
