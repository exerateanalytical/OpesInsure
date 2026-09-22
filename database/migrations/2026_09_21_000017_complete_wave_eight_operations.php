<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // couriers, fulfilment_orders, fulfilment_events, notification_templates,
        // communication_preferences, notification_deliveries, support_tickets and
        // support_ticket_events already exist from an earlier provisional wave in
        // this checkpoint. Reconcile by adding only what Wave 8 needs on top of
        // them instead of recreating (which would fail and would drop existing
        // foreign keys pointing at them).
        if (!Schema::hasTable('couriers')) {
            Schema::create('couriers', function (Blueprint $t): void {
                $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('name'); $t->string('phone_hash', 64); $t->string('vehicle_reference')->nullable();
                $t->string('status', 20)->default('ACTIVE'); $t->timestampsTz(); $t->index(['tenant_id','status']);
            });
        } else {
            Schema::table('couriers', function (Blueprint $t): void {
                if (!Schema::hasColumn('couriers', 'phone_hash')) $t->string('phone_hash', 64)->nullable()->after('name');
                if (!Schema::hasColumn('couriers', 'vehicle_reference')) $t->string('vehicle_reference')->nullable()->after('phone_hash');
            });
            DB::statement('CREATE INDEX IF NOT EXISTS couriers_tenant_id_status_index ON couriers (tenant_id, status)');
        }

        if (!Schema::hasTable('fulfilment_orders')) {
            Schema::create('fulfilment_orders', function (Blueprint $t): void {
                $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignUuid('policy_id')->constrained()->cascadeOnDelete(); $t->foreignUuid('courier_id')->nullable()->constrained()->nullOnDelete();
                $t->string('status', 24)->default('CREATED'); $t->string('tracking_number')->nullable()->unique();
                $t->jsonb('delivery_address'); $t->string('delivery_otp_hash'); $t->unsignedSmallInteger('delivery_attempts')->default(0);
                $t->timestampTz('sla_due_at'); $t->timestampTz('delivered_at')->nullable(); $t->jsonb('proof_of_delivery')->nullable();
                $t->string('idempotency_key', 100); $t->timestampsTz(); $t->unique(['tenant_id','idempotency_key']); $t->index(['tenant_id','status','sla_due_at']);
            });
        } else {
            Schema::table('fulfilment_orders', function (Blueprint $t): void {
                if (!Schema::hasColumn('fulfilment_orders', 'idempotency_key')) $t->string('idempotency_key', 100)->nullable()->after('proof_of_delivery');
            });
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS fulfilment_orders_tenant_id_idempotency_key_unique ON fulfilment_orders (tenant_id, idempotency_key)');
            DB::statement('CREATE INDEX IF NOT EXISTS fulfilment_orders_tenant_id_status_sla_due_at_index ON fulfilment_orders (tenant_id, status, sla_due_at)');
        }

        if (!Schema::hasTable('fulfilment_events')) {
            Schema::create('fulfilment_events', function (Blueprint $t): void {
                $t->uuid('id')->primary(); $t->foreignUuid('fulfilment_order_id')->constrained()->cascadeOnDelete();
                $t->string('from_status',24)->nullable(); $t->string('to_status',24); $t->string('event_type',32);
                $t->foreignUuid('courier_id')->nullable()->constrained()->nullOnDelete(); $t->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $t->decimal('latitude',10,7)->nullable(); $t->decimal('longitude',10,7)->nullable(); $t->jsonb('evidence')->default('{}'); $t->timestampTz('occurred_at');
            });
        }

        if (!Schema::hasTable('notification_templates')) {
            Schema::create('notification_templates', function (Blueprint $t): void {
                $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
                $t->string('code',80); $t->string('locale',5); $t->string('purpose',24); $t->string('channel',16);
                $t->string('subject')->nullable(); $t->text('body'); $t->jsonb('required_variables')->default('[]'); $t->unsignedInteger('version');
                $t->string('status',16)->default('DRAFT'); $t->foreignUuid('created_by')->constrained('users'); $t->foreignUuid('approved_by')->nullable()->constrained('users');
                $t->timestampTz('approved_at')->nullable(); $t->timestampsTz(); $t->unique(['tenant_id','code','locale','channel','version']);
            });
        } else {
            Schema::table('notification_templates', function (Blueprint $t): void {
                if (!Schema::hasColumn('notification_templates', 'tenant_id')) $t->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete()->after('id');
                if (!Schema::hasColumn('notification_templates', 'created_by')) $t->foreignUuid('created_by')->nullable()->constrained('users')->after('required_variables');
                if (!Schema::hasColumn('notification_templates', 'approved_by')) $t->foreignUuid('approved_by')->nullable()->constrained('users')->after('created_by');
                if (!Schema::hasColumn('notification_templates', 'approved_at')) $t->timestampTz('approved_at')->nullable()->after('approved_by');
            });
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS notification_templates_tenant_id_code_locale_channel_version_u ON notification_templates (tenant_id, code, locale, channel, version)');
        }

        if (!Schema::hasTable('communication_preferences')) {
            Schema::create('communication_preferences', function (Blueprint $t): void {
                $t->uuid('id')->primary(); $t->foreignUuid('party_id')->constrained()->cascadeOnDelete(); $t->string('purpose',24); $t->string('channel',16);
                $t->boolean('enabled'); $t->string('source',24); $t->timestampTz('changed_at'); $t->timestampsTz(); $t->unique(['party_id','purpose','channel']);
            });
        }

        if (!Schema::hasTable('notification_deliveries')) {
            Schema::create('notification_deliveries', function (Blueprint $t): void {
                $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUuid('party_id')->constrained()->cascadeOnDelete();
                $t->foreignUuid('template_id')->constrained('notification_templates'); $t->string('channel',16); $t->string('destination_hash',64); $t->string('status',20)->default('QUEUED');
                $t->unsignedSmallInteger('attempts')->default(0); $t->unsignedSmallInteger('max_attempts')->default(5); $t->jsonb('payload');
                $t->string('provider_reference')->nullable(); $t->string('failure_code')->nullable(); $t->timestampTz('next_attempt_at')->nullable(); $t->timestampTz('sent_at')->nullable();
                $t->string('idempotency_key',100); $t->timestampsTz(); $t->unique(['tenant_id','idempotency_key']); $t->index(['status','next_attempt_at']);
            });
        } else {
            Schema::table('notification_deliveries', function (Blueprint $t): void {
                if (!Schema::hasColumn('notification_deliveries', 'max_attempts')) $t->unsignedSmallInteger('max_attempts')->default(5)->after('attempts');
                if (!Schema::hasColumn('notification_deliveries', 'failure_code')) $t->string('failure_code')->nullable()->after('provider_reference');
                if (!Schema::hasColumn('notification_deliveries', 'idempotency_key')) $t->string('idempotency_key', 100)->nullable()->after('failure_reason');
            });
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS notification_deliveries_tenant_id_idempotency_key_unique ON notification_deliveries (tenant_id, idempotency_key)');
            DB::statement('CREATE INDEX IF NOT EXISTS notification_deliveries_status_next_attempt_at_index ON notification_deliveries (status, next_attempt_at)');
        }

        Schema::create('notification_attempts', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->foreignUuid('notification_delivery_id')->constrained()->cascadeOnDelete(); $t->unsignedSmallInteger('attempt_number');
            $t->string('status',20); $t->string('provider_reference')->nullable(); $t->string('failure_code')->nullable(); $t->jsonb('response')->default('{}'); $t->timestampTz('attempted_at');
            $t->unique(['notification_delivery_id','attempt_number']);
        });

        if (!Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $t): void {
                $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete(); $t->foreignUuid('party_id')->nullable()->constrained()->nullOnDelete();
                $t->string('ticket_number')->unique(); $t->string('type',28); $t->string('category',64); $t->string('priority',12); $t->string('status',24)->default('OPEN');
                $t->string('subject',200); $t->text('description'); $t->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignUuid('related_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete(); $t->timestampTz('sla_due_at'); $t->timestampTz('acknowledged_at')->nullable();
                $t->timestampTz('resolved_at')->nullable(); $t->timestampTz('closed_at')->nullable(); $t->string('idempotency_key',100); $t->timestampsTz();
                $t->unique(['tenant_id','idempotency_key']); $t->index(['tenant_id','status','priority','sla_due_at']);
            });
        } else {
            Schema::table('support_tickets', function (Blueprint $t): void {
                if (!Schema::hasColumn('support_tickets', 'acknowledged_at')) $t->timestampTz('acknowledged_at')->nullable()->after('sla_due_at');
                if (!Schema::hasColumn('support_tickets', 'idempotency_key')) $t->string('idempotency_key', 100)->nullable()->after('closed_at');
            });
            if (!Schema::hasColumn('support_tickets', 'related_ticket_id')) {
                Schema::table('support_tickets', function (Blueprint $t): void {
                    $t->foreignUuid('related_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete()->after('assigned_to');
                });
            }
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS support_tickets_tenant_id_idempotency_key_unique ON support_tickets (tenant_id, idempotency_key)');
            DB::statement('CREATE INDEX IF NOT EXISTS support_tickets_tenant_id_status_priority_sla_due_at_index ON support_tickets (tenant_id, status, priority, sla_due_at)');
        }

        if (!Schema::hasTable('support_ticket_events')) {
            Schema::create('support_ticket_events', function (Blueprint $t): void {
                $t->uuid('id')->primary(); $t->foreignUuid('support_ticket_id')->constrained()->cascadeOnDelete(); $t->string('type',32);
                $t->string('from_status',24)->nullable(); $t->string('to_status',24)->nullable(); $t->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $t->text('message'); $t->jsonb('metadata')->default('{}'); $t->timestampTz('occurred_at');
            });
        }

        Schema::create('communication_logs', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignUuid('party_id')->nullable()->constrained()->nullOnDelete();
            $t->string('direction',8); $t->string('channel',16); $t->string('purpose',24); $t->string('counterparty_hash',64); $t->text('summary');
            $t->foreignUuid('ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete(); $t->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->jsonb('metadata')->default('{}'); $t->timestampTz('occurred_at'); $t->timestampsTz(); $t->index(['tenant_id','party_id','occurred_at']);
        });

        DB::statement("DO \$\$ BEGIN ALTER TABLE fulfilment_orders ADD CONSTRAINT fulfilment_status_check CHECK (status IN ('CREATED','READY_FOR_PICKUP','ASSIGNED','PICKED_UP','IN_TRANSIT','DELIVERED','FAILED_ATTEMPT','RETURNING','RETURNED','CANCELLED')); EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;");
        DB::statement("DO \$\$ BEGIN ALTER TABLE notification_deliveries ADD CONSTRAINT notification_status_check CHECK (status IN ('QUEUED','SENDING','SENT','DELIVERED','FAILED','DEAD_LETTERED','CANCELLED')); EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;");
    }
    public function down(): void
    {
        foreach (['communication_logs','notification_attempts'] as $table) Schema::dropIfExists($table);
    }
};
