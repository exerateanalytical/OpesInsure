<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent B6 — REQ-OPS-001 scheduler heartbeat. REQ-OPS-002 automated restore verifications are recorded in the existing
 * recovery_exercises register, whose RPO/RTO targets become nullable: no target is invented when the owner has not set one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_scheduler_heartbeats', function (Blueprint $t): void {
            $t->string('name', 64)->primary();
            $t->string('host', 255)->nullable();
            $t->timestampTz('last_beat_at');
            $t->unsignedBigInteger('beats')->default(0);
        });
        DB::statement('ALTER TABLE recovery_exercises ALTER COLUMN target_rto_minutes DROP NOT NULL');
        DB::statement('ALTER TABLE recovery_exercises ALTER COLUMN target_rpo_minutes DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_scheduler_heartbeats');
    }
};
