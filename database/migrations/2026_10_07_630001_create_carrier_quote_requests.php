<?php

declare(strict_types=1);

use App\Application\Cases\CaseTypeCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * REQ-QUO-006 manual quotation (AOM Mode 1).
 *
 *  carrier_quote_requests   one request per (quote, carrier, product) sent to an insurer whose QUOTATION
 *                           capability is MANUAL; worked as a CARRIER_QUOTE_REQUEST case (case engine, SLA clocks).
 *  carrier_quote_responses  append-only answers (OFFER / DECLINE) entered by insurer staff via portal or API, or by
 *                           a broker on the insurer's behalf with evidence.
 *  quote_offers             a manual offer has no OPES tariff, so tariff_version_id becomes nullable; `origin` and
 *                           `carrier_quote_response_id` say where the offer came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_quote_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('quote_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('carrier_id')->constrained();
            $t->foreignUuid('product_id')->nullable()->constrained('insurance_products');
            $t->uuid('case_id')->nullable()->index();
            $t->string('request_number', 40)->unique();
            $t->string('status', 24);
            $t->string('channel', 24)->default('PLATFORM');
            $t->jsonb('risk_snapshot')->default('{}');
            $t->text('notes')->nullable();
            $t->timestampTz('requested_at');
            $t->foreignUuid('requested_by')->nullable()->constrained('users');
            $t->timestampTz('response_due_at')->nullable();
            $t->timestampTz('responded_at')->nullable();
            $t->foreignUuid('quote_offer_id')->nullable()->constrained('quote_offers');
            $t->string('decline_reason_code', 64)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->index(['carrier_id', 'status']);
        });
        DB::statement("ALTER TABLE carrier_quote_requests ADD CONSTRAINT carrier_quote_requests_status_allowed CHECK (status IN ('REQUESTED','IN_PROGRESS','OFFERED','DECLINED','CANCELLED','EXPIRED'))");
        DB::statement("ALTER TABLE carrier_quote_requests ADD CONSTRAINT carrier_quote_requests_channel_allowed CHECK (channel IN ('PLATFORM','EMAIL','PHONE','PAPER'))");
        // One open request per quote/carrier/product (a declined or cancelled one may be re-sent).
        DB::statement("CREATE UNIQUE INDEX carrier_quote_requests_one_open ON carrier_quote_requests (quote_id, carrier_id, COALESCE(product_id, '00000000-0000-0000-0000-000000000000'::uuid)) WHERE status IN ('REQUESTED','IN_PROGRESS')");

        Schema::create('carrier_quote_responses', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_quote_request_id')->constrained()->cascadeOnDelete();
            $t->string('response_type', 16);
            $t->string('source', 24);
            $t->foreignUuid('product_id')->nullable()->constrained('insurance_products');
            $t->bigInteger('premium_minor')->nullable();
            $t->bigInteger('tax_minor')->nullable();
            $t->bigInteger('fee_minor')->nullable();
            $t->bigInteger('total_minor')->nullable();
            $t->string('currency', 3)->nullable();
            $t->jsonb('premium_breakdown')->default('[]');
            $t->jsonb('conditions')->default('[]');
            $t->jsonb('document_ids')->default('[]');
            $t->timestampTz('valid_until')->nullable();
            $t->string('carrier_reference', 120)->nullable();
            $t->string('decline_reason_code', 64)->nullable();
            $t->text('notes')->nullable();
            $t->uuid('evidence_document_id')->nullable();
            $t->foreignUuid('responded_by')->constrained('users');
            $t->timestampTz('responded_at');
            $t->timestampTz('created_at');
        });
        DB::statement("ALTER TABLE carrier_quote_responses ADD CONSTRAINT carrier_quote_responses_type_allowed CHECK (response_type IN ('OFFER','DECLINE'))");
        DB::statement("ALTER TABLE carrier_quote_responses ADD CONSTRAINT carrier_quote_responses_source_allowed CHECK (source IN ('INSURER_PORTAL','INSURER_API','BROKER_ON_BEHALF'))");
        DB::statement("ALTER TABLE carrier_quote_responses ADD CONSTRAINT carrier_quote_responses_offer_complete CHECK (response_type <> 'OFFER' OR (premium_minor >= 0 AND total_minor >= premium_minor AND currency IS NOT NULL AND valid_until IS NOT NULL AND product_id IS NOT NULL))");
        DB::statement("ALTER TABLE carrier_quote_responses ADD CONSTRAINT carrier_quote_responses_on_behalf_evidence CHECK (source <> 'BROKER_ON_BEHALF' OR evidence_document_id IS NOT NULL)");

        DB::statement('ALTER TABLE quote_offers ALTER COLUMN tariff_version_id DROP NOT NULL');
        Schema::table('quote_offers', function (Blueprint $t): void {
            $t->string('origin', 16)->default('RATED');
            $t->foreignUuid('carrier_quote_response_id')->nullable()->constrained('carrier_quote_responses');
        });
        DB::statement("ALTER TABLE quote_offers ADD CONSTRAINT quote_offers_origin_allowed CHECK (origin IN ('RATED','MANUAL'))");
        DB::statement("ALTER TABLE quote_offers ADD CONSTRAINT quote_offers_tariff_or_manual CHECK (tariff_version_id IS NOT NULL OR (origin = 'MANUAL' AND carrier_quote_response_id IS NOT NULL))");

        // Case type v1: generic lifecycle.
        if (! DB::table('case_types')->where('code', 'CARRIER_QUOTE_REQUEST')->exists()) {
            $def = CaseTypeCatalogue::genericLifecycle(false);
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => 'CARRIER_QUOTE_REQUEST', 'version' => 1, 'family_code' => null,
                'name' => 'Carrier manual quotation request', 'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                // No invented SLA targets (UNVERIFIED): admins set FIRST_RESPONSE / RESOLUTION by versioning the type.
                'sla_policies' => '[]',
                'auto_tasks' => '[]', 'default_confidentiality' => 'NORMAL', 'regulated' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE quote_offers DROP CONSTRAINT IF EXISTS quote_offers_tariff_or_manual');
        DB::statement('ALTER TABLE quote_offers DROP CONSTRAINT IF EXISTS quote_offers_origin_allowed');
        Schema::table('quote_offers', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('carrier_quote_response_id');
            $t->dropColumn('origin');
        });
        Schema::dropIfExists('carrier_quote_responses');
        Schema::dropIfExists('carrier_quote_requests');
        // Case type reference data is never deleted; tariff_version_id stays nullable (manual offers may exist).
    }
};
