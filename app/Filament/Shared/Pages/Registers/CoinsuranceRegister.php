<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Registers;

use App\Filament\Shared\Pages\RegisterPage;

/** Read-only register of co-insurance arrangements (UI audit 2026-09-27). */
final class CoinsuranceRegister extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-handshake';

    protected static ?string $slug = 'coinsurance';

    protected static ?int $navigationSort = 60;

    protected static string $registerTable = 'coinsurance_arrangements';

    protected static array $permissions = ['coinsurance.view'];

    protected static string $label = 'Co-insurance';

    protected static ?string $group = 'Reinsurance & co-insurance';

    protected static ?string $carrierColumn = 'carrier_id';

    protected static array $columns = ['reference' => ['text', 'reference'], 'status' => ['status', 'status'], 'currency' => ['text', 'currency'], 'settlement_method' => ['text', 'settlement_method'], 'effective_from' => ['day', 'effective_from'], 'effective_until' => ['day', 'effective_until']];
}
