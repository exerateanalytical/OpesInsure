<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | REQ-CLM-005 (agent C6): claim evidence review reasons. claim_documents stays the claim↔document
 | SUBJECT LINK (REQ-DUP-021 — document data lives only in `documents`/`document_versions`); the link
 | gains only the structured reason code of its review (WF-051 accept / WF-052 reject). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_documents', function (Blueprint $t): void {
            $t->string('review_reason_code', 64)->nullable();
            $t->index(['claim_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('claim_documents', function (Blueprint $t): void {
            $t->dropIndex(['claim_id', 'status']);
            $t->dropColumn('review_reason_code');
        });
    }
};
