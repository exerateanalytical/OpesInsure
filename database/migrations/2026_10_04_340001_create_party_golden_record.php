<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Batch 4A — party golden record (REQ-PTY-002/003/004). Additive only: extends the canonical `parties` table
 | (no second person/customer table) with merge redirection, and adds bitemporal party_roles, party_relationships,
 | ownership_interests (UBO graph), entity_match_candidates (probable-match review) and party_merges (survivorship
 | log + unmerge). Nothing is ever deleted by a merge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $t) {
            $t->uuid('merged_into_id')->nullable()->index();
            $t->timestampTz('merged_at')->nullable();
        });

        // REQ-PTY-002 — bitemporal: valid_from/valid_to = business time, recorded_at/superseded_at = system time.
        Schema::create('party_roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('party_id')->constrained('parties');
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $t->string('role_code', 40);
            $t->string('context_type', 40)->nullable();   // policy | proposal | quote | claim | null (standing role)
            $t->uuid('context_id')->nullable();
            $t->timestampTz('valid_from');
            $t->timestampTz('valid_to')->nullable();
            $t->timestampTz('recorded_at');
            $t->timestampTz('superseded_at')->nullable();
            $t->uuid('supersedes_id')->nullable();
            $t->string('source', 40)->default('MANUAL');
            $t->jsonb('details')->default('{}');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['party_id', 'role_code']);
            $t->index(['context_type', 'context_id']);
        });

        // REQ-PTY-003 — household / employer / group / corporate relationships.
        Schema::create('party_relationships', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $t->foreignUuid('from_party_id')->constrained('parties');
            $t->foreignUuid('to_party_id')->constrained('parties');
            $t->string('type', 40);
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->jsonb('details')->default('{}');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['from_party_id', 'status']);
            $t->index(['to_party_id', 'status']);
        });

        // REQ-PTY-003 — ownership graph for UBO (ICE E5). owner → owned (owned is an ORGANIZATION).
        Schema::create('ownership_interests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $t->foreignUuid('owner_party_id')->constrained('parties');
            $t->foreignUuid('owned_party_id')->constrained('parties');
            $t->decimal('percentage', 7, 4);
            $t->string('interest_type', 24)->default('SHAREHOLDING');   // SHAREHOLDING | VOTING | CONTROL
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->string('evidence_reference')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['owned_party_id', 'status']);
            $t->index(['owner_party_id', 'status']);
        });

        // REQ-PTY-004 — scored probable matches for human review (never auto-merged).
        Schema::create('entity_match_candidates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('entity_type', 24)->default('party');
            $t->uuid('party_a_id');
            $t->uuid('party_b_id');
            $t->decimal('score', 5, 4);
            $t->string('band', 8);
            $t->jsonb('reasons')->default('[]');
            $t->string('status', 20)->default('OPEN');   // OPEN | DISMISSED | MERGE_REQUESTED | MERGED
            $t->uuid('case_id')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->string('review_note', 500)->nullable();
            $t->timestampsTz();
            $t->unique(['entity_type', 'party_a_id', 'party_b_id']);
            $t->index(['status', 'score']);
        });

        Schema::create('party_merges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('match_candidate_id')->nullable();
            $t->foreignUuid('survivor_party_id')->constrained('parties');
            $t->foreignUuid('merged_party_id')->constrained('parties');
            $t->string('status', 20)->default('PENDING');   // PENDING | MERGED | REJECTED | UNMERGED
            $t->uuid('approval_request_id')->nullable();
            $t->jsonb('survivorship_rules')->default('{}');  // requested field → SURVIVOR | MERGED
            $t->jsonb('survivorship_log')->default('[]');    // applied decisions (BRK-017)
            $t->jsonb('before_snapshot')->default('{}');
            $t->jsonb('moved_rows')->default('{}');
            $t->string('reason', 500)->nullable();
            $t->uuid('requested_by');
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->uuid('unmerged_by')->nullable();
            $t->timestampTz('unmerged_at')->nullable();
            $t->string('unmerge_reason', 500)->nullable();
            $t->timestampsTz();
            $t->index(['merged_party_id', 'status']);
        });

        // Backfill: the policy party is the policyholder (explicit; insured/beneficiary are never inferred — LOCK-006).
        $now = now();
        DB::table('policies')->select(['id', 'tenant_id', 'party_id', 'coverage_starts_at', 'created_at'])->orderBy('id')
            ->chunk(500, function ($rows) use ($now) {
                DB::table('party_roles')->insert($rows->map(fn ($p) => [
                    'id' => (string) Str::uuid(), 'party_id' => $p->party_id, 'tenant_id' => $p->tenant_id, 'role_code' => 'POLICYHOLDER',
                    'context_type' => 'policy', 'context_id' => $p->id, 'valid_from' => $p->coverage_starts_at ?? $p->created_at ?? $now,
                    'recorded_at' => $now, 'source' => 'BACKFILL_POLICIES', 'details' => '{}', 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_merges');
        Schema::dropIfExists('entity_match_candidates');
        Schema::dropIfExists('ownership_interests');
        Schema::dropIfExists('party_relationships');
        Schema::dropIfExists('party_roles');
        Schema::table('parties', fn (Blueprint $t) => $t->dropColumn(['merged_into_id', 'merged_at']));
    }
};
