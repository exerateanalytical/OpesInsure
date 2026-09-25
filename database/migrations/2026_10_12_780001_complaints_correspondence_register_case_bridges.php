<?php

declare(strict_types=1);

use App\Application\Complaints\ComplaintLifecycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Batch 12C — REQ-CPL-001 / REQ-COR-001 / REQ-CAS-002 / REQ-DUP-022.
 *
 *  correspondence_register  ICE gap 23: one registry of inbound/outbound correspondence with proof of dispatch,
 *                           linked to a case and, optionally, to a notification delivery or communication log.
 *  complaints               complaint facts on top of a COMPLAINT case (the case engine owns state, SLA, owner).
 *                           A complaint that came in as a support ticket keeps the ticket as a projection only.
 *  case_types COMPLAINT v2  REQ-CPL-001 lifecycle (Submitted → Acknowledged → Classified → Assigned →
 *                           Investigating → Resolution proposed → Communicated → Closed / Escalated).
 *                           v1 is SUPERSEDED, never deleted: cases opened on v1 keep their pinned version (INV-6.2).
 *                           sla_policies stay empty: complaint deadlines are configurable (owner decision) and
 *                           come from sla_policy_overrides; no REGULATORY_DEADLINE without a legal basis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correspondence_register', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('reference_number', 40)->unique();
            $t->foreignUuid('case_id')->nullable()->constrained('cases');
            $t->string('subject_type', 64)->nullable();
            $t->uuid('subject_id')->nullable();
            $t->string('direction', 8);
            $t->string('channel', 16);
            $t->string('counterparty_type', 24);
            $t->foreignUuid('counterparty_party_id')->nullable()->constrained('parties');
            $t->string('counterparty_name', 200);
            $t->string('counterparty_contact', 255)->nullable();
            $t->string('external_reference', 120)->nullable();
            $t->string('subject_line', 255);
            $t->text('summary')->nullable();
            $t->uuid('document_id')->nullable();
            $t->foreignUuid('notification_delivery_id')->nullable()->constrained('notification_deliveries');
            $t->foreignUuid('communication_log_id')->nullable()->constrained('communication_logs');
            $t->string('status', 16);
            $t->timestampTz('received_at')->nullable();
            $t->timestampTz('dispatched_at')->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->string('proof_type', 32)->nullable();
            $t->string('proof_reference', 160)->nullable();
            $t->uuid('proof_document_id')->nullable();
            $t->foreignUuid('proof_recorded_by')->nullable()->constrained('users');
            $t->text('failure_reason')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->string('idempotency_key', 100)->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'case_id']);
            $t->index(['tenant_id', 'subject_type', 'subject_id']);
        });
        DB::statement("ALTER TABLE correspondence_register ADD CONSTRAINT correspondence_direction_chk CHECK (direction IN ('INBOUND','OUTBOUND'))");
        DB::statement("ALTER TABLE correspondence_register ADD CONSTRAINT correspondence_channel_chk CHECK (channel IN ('EMAIL','SMS','WHATSAPP','LETTER','COURIER','PORTAL','PHONE','IN_PERSON'))");
        DB::statement("ALTER TABLE correspondence_register ADD CONSTRAINT correspondence_status_chk CHECK (status IN ('RECEIVED','DRAFT','DISPATCHED','DELIVERED','FAILED'))");
        // Inbound rows are RECEIVED with a received_at; outbound rows never are.
        DB::statement("ALTER TABLE correspondence_register ADD CONSTRAINT correspondence_inbound_chk CHECK ((direction = 'INBOUND') = (status = 'RECEIVED') AND (direction <> 'INBOUND' OR received_at IS NOT NULL))");
        // Proof of dispatch: a DISPATCHED/DELIVERED row carries its dispatch time and a proof.
        DB::statement("ALTER TABLE correspondence_register ADD CONSTRAINT correspondence_proof_chk CHECK (status NOT IN ('DISPATCHED','DELIVERED') OR (dispatched_at IS NOT NULL AND proof_type IS NOT NULL AND (proof_reference IS NOT NULL OR proof_document_id IS NOT NULL)))");
        DB::statement("ALTER TABLE correspondence_register ADD CONSTRAINT correspondence_delivered_chk CHECK (status <> 'DELIVERED' OR delivered_at IS NOT NULL)");

        Schema::create('complaints', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('case_id')->unique()->constrained('cases');
            $t->string('complaint_number', 40)->unique();
            $t->foreignUuid('party_id')->nullable()->constrained('parties');
            $t->string('complainant_name', 200);
            $t->string('complainant_contact', 255)->nullable();
            $t->string('channel', 16);
            $t->string('subject_type', 64)->nullable();
            $t->uuid('subject_id')->nullable();
            $t->text('description');
            $t->string('category', 64)->nullable();
            $t->string('severity', 16)->nullable();
            $t->boolean('regulatory')->default(false);
            $t->foreignUuid('support_ticket_id')->nullable()->unique()->constrained('support_tickets');
            $t->timestampTz('received_at');
            $t->timestampTz('acknowledged_at')->nullable();
            $t->timestampTz('classified_at')->nullable();
            $t->string('root_cause', 64)->nullable();
            $t->string('outcome', 24)->nullable();
            $t->text('resolution_summary')->nullable();
            $t->decimal('redress_amount', 18, 2)->nullable();
            $t->foreignUuid('response_correspondence_id')->nullable()->constrained('correspondence_register');
            $t->timestampTz('communicated_at')->nullable();
            $t->string('escalation_level', 16)->nullable();
            $t->string('escalation_reference', 120)->nullable();
            $t->timestampTz('escalated_at')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->string('idempotency_key', 100)->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'category', 'severity']);
        });
        DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_severity_chk CHECK (severity IS NULL OR severity IN ('LOW','MEDIUM','HIGH','CRITICAL'))");
        DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_outcome_chk CHECK (outcome IS NULL OR outcome IN ('UPHELD','PARTIALLY_UPHELD','NOT_UPHELD'))");
        DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_escalation_chk CHECK (escalation_level IS NULL OR escalation_level IN ('NATIONAL','CIMA'))");
        DB::statement('ALTER TABLE complaints ADD CONSTRAINT complaints_communicated_chk CHECK (communicated_at IS NULL OR response_correspondence_id IS NOT NULL)');

        // COMPLAINT v2 — only when v1 exists (fresh databases seed v1 in 2026_10_01_210001).
        $current = DB::table('case_types')->where('code', 'COMPLAINT')->orderByDesc('version')->first();
        if ($current && ! DB::table('case_types')->where('code', 'COMPLAINT')->where('transitions', 'like', '%complaint.response_dispatched%')->exists()) {
            $def = ComplaintLifecycle::definition();
            DB::table('case_types')->where('code', 'COMPLAINT')->where('status', 'EFFECTIVE')
                ->update(['status' => 'SUPERSEDED', 'valid_to' => now()->toDateString(), 'updated_at' => now()]);
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => 'COMPLAINT', 'version' => $current->version + 1, 'family_code' => $current->family_code,
                'name' => $current->name, 'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                'sla_policies' => $current->sla_policies, 'auto_tasks' => '[]', 'subtypes' => json_encode(ComplaintLifecycle::SUBTYPES),
                'default_confidentiality' => $current->default_confidentiality, 'regulated' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $v2 = DB::table('case_types')->where('code', 'COMPLAINT')->where('transitions', 'like', '%complaint.response_dispatched%')->pluck('id');
        if ($v2->isNotEmpty() && ! DB::table('cases')->whereIn('case_type_id', $v2)->exists()) {
            DB::table('case_types')->whereIn('id', $v2)->delete();
            $prev = DB::table('case_types')->where('code', 'COMPLAINT')->orderByDesc('version')->value('id');
            DB::table('case_types')->where('id', $prev)->update(['status' => 'EFFECTIVE', 'valid_to' => null]);
        }
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('correspondence_register');
    }
};
