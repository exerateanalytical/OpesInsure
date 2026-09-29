<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Filament\Shared\Actions\CarrierConnectorActions;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Carrier connector outbound messages and manual-fallback queue — GET carrier-connectors/fallback-queue
 * (integrations.carrier_connectors.manage). Payloads are not shown.
 */
final class CarrierConnectorMessages extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-plug';

    protected static ?int $navigationSort = 92;

    protected static ?string $slug = 'risk-transfer/carrier-connectors';

    protected static array $permissions = [CarrierConnectorActions::P];

    protected static string $screen = 'carrier_connectors';

    protected static string $group = 'Integrations';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn () => self::rows(DB::table('carrier_exchange_messages')->where('direction', 'OUTBOUND')->orderByDesc('created_at')->limit(300)
                ->get(['id', 'carrier_id', 'message_type', 'correlation_id', 'status', 'attempt_count', 'fallback_reason', 'next_attempt_at', 'created_at'])),
            ['message_type' => 'text', 'correlation_id' => 'text', 'status' => 'status', 'attempt_count' => 'text', 'fallback_reason' => 'text', 'created_at' => 'date'],
            [CarrierConnectorActions::connectorConfigure(), CarrierConnectorActions::connectorSync(), CarrierConnectorActions::connectorResolveConflict()],
            [CarrierConnectorActions::connectorDispatch(), CarrierConnectorActions::connectorResolveFallback()]);
    }
}
