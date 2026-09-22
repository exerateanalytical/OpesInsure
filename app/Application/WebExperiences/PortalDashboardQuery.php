<?php
namespace App\Application\WebExperiences;

use Illuminate\Support\Facades\{DB,Schema};

final class PortalDashboardQuery
{
    private const TABLES = ['tenant_customers','quotes','proposals','policies','payment_intents','claims','fulfilment_orders','support_tickets'];

    public function handle(?string $tenantId, string $portal): array
    {
        $metrics = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) { continue; }
            $query = DB::table($table);
            if ($tenantId && Schema::hasColumn($table, 'tenant_id')) { $query->where('tenant_id', $tenantId); }
            $metrics[$table] = $query->count();
        }
        return ['portal'=>$portal,'tenant_id'=>$tenantId,'metrics'=>$metrics,'generated_at'=>now()->toIso8601String()];
    }
}
