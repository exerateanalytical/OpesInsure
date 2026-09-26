<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Gap Closure Pack v1 file 07 (Reinsurance & Co-insurance Institutional Master) — additive columns on the
 | Batch 13 canonical tables (no parallel tables):
 |  - reinsurers: reinsurer / reinsurance-broker directory fields + approved-security gate
 |    (PENDING_CIMA_OR_TENANT_APPROVED_SOURCE: nothing is approved until a tenant approver or a CIMA source says so);
 |  - reinsurance_treaties / versions: treaty-master fields not yet held (treaty form, number, territories, wording,
 |    bordereau frequency, premium / profit-commission terms, claims-cooperation and cash-call thresholds);
 |  - coinsurance_arrangements: settlement method and agreement document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reinsurers', function (Blueprint $t) {
            $t->string('regulator', 128)->nullable();
            $t->string('license_reference', 128)->nullable();
            $t->jsonb('ratings')->default('[]');                 // [{agency, rating, as_of}]
            $t->string('approved_security_status', 32)->default('PENDING_VERIFICATION'); // PENDING_VERIFICATION | TENANT_APPROVED | CIMA_APPROVED | REJECTED | SUSPENDED
            $t->uuid('security_approved_by')->nullable();
            $t->timestampTz('security_approved_at')->nullable();
            $t->string('security_approval_reason', 500)->nullable();
            $t->jsonb('contact')->default('{}');
            $t->string('website')->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->string('source_url', 1024)->nullable();
            $t->string('verification_status', 32)->default('PENDING_VERIFICATION');
            $t->string('data_source', 64)->nullable();           // MANUAL | IMPORT:<batch> | GAP_CLOSURE_PACK_V1
        });

        Schema::table('reinsurance_treaties', function (Blueprint $t) {
            $t->string('treaty_form', 40)->nullable();          // Gap Closure treaty_types code (PER_RISK_EXCESS_OF_LOSS …); treaty_type keeps the engine family
            $t->string('treaty_number', 64)->nullable();
            $t->uuid('cedant_party_id')->nullable();
            $t->jsonb('territories')->default('[]');
            $t->string('bordereau_frequency', 16)->nullable();   // MONTHLY | QUARTERLY | SEMI_ANNUAL | ANNUAL
            $t->uuid('wording_document_id')->nullable();
        });

        Schema::table('reinsurance_treaty_versions', function (Blueprint $t) {
            $t->jsonb('premium_terms')->nullable();
            $t->jsonb('profit_commission')->nullable();
            $t->bigInteger('claims_cooperation_threshold_minor')->nullable();
            $t->bigInteger('cash_call_threshold_minor')->nullable();
        });

        Schema::table('coinsurance_arrangements', function (Blueprint $t) {
            $t->string('settlement_method', 32)->nullable();     // tenant-defined code (the pack gives no vocabulary)
            $t->uuid('agreement_document_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coinsurance_arrangements', fn (Blueprint $t) => $t->dropColumn(['settlement_method', 'agreement_document_id']));
        Schema::table('reinsurance_treaty_versions', fn (Blueprint $t) => $t->dropColumn(['premium_terms', 'profit_commission', 'claims_cooperation_threshold_minor', 'cash_call_threshold_minor']));
        Schema::table('reinsurance_treaties', fn (Blueprint $t) => $t->dropColumn(['treaty_form', 'treaty_number', 'cedant_party_id', 'territories', 'bordereau_frequency', 'wording_document_id']));
        Schema::table('reinsurers', fn (Blueprint $t) => $t->dropColumn(['regulator', 'license_reference', 'ratings', 'approved_security_status', 'security_approved_by', 'security_approved_at',
            'security_approval_reason', 'contact', 'website', 'effective_from', 'effective_until', 'source_url', 'verification_status', 'data_source']));
    }
};
