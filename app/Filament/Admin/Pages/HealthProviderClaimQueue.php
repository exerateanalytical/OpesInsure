<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Filament\Shared\Actions\HealthProviderActions;
use App\Filament\Shared\Pages\HealthQueuePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Insurer provider-claim adjudication (GET health/provider-claims[/{id}]); review, line-by-line adjudication, payable, dispute via HealthProviderActions::providerClaim(). */
final class HealthProviderClaimQueue extends HealthQueuePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 61;

    protected static ?string $slug = 'health/provider-claims';

    protected static string $permission = 'health.provider_claims.view';

    protected static string $screen = 'provider_claims';

    protected const STATUSES = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'PARTIALLY_APPROVED', 'REJECTED', 'PAYABLE', 'PAID', 'DISPUTED'];

    protected const TABLE = 'health_provider_claims';

    protected function fetch(string $tenantId, ?string $status): array
    {
        return app(ProviderClaimService::class)->list($tenantId, ['status' => $status]);
    }

    protected function columns(): array
    {
        return ['claim_number', 'invoice_reference', 'status', 'provider', 'billed_minor', 'allowed_minor', 'insurer_share_minor', 'rejected_minor', 'currency'];
    }

    protected function workflowActions(): array
    {
        return HealthProviderActions::providerClaim();
    }

    protected function detail(string $tenantId, string $id): array
    {
        $c = (array) app(ProviderClaimService::class)->find($tenantId, $id);

        return [
            'cards' => array_intersect_key($c, array_flip(['claim_number', 'invoice_reference', 'status', 'service_date', 'billed_minor', 'allowed_minor', 'copay_minor',
                'insurer_share_minor', 'member_share_minor', 'rejected_minor', 'currency', 'preauth_verified', 'adjudication_note', 'dispute_reason'])),
            'lines' => self::flat(array_map(fn ($l) => array_intersect_key((array) $l, array_flip(['line_no', 'service_code', 'quantity', 'unit_price_minor', 'billed_minor',
                'tariff_unit_price_minor', 'allowed_minor', 'copay_minor', 'insurer_share_minor', 'rejected_minor', 'decision', 'reason_code', 'explanation'])), $c['lines'] ?? [])),
            'history' => self::flat(array_map(fn ($e) => array_intersect_key((array) $e, array_flip(['event', 'from_status', 'to_status', 'reason', 'created_at'])), $c['history'] ?? [])),
        ];
    }
}
