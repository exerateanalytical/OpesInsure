<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S13 — REQ-SEED-001 provenance (data_origin, is_demo) on the tables that hang off demo policies, claims,
 * payments and partners, so finance, commission, bordereau and renewal outputs rooted in them can leave
 * demo rows out too. DemoScenarioSeeder flags any table that has both columns automatically; demo:exit
 * back-fills rows created through the app by demo personas (DemoModeSwitch::flagDerived()).
 * Tables with immutability triggers (claim_decisions, documents, payment_allocations) are deliberately excluded.
 */
return new class extends Migration
{
    public const TABLES = [
        'claim_documents', 'claim_events', 'claim_payments', 'customer_attributions', 'financial_obligations', 'kyc_submissions',
        'partner_licences', 'partner_statements', 'payment_attempts', 'policy_cancellations', 'policy_premium_instalments',
        'policy_transactions', 'premium_components', 'refunds', 'renewal_cases', 'renewal_work_items', 'settlement_batches',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table): void {
                if (! Schema::hasColumn($table, 'data_origin')) {
                    $t->string('data_origin', 24)->nullable();
                }
                if (! Schema::hasColumn($table, 'is_demo')) {
                    $t->boolean('is_demo')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'is_demo')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['is_demo', 'data_origin']));
            }
        }
    }
};
