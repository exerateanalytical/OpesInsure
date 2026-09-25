<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institutional directory of the official insurers (additive only).
 *
 * Carriers have no tenant, so tenant_branches cannot hold their offices and the
 * register has no office table: institution_offices is the one table for
 * institutional HEAD_OFFICE / DIRECT_BRANCH offices. Register contacts do not go
 * into party_contacts: that table is globally unique on (type, value) and is the
 * customer identity lookup (PartyService, agent intake), so a shared switchboard
 * would collide or resolve customers to an insurer. The head-office address is
 * stored in party_addresses (type HEAD_OFFICE), the canonical address table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('directory_id', 32)->index();             // e.g. CM-INS-IARD-001
            $t->string('directory_name')->nullable();            // name as published in the directory (display name unchanged)
            $t->string('website')->nullable();
            $t->string('po_box', 64)->nullable();
            $t->jsonb('phones')->default('[]');
            $t->jsonb('emails')->default('[]');
            $t->jsonb('sources')->default('[]');
            $t->jsonb('maps_listing')->nullable();
            $t->string('verification_status', 48)->nullable();  // VERIFIED | PARTIALLY_VERIFIED | VERIFIED_HQ_BRANCHES_PENDING | VERIFIED_NETWORK_SHARED_WITH_GROUP
            $t->date('verified_at')->nullable();
            // Evidence text only; never an authorization (owner rule).
            $t->text('regulatory_reference_note')->nullable();
            $t->string('regulatory_reference_status', 32)->nullable(); // PENDING_EVIDENCE_REVIEW
            $t->string('dataset', 48);
            $t->string('dataset_version', 32);
            // Set when an admin edits the record; the deploy seeder then leaves this insurer untouched.
            $t->timestampTz('admin_edited_at')->nullable();
            $t->foreignUuid('admin_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });

        Schema::create('institution_offices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->constrained()->cascadeOnDelete();
            $t->string('office_type', 24);                       // HEAD_OFFICE | DIRECT_BRANCH
            $t->string('name');
            $t->string('city')->nullable();
            $t->text('address')->nullable();
            $t->string('phone', 40)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->string('source', 48);
            $t->timestampsTz();
            $t->unique(['carrier_id', 'name']);
        });

        // Admin-controlled display labels (EN/FR) for each verification status.
        Schema::create('institution_verification_labels', function (Blueprint $t) {
            $t->string('code', 48)->primary();
            $t->string('label_en', 120);
            $t->string('label_fr', 120);
            $t->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_verification_labels');
        Schema::dropIfExists('institution_offices');
        Schema::dropIfExists('institution_profiles');
    }
};
