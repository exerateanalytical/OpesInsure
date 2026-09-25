<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * REQ-KYC-001 / REQ-KYC-002 / REQ-KYC-003 — KYC on the case engine.
 *
 * Additive only. kyc_submissions stays the canonical KYC record (REQ-DUP-007:
 * MobileKycService becomes a thin adapter over App\Application\Kyc\KycService);
 * the review workflow runs as a KYC_REVIEW work case (REQ-CAS-001) linked by case_id.
 *
 *  - kyc_submissions: level, subject kind, risk, maker/checker, expiry, remediation lineage.
 *  - kyc_level_requirements: required documents per level, by document catalogue canonical code.
 *    Seeded rows are a PLATFORM_DEFAULT marked UNVERIFIED: no CIMA/ANIF text in the repository
 *    fixes a per-level document list, so an owner must confirm or replace them (tenant rows override).
 *  - screening_checks: sanctions/PEP screening results (adapter interface, MANUAL mode only; no lists).
 *    Named generically so REQ-AML-001 / REQ-KYC-004 (batch 15A) extends it instead of duplicating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $t) {
            $t->string('subject_kind', 16)->default('INDIVIDUAL');   // INDIVIDUAL | CORPORATE
            $t->string('kyc_level', 16)->nullable();                  // SIMPLIFIED | STANDARD | ENHANCED
            $t->string('level_source', 16)->nullable();               // COMPUTED | OVERRIDE
            $t->jsonb('risk_factors')->default('[]');
            $t->uuid('case_id')->nullable()->index();
            $t->string('screening_status', 24)->default('NOT_SCREENED'); // NOT_SCREENED | CLEAR | POSSIBLE_MATCH | CONFIRMED_MATCH
            $t->string('recommended_outcome', 16)->nullable();
            $t->text('recommendation_rationale')->nullable();
            $t->foreignUuid('recommended_by')->nullable()->constrained('users');
            $t->timestampTz('recommended_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('expires_at')->nullable()->index();
            $t->string('expiry_basis', 32)->nullable();               // DOCUMENT_VALID_UNTIL | REFRESH_POLICY | NONE
            $t->timestampTz('expired_at')->nullable();
            $t->uuid('supersedes_submission_id')->nullable()->index();
            $t->uuid('superseded_by_submission_id')->nullable();
            $t->string('remediation_reason', 500)->nullable();
            $t->unsignedInteger('version')->default(1);
        });

        Schema::create('kyc_level_requirements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained(); // NULL = platform default
            $t->string('subject_kind', 16);
            $t->string('kyc_level', 16);
            $t->string('requirement_code', 64);                        // stable key within (kind, level)
            $t->jsonb('accepted_canonical_codes');                     // any one satisfies (document catalogue canonical codes)
            $t->string('applies_to', 24)->default('SUBJECT');          // SUBJECT | REPRESENTATIVE
            $t->boolean('mandatory')->default(true);
            $t->unsignedSmallInteger('refresh_months')->nullable();    // NULL = UNVERIFIED / owner decision
            $t->string('source', 48);
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
            $t->index(['subject_kind', 'kyc_level', 'status']);
        });

        Schema::create('screening_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('party_id')->constrained();
            $t->string('subject_type', 48);                             // kyc_submission | ...
            $t->uuid('subject_id');
            $t->string('check_type', 16);                               // SANCTIONS | PEP
            $t->string('provider', 48);                                 // MANUAL (no automated list is configured)
            $t->string('status', 24);                                   // PENDING | CLEAR | POSSIBLE_MATCH | CONFIRMED_MATCH
            $t->string('list_reference', 255)->nullable();              // what the reviewer consulted (free text, not invented)
            $t->jsonb('result')->default('{}');
            $t->text('notes')->nullable();
            $t->foreignUuid('checked_by')->nullable()->constrained('users');
            $t->timestampTz('checked_at')->nullable();
            $t->timestampsTz();
            $t->index(['subject_type', 'subject_id']);
            $t->index(['tenant_id', 'party_id']);
        });

        $now = now();
        $src = 'PLATFORM_DEFAULT_UNVERIFIED';
        $id = ['NATIONAL_ID', 'PASSPORT', 'RESIDENCE_PERMIT'];
        $rows = [];
        foreach (['SIMPLIFIED', 'STANDARD', 'ENHANCED'] as $level) {
            $rows[] = ['INDIVIDUAL', $level, 'IDENTITY', $id, 'SUBJECT'];
            if ($level !== 'SIMPLIFIED') {
                $rows[] = ['INDIVIDUAL', $level, 'ADDRESS', ['PROOF_OF_ADDRESS'], 'SUBJECT'];
            }
            $rows[] = ['CORPORATE', $level, 'REGISTRATION', ['BUSINESS_REGISTRATION'], 'SUBJECT'];
            if ($level !== 'SIMPLIFIED') {
                $rows[] = ['CORPORATE', $level, 'TAX_ID', ['TAX_ID_CERTIFICATE'], 'SUBJECT'];
                $rows[] = ['CORPORATE', $level, 'REPRESENTATIVE_IDENTITY', $id, 'REPRESENTATIVE'];
            }
            if ($level === 'ENHANCED') {
                $rows[] = ['INDIVIDUAL', $level, 'TAX_ID', ['TAX_ID_CERTIFICATE'], 'SUBJECT'];
                $rows[] = ['CORPORATE', $level, 'FINANCIAL_STATEMENTS', ['FINANCIAL_STATEMENTS'], 'SUBJECT'];
            }
        }
        foreach ($rows as [$kind, $level, $code, $codes, $applies]) {
            DB::table('kyc_level_requirements')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => null, 'subject_kind' => $kind, 'kyc_level' => $level, 'requirement_code' => $code,
                'accepted_canonical_codes' => json_encode($codes), 'applies_to' => $applies, 'mandatory' => true, 'refresh_months' => null,
                'source' => $src, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('case_types') && ! DB::table('case_types')->where('code', 'KYC_REVIEW')->exists()) {
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => 'KYC_REVIEW', 'version' => 1, 'family_code' => null, 'name' => 'KYC review',
                'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode(\App\Application\Kyc\KycCaseType::states()),
                'transitions' => json_encode(\App\Application\Kyc\KycCaseType::transitions()),
                'sla_policies' => '[]', 'auto_tasks' => '[]', 'default_confidentiality' => 'NORMAL', 'regulated' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('case_types')->where('code', 'KYC_REVIEW')->where('version', 1)->delete();
        Schema::dropIfExists('screening_checks');
        Schema::dropIfExists('kyc_level_requirements');
        Schema::table('kyc_submissions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('recommended_by');
            $t->dropColumn(['subject_kind', 'kyc_level', 'level_source', 'risk_factors', 'case_id', 'screening_status', 'recommended_outcome',
                'recommendation_rationale', 'recommended_at', 'decision_reason', 'approved_at', 'expires_at', 'expiry_basis', 'expired_at',
                'supersedes_submission_id', 'superseded_by_submission_id', 'remediation_reason', 'version']);
        });
    }
};
