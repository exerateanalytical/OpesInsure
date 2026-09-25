<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document letterheads (logo, optional header image, brand colour, legal footer lines) per insurer
 * (carrier, edited from the Institutional directory) and per organisation (tenant, edited from the
 * organisation screen). Additive only. Each upload is a new immutable version: issued documents
 * snapshot the version and file hashes, so replacing a logo never changes an issued original.
 *
 * Owner rule: only licensed/authorized logos. Every version carries who authorized it, when and the
 * source; nothing is scraped or seeded. Every field is nullable except the owner and the authorization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letterhead_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('owner_type', 16);                                   // CARRIER | TENANT
            $t->foreignUuid('carrier_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 24);                                       // PENDING_APPROVAL | ACTIVE | SUPERSEDED | REJECTED
            $t->string('logo_path')->nullable();
            $t->string('logo_mime', 48)->nullable();
            $t->string('logo_sha256', 64)->nullable();
            $t->unsignedInteger('logo_width')->nullable();
            $t->unsignedInteger('logo_height')->nullable();
            $t->string('header_path')->nullable();
            $t->string('header_mime', 48)->nullable();
            $t->string('header_sha256', 64)->nullable();
            $t->string('brand_color', 7)->nullable();                       // #RRGGBB
            $t->text('registered_address')->nullable();
            $t->string('rccm', 64)->nullable();
            $t->string('niu', 64)->nullable();
            $t->string('licence_reference', 120)->nullable();
            $t->boolean('public_display')->default(false);
            // Licence / authorization of the artwork (required).
            $t->string('authorized_by', 190);
            $t->date('authorized_on');
            $t->string('authorization_source', 255);
            $t->text('authorization_note')->nullable();
            $t->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_type', 'carrier_id', 'status']);
            $t->index(['owner_type', 'tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE letterhead_assets ADD CONSTRAINT letterhead_assets_owner_check CHECK ((owner_type = 'CARRIER' AND carrier_id IS NOT NULL AND tenant_id IS NULL) OR (owner_type = 'TENANT' AND tenant_id IS NOT NULL AND carrier_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX letterhead_assets_owner_version ON letterhead_assets (owner_type, COALESCE(carrier_id, tenant_id), version)");
    }

    public function down(): void
    {
        Schema::dropIfExists('letterhead_assets');
    }
};
