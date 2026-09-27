<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screens "contracts", "contract_detail", "tariff_schedules" and "tariff_detail": the opened contract shows its approved tariff lines. */
final class ContractsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'contracts';

    protected static string $permission = 'provider_portal.network.view';

    protected static string $screen = 'contracts';

    public function rowActions(array $row): array
    {
        return isset($row['id']) && $this->can('provider.tariff.view') ? [['label' => __('provider_workspace.screens.tariff_schedules'), 'action' => 'open', 'arg' => $row['id']]] : [];
    }

    private function can(string $permission): bool
    {
        return (bool) rescue(fn () => $this->user()->hasPermission($permission), false, false);
    }

    /** Contract detail + tariff schedules (ProviderPortalService::tariffs, approved / superseded versions only). */
    protected function detail(): ?array
    {
        $c = $this->ws()->contract($this->tenantId(), $this->scope(), $this->selected)['contract'];
        $rows = [];
        foreach (app(\App\Application\Providers\Portal\ProviderPortalService::class)->tariffs($this->tenantId(), $this->scope(), $this->selected) as $v) {
            foreach ($v->lines as $l) {
                $rows[] = ['version' => $v->version, 'status' => $v->status, 'effective_from' => $v->effective_from, 'effective_to' => $v->effective_to, 'currency' => $v->currency] + (array) $l;
            }
        }

        return ['title' => __('provider_workspace.screens.contract_detail').' '.($c['contract_number'] ?? ''),
            'cards' => array_filter($c, fn ($v) => is_scalar($v) || $v === null), 'rows' => array_map(fn ($r) => array_diff_key($r, ['medical_service_id' => 1]), $rows)];
    }

    protected function rows(): array
    {
        return array_map(fn ($r) => (array) $r, app(\App\Application\Providers\Portal\ProviderPortalService::class)->contracts($this->tenantId(), $this->scope()));
    }
}
