<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-DUP-004: the document catalogue (document_types, document_packs,
 * document_requirement_matrix, product_document_requirements) is the single
 * catalogue of document definitions. document_requirement_versions is kept
 * (rows untouched, still read by the proposal workflow) but marked legacy, with
 * an optional link to the canonical document type. No row is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requirement_versions', function (Blueprint $t) {
            $t->boolean('is_legacy')->default(true);
            $t->string('catalogue_type_id', 64)->nullable();
            $t->string('legacy_note', 255)->nullable();
        });
        DB::table('document_requirement_versions')->update([
            'is_legacy' => true,
            'legacy_note' => 'Superseded by the document catalogue (REQ-DUP-004); kept for proposal history.',
        ]);
        // Best-effort link by code/alias once the catalogue is seeded (codes match canonical_code).
        if (Schema::hasTable('document_types')) {
            DB::statement("UPDATE document_requirement_versions v SET catalogue_type_id = t.type_id FROM document_types t
                WHERE v.catalogue_type_id IS NULL AND (t.canonical_code = upper(v.code) OR jsonb_exists(t.aliases, upper(v.code)))");
        }
    }

    public function down(): void
    {
        Schema::table('document_requirement_versions', fn (Blueprint $t) => $t->dropColumn(['is_legacy', 'catalogue_type_id', 'legacy_note']));
    }
};
