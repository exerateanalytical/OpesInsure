<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Agent C8 — REQ-CLM-007 claim types per product / line.
 *  claim_type_versions   versioned config (platform defaults: tenant_id NULL; insurer overrides: tenant_id set, optionally per product).
 *                        Codes are the workflow claim types of App\Application\Claims\ClaimReferenceCodes::CLAIM_TYPES.
 *                        Reporting deadlines are PLATFORM DEFAULTS an insurer may override — never legal deadlines.
 *  claim_reporting_checks one row per claim: the claim type resolved at FNOL, the reporting-deadline check and the
 *                        late-claim approval (maker-checker, case engine) state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_type_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->uuid('product_id')->nullable();
            $t->string('scope_key', 96); // PLATFORM | TENANT:<id> | TENANT:<id>:PRODUCT:<id>
            $t->string('line_code', 32);
            $t->string('code', 64);
            $t->unsignedInteger('version');
            $t->jsonb('labels'); // {en, fr}
            $t->jsonb('applicable_coverages')->default('[]');
            $t->unsignedInteger('reporting_deadline_days')->nullable(); // null = no reporting deadline check
            $t->string('evidence_pack_code', 96)->nullable(); // document catalogue CLAIM pack (REQ-CLM-005 reads the evidence rules)
            $t->jsonb('required_evidence_codes')->default('[]');
            $t->bigInteger('default_reserve_minor')->nullable();
            $t->string('currency', 3)->nullable();
            $t->boolean('is_default')->default(false); // used when FNOL names no claim type
            $t->string('status', 16)->default('DRAFT'); // DRAFT | ACTIVE | RETIRED
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('source', 24)->default('PLATFORM_DEFAULT'); // PLATFORM_DEFAULT | INSURER_OVERRIDE
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['scope_key', 'line_code', 'code', 'version']);
            $t->index(['line_code', 'code', 'status']);
        });

        Schema::create('claim_reporting_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->unique()->constrained();
            $t->foreignUuid('claim_type_version_id')->nullable()->constrained('claim_type_versions');
            $t->string('claim_type_code', 64)->nullable();
            $t->string('line_code', 32)->nullable();
            $t->timestampTz('loss_occurred_at');
            $t->timestampTz('reported_at');
            $t->unsignedInteger('deadline_days')->nullable();
            $t->timestampTz('deadline_at')->nullable();
            $t->unsignedInteger('days_late')->default(0);
            $t->boolean('late')->default(false);
            // NOT_REQUIRED | PENDING | RECOMMENDED | APPROVED | REJECTED
            $t->string('approval_status', 16)->default('NOT_REQUIRED');
            $t->uuid('case_id')->nullable();
            $t->string('recommendation', 16)->nullable();
            $t->foreignUuid('recommended_by')->nullable()->constrained('users');
            $t->timestampTz('recommended_at')->nullable();
            $t->text('recommendation_rationale')->nullable();
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_rationale')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'late', 'approval_status']);
        });

        $now = now();
        $from = '2026-01-01';
        $row = fn (string $line, string $code, string $en, string $fr, array $cov, ?int $days, string $pack, ?int $reserve, bool $default = false) => [
            'id' => (string) Str::uuid(), 'tenant_id' => null, 'product_id' => null, 'scope_key' => 'PLATFORM', 'line_code' => $line, 'code' => $code, 'version' => 1,
            'labels' => json_encode(['en' => $en, 'fr' => $fr]), 'applicable_coverages' => json_encode($cov), 'reporting_deadline_days' => $days,
            'evidence_pack_code' => $pack, 'required_evidence_codes' => json_encode([]), 'default_reserve_minor' => $reserve, 'currency' => $reserve === null ? null : 'XAF',
            'is_default' => $default, 'status' => 'ACTIVE', 'effective_from' => $from, 'effective_until' => null, 'source' => 'PLATFORM_DEFAULT',
            'created_by' => null, 'approved_by' => null, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
        // Platform defaults only (owner master data: claims.claim_category + document catalogue CLAIM packs). Insurers override per tenant / product.
        DB::table('claim_type_versions')->insert([
            $row('MOTOR', 'MOTOR_DAMAGE', 'Motor own damage', 'Dommages au véhicule', ['OWN_DAMAGE', 'COLLISION', 'GLASS'], 5, 'MOTOR_CLAIM_PACK', 500000, true),
            $row('MOTOR', 'MOTOR_TP_BODILY_INJURY', 'Motor third-party bodily injury', 'RC automobile — dommages corporels', ['TPL', 'TPL_BODILY_INJURY'], 5, 'MOTOR_CLAIM_PACK', 2000000),
            $row('MOTOR', 'MOTOR_TP_PROPERTY_DAMAGE', 'Motor third-party property damage', 'RC automobile — dommages matériels', ['TPL', 'TPL_PROPERTY_DAMAGE'], 5, 'MOTOR_CLAIM_PACK', 750000),
            $row('MOTOR', 'THEFT', 'Vehicle theft', 'Vol du véhicule', ['THEFT'], 2, 'MOTOR_CLAIM_PACK', 1500000),
            $row('HEALTH', 'HEALTH_REIMBURSEMENT', 'Health reimbursement', 'Remboursement de frais médicaux', ['HOSPITALISATION', 'OUTPATIENT', 'PHARMACY'], 30, 'HEALTH_INDIVIDUAL_CLAIM_PACK', 100000, true),
            $row('HEALTH', 'HEALTH_PROVIDER', 'Health provider claim', 'Facture prestataire de santé', ['HOSPITALISATION', 'OUTPATIENT'], 30, 'HEALTH_INDIVIDUAL_CLAIM_PACK', 250000),
            $row('PROPERTY', 'PROPERTY_DAMAGE', 'Property damage', 'Dommages aux biens', ['BUILDING', 'CONTENTS', 'WATER_DAMAGE'], 5, 'PROPERTY_CLAIM_PACK', 1000000, true),
            $row('PROPERTY', 'FIRE', 'Fire', 'Incendie', ['FIRE', 'BUILDING', 'CONTENTS'], 5, 'PROPERTY_CLAIM_PACK', 2000000),
            $row('PROPERTY', 'THEFT', 'Burglary / theft', 'Vol / cambriolage', ['THEFT', 'CONTENTS'], 2, 'PROPERTY_CLAIM_PACK', 500000),
            $row('PROPERTY', 'BUSINESS_INTERRUPTION', 'Business interruption', 'Pertes d\'exploitation', ['BUSINESS_INTERRUPTION'], 10, 'BUSINESS_MULTIRISK_CLAIM_PACK', 2000000),
            $row('LIFE', 'LIFE_DEATH', 'Death benefit', 'Capital décès', ['DEATH'], 90, 'LIFE_DEATH_CLAIM', null, true),
            $row('LIFE', 'LIFE_DISABILITY', 'Disability benefit', 'Invalidité', ['DISABILITY', 'PTD'], 90, 'LIFE_CLAIM_PACK', null),
            $row('TRAVEL', 'TRAVEL_MEDICAL', 'Travel medical expenses', 'Frais médicaux à l\'étranger', ['MEDICAL_EXPENSES', 'ASSISTANCE'], 30, 'TRAVEL_CLAIM_PACK', 300000, true),
            $row('TRAVEL', 'TRAVEL_CANCELLATION', 'Trip cancellation', 'Annulation de voyage', ['CANCELLATION', 'BAGGAGE'], 30, 'TRAVEL_CLAIM_PACK', 200000),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_reporting_checks');
        Schema::dropIfExists('claim_type_versions');
    }
};
