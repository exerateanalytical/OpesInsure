<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Providers\Workspace\ProviderOperationsService;
use App\Filament\Shared\Actions\HealthProviderActions;
use App\Filament\Shared\Pages\HealthQueuePage;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** Insurer-side provider-portal disputes (POST provider-disputes/{id}/resolve) via HealthProviderActions::providerDisputeResolve(). */
final class HealthProviderDisputes extends HealthQueuePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-scale';

    protected static ?int $navigationSort = 63;

    protected static ?string $slug = 'health/provider-disputes';

    protected static string $permission = 'health.provider_claims.view';

    protected static string $screen = 'provider_disputes';

    protected const STATUSES = ['SUBMITTED', 'ACKNOWLEDGED', 'UNDER_REVIEW', 'MORE_INFORMATION_REQUIRED', 'ESCALATED', 'RESOLVED_PROVIDER', 'RESOLVED_INSURER', 'PARTIALLY_RESOLVED', 'CLOSED'];

    protected const TABLE = 'provider_disputes';

    protected function fetch(string $tenantId, ?string $status): array
    {
        return app(ProviderOperationsService::class)->insurerDisputes($tenantId, $status);
    }

    protected function columns(): array
    {
        return ['dispute_number', 'subject_type', 'reason_code', 'status', 'provider', 'disputed_amount_minor', 'resolution_amount_minor', 'currency'];
    }

    protected function workflowActions(): array
    {
        return [HealthProviderActions::providerDisputeResolve()];
    }

    protected function detail(string $tenantId, string $id): array
    {
        $d = (array) (DB::table('provider_disputes')->where(['tenant_id' => $tenantId, 'id' => $id])->first() ?? []);

        return ['cards' => array_intersect_key($d, array_flip(['dispute_number', 'subject_type', 'claim_line_no', 'reason_code', 'status', 'disputed_amount_minor',
            'resolution_amount_minor', 'currency', 'description', 'response', 'resolved_at'])), 'lines' => [], 'history' => []];
    }
}
