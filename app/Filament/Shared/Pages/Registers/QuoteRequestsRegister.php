<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Registers;

use App\Filament\Shared\Actions\QuoteActions;
use App\Filament\Shared\Pages\RegisterPage;
use Filament\Tables\Table;

/**
 * Register of carrier quote requests (UI audit 2026-09-27). Insurer users with carrier.quote_requests.respond take a
 * request into work or decline it from the row (QuoteActions::carrierStart / carrierDecline, same service and carrier
 * scoping as POST carrier/quote-requests/{r}/start|decline). Offers are still recorded through the API / broker on-behalf.
 */
final class QuoteRequestsRegister extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-inbox';

    protected static ?string $slug = 'quote-requests';

    protected static ?int $navigationSort = 41;

    protected static string $registerTable = 'carrier_quote_requests';

    protected static array $permissions = ['carrier.quote_requests.view'];

    protected static string $label = 'Quote requests';

    protected static ?string $group = 'Underwriting';

    protected static array $columns = ['request_number' => ['text', 'number'], 'status' => ['status', 'status'], 'channel' => ['text', 'channel'], 'requested_at' => ['date', 'requested'], 'response_due_at' => ['date', 'due'], 'responded_at' => ['date', 'responded'], 'decline_reason_code' => ['text', 'reason']];

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([QuoteActions::carrierStart(), QuoteActions::carrierDecline()]);
    }
}
