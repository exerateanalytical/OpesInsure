<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Registers;

use App\Filament\Shared\Pages\RegisterPage;

/** Read-only register of FX rates (UI audit 2026-09-27). */
final class FxRatesRegister extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-arrow-left-right';

    protected static ?string $slug = 'fx-rates';

    protected static ?int $navigationSort = 76;

    protected static string $registerTable = 'fx_rates';

    protected static array $permissions = ['fx.rates.view'];

    protected static string $label = 'Exchange rates';

    protected static ?string $group = 'Financial operations';

    protected static ?string $carrierColumn = null;

    protected static string $defaultSort = 'effective_at';

    protected static array $columns = ['base_currency' => ['text', 'base_currency'], 'quote_currency' => ['text', 'quote_currency'], 'rate' => ['text', 'rate'], 'source' => ['text', 'source'], 'effective_at' => ['date', 'effective_from']];
}
