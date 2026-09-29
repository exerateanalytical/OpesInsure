<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Filament\Shared\Actions\DeveloperPortalActions;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Developer portal keys — GET developer/clients (integrations.manage). Lists key metadata only (never a secret: the
 * service stores the OAuth secret hashed and returns the plain value once, at issue).
 */
final class DeveloperKeys extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-key-round';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'risk-transfer/developer-keys';

    protected static array $permissions = ['integrations.manage'];

    protected static string $screen = 'developer_keys';

    protected static string $group = 'Integrations';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn () => self::rows(DB::table('integration_client_keys as k')->join('integration_clients as c', 'c.id', '=', 'k.integration_client_id')
                ->orderByDesc('k.created_at')->limit(500)
                ->get(['k.id', 'k.integration_client_id', 'c.name as client_name', 'k.environment', 'k.label', 'k.status', 'k.last_used_at', 'k.revoked_at', 'k.created_at'])),
            ['client_name' => 'text', 'environment' => 'text', 'label' => 'text', 'status' => 'status', 'last_used_at' => 'date', 'created_at' => 'date'],
            [DeveloperPortalActions::devIssueKey(), DeveloperPortalActions::devRateLimits()],
            [DeveloperPortalActions::devRevokeKey()]);
    }
}
