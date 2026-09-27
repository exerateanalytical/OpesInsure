<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Health\Preauth\PreauthLifecycle;
use App\Application\Health\Preauth\PreauthorizationService;
use App\Filament\Shared\Actions\HealthProviderActions;
use App\Filament\Shared\Pages\HealthQueuePage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Insurer pre-authorization queue and detail (GET health/preauthorizations[/{id}]); decisions via HealthProviderActions::preauth(). */
final class HealthPreauthorizationQueue extends HealthQueuePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'health/preauthorizations';

    protected static string $permission = 'health.preauth.view';

    protected static string $screen = 'preauth_queue';

    protected const STATUSES = PreauthLifecycle::STATES;

    protected function fetch(string $tenantId, ?string $status): array
    {
        return app(PreauthorizationService::class)->list($tenantId, ['status' => $status]);
    }

    protected function columns(): array
    {
        return ['preauth_number', 'request_type', 'status', 'provider', 'member_ref', 'service_date', 'requested_amount_minor', 'approved_amount_minor', 'currency'];
    }

    protected function workflowActions(): array
    {
        return HealthProviderActions::preauth();
    }

    protected function detail(string $tenantId, string $id): array
    {
        $svc = app(PreauthorizationService::class);
        $d = $svc->present($svc->find($tenantId, $id), true);

        return [
            'cards' => array_intersect_key($d, array_flip(['preauth_number', 'request_type', 'status', 'member_ref', 'service_date', 'eligible', 'requested_amount_minor',
                'approved_amount_minor', 'currency', 'proposed_decision', 'decision_reason_code', 'decision_notes', 'info_request', 'gop_valid_from', 'gop_valid_until', 'type_details'])),
            'lines' => self::flat(array_map(fn ($l) => array_intersect_key($l, array_flip(['line_no', 'service_code', 'quantity', 'unit_price_minor', 'requested_amount_minor',
                'insurer_amount_minor', 'line_decision', 'approved_quantity', 'approved_amount_minor', 'decline_reason'])), $d['lines'] ?? [])),
            'history' => self::flat(array_map(fn ($e) => array_intersect_key($e, array_flip(['event', 'from_status', 'to_status', 'reason', 'occurred_at'])), $d['history'] ?? [])),
        ];
    }
}
