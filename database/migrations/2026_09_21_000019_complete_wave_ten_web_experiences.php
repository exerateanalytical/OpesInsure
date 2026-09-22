<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('portal_workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('user_id')->index();
            $table->string('portal', 32);
            $table->string('locale', 5)->default('en');
            $table->jsonb('preferences')->default('{}');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'tenant_id', 'portal']);
        });

        Schema::create('marketplace_publications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('product_id')->index();
            $table->uuid('tariff_version_id')->nullable()->index();
            $table->string('status', 24)->default('DRAFT')->index();
            $table->jsonb('channels')->default('["WEB"]');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'product_id', 'status']);
        });

        Schema::create('saved_comparisons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('user_id')->index();
            $table->uuid('quote_request_id')->index();
            $table->jsonb('selected_offer_ids')->default('[]');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['user_id', 'quote_request_id']);
        });

        Schema::create('dashboard_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('portal', 32)->index();
            $table->string('period_key', 32);
            $table->jsonb('metrics');
            $table->char('integrity_hash', 64);
            $table->timestampTz('generated_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'portal', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_snapshots');
        Schema::dropIfExists('saved_comparisons');
        Schema::dropIfExists('marketplace_publications');
        Schema::dropIfExists('portal_workspaces');
    }
};
