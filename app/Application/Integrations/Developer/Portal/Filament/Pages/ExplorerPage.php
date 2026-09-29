<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use BackedEnum;

/**
 * DEV-008 API Explorer — pick a partner operation, see its parameters, scopes and a ready-to-run curl against the
 * sandbox. Requests run from the developer's own machine with their own token: the portal never holds or replays a secret.
 */
final class ExplorerPage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-square-terminal';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'explorer';

    protected static string $screen = 'explorer';

    public ?string $operation = null;

    public function extraView(): ?string
    {
        return 'developer-portal.explorer';
    }

    public function pick(string $operationId): void
    {
        $this->operation = collect($this->svc()->operations())->firstWhere('operation_id', $operationId) ? $operationId : null;
    }

    /** @return array<string, mixed>|null */
    public function selectedOperation(): ?array
    {
        $op = $this->operation ? collect($this->svc()->operations())->firstWhere('operation_id', $this->operation) : null;

        return $op ? $op + ['curl' => $this->svc()->curlFor($op['method'], $op['path'], (string) $op['body_fields'])] : null;
    }

    protected function rows(): array
    {
        return $this->svc()->operations();
    }

    protected function columns(): array
    {
        return ['method', 'path', 'scopes'];
    }

    public function rowActions(array $row): array
    {
        return [['label' => __('developer_portal.ui.try'), 'action' => 'pick', 'arg' => (string) $row['operation_id']]];
    }
}
