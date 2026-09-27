<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Health\ProviderClaims\ProviderSettlementService;
use App\Filament\Shared\Actions\HealthProviderActions;
use App\Filament\Shared\Pages\HealthQueuePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Insurer provider settlement batches (health/provider-settlements): create a batch, open it, pay it — via HealthProviderActions. */
final class HealthProviderSettlements extends HealthQueuePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 62;

    protected static ?string $slug = 'health/provider-settlements';

    protected static string $permission = 'health.provider_claims.view';

    protected static string $screen = 'provider_settlements';

    protected const STATUSES = ['OPEN', 'PAID', 'CANCELLED'];

    protected const TABLE = 'health_provider_settlement_batches';

    protected function fetch(string $tenantId, ?string $status): array
    {
        return app(ProviderSettlementService::class)->list($tenantId, ['status' => $status]);
    }

    protected function columns(): array
    {
        return ['batch_number', 'status', 'provider', 'claim_count', 'total_minor', 'currency', 'payment_reference', 'paid_at'];
    }

    protected function headerWorkflowActions(): array
    {
        return [HealthProviderActions::settlementCreate()];
    }

    protected function workflowActions(): array
    {
        return [HealthProviderActions::settlementPay()];
    }

    protected function detail(string $tenantId, string $id): array
    {
        $b = (array) app(ProviderSettlementService::class)->batch($tenantId, $id);

        return [
            'cards' => array_intersect_key($b, array_flip(['batch_number', 'status', 'claim_count', 'total_minor', 'currency', 'payment_reference', 'paid_at', 'created_at'])),
            'lines' => self::flat(array_map(fn ($c) => array_intersect_key((array) $c, array_flip(['claim_number', 'invoice_reference', 'status', 'insurer_share_minor'])), array_values(array_filter($b['claims'] ?? [], fn ($c) => \App\Application\WebExperiences\PortalScope::visibleOf('health_provider_claims', [(string) $c->id]) !== [])))),
            'history' => [],
        ];
    }
}
