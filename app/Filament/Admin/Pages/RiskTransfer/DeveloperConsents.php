<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Filament\Shared\Actions\DeveloperPortalActions;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Tenant consents given to API connections — GET developer/consents (integrations.consent.manage). */
final class DeveloperConsents extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-shield-check';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'risk-transfer/developer-consents';

    protected static array $permissions = ['integrations.consent.manage'];

    protected static string $screen = 'developer_consents';

    protected static string $group = 'Integrations';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn (string $t) => self::rows(DB::table('integration_client_consents as x')->join('integration_clients as c', 'c.id', '=', 'x.integration_client_id')
                ->where('x.tenant_id', $t)->orderByDesc('x.granted_at')->limit(500)
                ->get(['x.id', 'c.name as client_name', 'x.scopes', 'x.status', 'x.granted_at', 'x.expires_at', 'x.revoked_at'])
                ->map(fn ($x) => [...(array) $x, 'scopes' => implode(', ', (array) json_decode((string) $x->scopes, true))])),
            ['client_name' => 'text', 'scopes' => 'text', 'status' => 'status', 'granted_at' => 'date', 'expires_at' => 'date', 'revoked_at' => 'date'],
            [DeveloperPortalActions::devGrantConsent()],
            [DeveloperPortalActions::devRevokeConsent()]);
    }
}
