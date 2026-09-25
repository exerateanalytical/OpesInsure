<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Agent E8 — REQ-AML-001 / REQ-KYC-004 list screening (App\Application\Compliance\Aml\Screening).
 |  - screening_list_sources: tenant list sources (PEP / SANCTIONS / WATCHLIST). No list data is seeded.
 |  - screening_list_versions: versioned imports (CSV / JSON), maker-checker approval; one ACTIVE version per source.
 |  - screening_list_entries: entries of a version with normalised names (fuzzy matching).
 |  - screening_hits: explainable matches of a party against an entry; disposition with maker-checker.
 | screening_checks (existing, REQ-KYC-001) records each list screening run with subject_type "party", provider "LIST_MATCH".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_list_sources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 64);
            $t->string('name', 160);
            $t->string('list_type', 16);                  // PEP | SANCTIONS | WATCHLIST
            $t->string('publisher', 160)->nullable();     // publisher as provided (free text)
            $t->string('status', 16)->default('ACTIVE'); // ACTIVE | INACTIVE
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('screening_list_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('source_id')->constrained('screening_list_sources');
            $t->unsignedInteger('version');
            $t->string('status', 20)->default('PENDING_APPROVAL'); // PENDING_APPROVAL | ACTIVE | SUPERSEDED | REJECTED
            $t->string('format', 8);                               // CSV | JSON
            $t->string('source_reference', 255)->nullable();
            $t->string('content_sha256', 64);
            $t->unsignedInteger('entry_count')->default(0);
            $t->uuid('imported_by')->nullable();
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestampTz('activated_at')->nullable();
            $t->timestampsTz();
            $t->unique(['source_id', 'version']);
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('screening_list_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('version_id')->constrained('screening_list_versions')->cascadeOnDelete();
            $t->string('entry_ref', 128);
            $t->string('entry_type', 16);
            $t->string('name', 255);
            $t->jsonb('aliases')->default('[]');
            $t->jsonb('normalized_names')->default('[]');
            $t->date('date_of_birth')->nullable();
            $t->string('country', 2)->nullable();
            $t->jsonb('attributes')->default('{}');
            $t->string('entry_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['version_id', 'entry_ref']);
        });

        Schema::create('screening_hits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('party_id')->constrained();
            $t->uuid('screening_check_id')->nullable();
            $t->foreignUuid('source_id')->constrained('screening_list_sources');
            $t->foreignUuid('version_id')->constrained('screening_list_versions');
            $t->foreignUuid('entry_id')->constrained('screening_list_entries');
            $t->string('entry_ref', 128);
            $t->string('entry_hash', 64);
            $t->string('list_type', 16);
            $t->string('matched_name', 255);
            $t->string('party_name', 255);
            $t->decimal('score', 5, 4);
            $t->jsonb('explanation')->default('{}');
            $t->string('status', 16)->default('OPEN');           // OPEN | PROPOSED | DISPOSED
            $t->string('proposed_disposition', 16)->nullable();  // FALSE_POSITIVE | TRUE_MATCH | ESCALATED
            $t->text('proposed_rationale')->nullable();
            $t->uuid('proposed_by')->nullable();
            $t->timestampTz('proposed_at')->nullable();
            $t->string('disposition', 16)->nullable();
            $t->text('disposition_note')->nullable();
            $t->uuid('disposed_by')->nullable();
            $t->timestampTz('disposed_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'party_id', 'status']);
            $t->index(['tenant_id', 'party_id', 'source_id', 'entry_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_hits');
        Schema::dropIfExists('screening_list_entries');
        Schema::dropIfExists('screening_list_versions');
        Schema::dropIfExists('screening_list_sources');
    }
};
