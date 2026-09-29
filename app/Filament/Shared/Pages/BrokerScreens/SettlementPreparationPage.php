<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\{ClaimActions, ClaimCaseActions};
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;

/**
 * BRK-085 Settlement Preparation (WF-059): approved (fully or partially) claims of the book to settle — compute and
 * offer the settlement, get the discharge signed, request the payment. Every step runs the claim settlement services
 * (ClaimActions::settle / offerSettlement / requestPayment, ClaimCaseActions::settlementDischarge / ConfirmDischarge /
 * settlementPayment) with the claim API's permissions.
 */
final class SettlementPreparationPage extends ClaimScreen
{
    protected static string $key = 'settlement_preparation';

    protected static ?string $slug = 'claim-settlement-preparation';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-hand-coins';

    protected static ?int $navigationSort = 95;

    protected function query(): Builder
    {
        return $this->claims(['APPROVED', 'PARTIALLY_APPROVED']);
    }

    protected function columns(): array
    {
        return [...parent::columns(), self::money('approved_amount_minor', 'approved_amount')];
    }

    protected function recordActions(): array
    {
        return [self::group([
            ClaimActions::settle(), ClaimActions::offerSettlement(), ClaimCaseActions::settlementDischarge(),
            ClaimCaseActions::settlementConfirmDischarge(), ClaimCaseActions::settlementPayment(), ClaimActions::requestPayment(),
        ])];
    }
}
