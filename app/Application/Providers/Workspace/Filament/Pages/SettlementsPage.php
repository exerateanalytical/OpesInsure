<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;

/** Provider Portal screens "settlements" and "settlement_detail" (remittance: statement figures derived from the batch claims, per-claim lines). */
final class SettlementsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-receipt-text';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'settlements';

    protected static string $permission = 'provider.settlement.view';

    protected static string $screen = 'settlements';

    public function rowActions(array $row): array
    {
        return isset($row['id']) ? [['label' => __('provider_workspace.ui.open'), 'action' => 'open', 'arg' => $row['id']]] : [];
    }

    protected function detail(): ?array
    {
        $d = $this->ws()->settlement($this->user(), $this->scope(), $this->selected);

        return ['title' => __('provider_workspace.screens.settlement_detail').' '.($d['settlement']['batch_number'] ?? ''), 'cards' => $d['statement'],
            'rows' => array_map(fn ($l) => (array) $l, $d['lines'])];
    }

    protected function rows(): array
    {
        return $this->ws()->settlements($this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
