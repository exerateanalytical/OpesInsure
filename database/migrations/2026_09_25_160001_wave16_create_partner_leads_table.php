<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 16 — agent leads: a prospect an agent is working before the person
 * becomes a consented, origin-locked client. Deliberately holds no consent
 * or attribution: converting a lead goes through AgentClientIntakeService,
 * which captures both. Scoped by tenant + partner (the agent's Partner).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_leads', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUuid('partner_id')->constrained('partners')->cascadeOnDelete();
            $t->string('full_name', 160);
            $t->string('phone_e164', 32);
            $t->string('city', 80)->nullable();
            $t->string('product_interest', 32)->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 16)->default('NEW');
            $t->foreignUuid('converted_customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
            $t->timestampTz('converted_at')->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestampsTz();
            $t->index(['tenant_id', 'partner_id', 'status']);
        });

        DB::statement("ALTER TABLE partner_leads ADD CONSTRAINT partner_lead_status_allowed CHECK (status IN ('NEW','CONTACTED','QUALIFIED','CONVERTED','LOST'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_leads');
    }
};
