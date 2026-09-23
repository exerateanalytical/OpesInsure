<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the generic offline-queue replay endpoint (POST /mobile/sync/operations
 * — see App\Application\Sync\SyncOperationDispatchService), the Laravel side of
 * the "Laravel synchronization contract" described in the mobile app's Patch 6
 * (Resilience & Inclusion) merge guide. The Offline Sync batch (idempotency
 * guard / resumable uploads) explicitly deferred this endpoint to Agent Mode's
 * offline-queue batch; this table is this batch's own addition.
 *
 * Rows are keyed by the CLIENT-generated operation id (operation_uuid),
 * scoped to (tenant_id, user_id) — not a bare global primary key on that id —
 * so two different tenants/users independently choosing the same UUID (astronomically
 * unlikely, but not a security boundary worth relying on) can never collide.
 * A repeat POST with the same operation_uuid returns the stored outcome
 * without re-executing the underlying handler (see dispatch()); this is the
 * "use the operation UUID ... to prevent double creation" requirement,
 * layered underneath (not instead of) the generic IdempotencyGuard's own
 * Idempotency-Key header replay at the HTTP layer.
 *
 * Also serves as the backing store for GET /mobile/agent/offline-queue and
 * POST /mobile/agent/offline-queue/{id}/retry — the agent app's own view of
 * what it queued while offline and whether it was ultimately applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('user_id')->constrained();
            $t->uuid('operation_uuid');
            $t->string('kind', 16);
            $t->string('resource', 64);
            $t->uuid('resource_id')->nullable();
            $t->string('method', 8);
            $t->string('path', 190);
            $t->string('status', 16);
            $t->string('server_version', 64)->nullable();
            $t->jsonb('payload')->nullable();
            $t->jsonb('response_body')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->unsignedInteger('attempt_count')->default(1);
            $t->timestampTz('synchronized_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'user_id', 'operation_uuid']);
            $t->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
    }
};
