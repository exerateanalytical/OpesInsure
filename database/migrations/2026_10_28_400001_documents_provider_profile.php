<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D4 (document security completion): provider-flow documents (eligibility confirmation, preauthorization
 * request, EOB, provider settlement statement, provider contract, tariff schedule) are addressed to a
 * provider. The provider is recorded on the issued document so the provider portal can list and download
 * exactly its own documents. Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'provider_profile_id')) {
            Schema::table('documents', function (Blueprint $t) {
                $t->uuid('provider_profile_id')->nullable();
                $t->index(['provider_profile_id', 'document_type_code'], 'documents_provider_type_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'provider_profile_id')) {
            Schema::table('documents', function (Blueprint $t) {
                $t->dropIndex('documents_provider_type_idx');
                $t->dropColumn('provider_profile_id');
            });
        }
    }
};
