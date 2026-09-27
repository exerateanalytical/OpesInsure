<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Registers;

use App\Filament\Shared\Pages\RegisterPage;

/** Read-only register of cashier sessions (UI audit 2026-09-27). */
final class CashierSessionsRegister extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-wallet';

    protected static ?string $slug = 'cashier-sessions';

    protected static ?int $navigationSort = 75;

    protected static string $registerTable = 'cashier_sessions';

    protected static array $permissions = ['cashier.sessions.view'];

    protected static string $label = 'Cashier sessions';

    protected static ?string $group = 'Financial operations';

    protected static ?string $carrierColumn = null;

    protected static string $defaultSort = 'opened_at';

    protected static array $columns = ['status' => ['status', 'status'], 'opening_float_minor' => ['money', 'opening_float'], 'expected_cash_minor' => ['money', 'expected_cash'], 'counted_cash_minor' => ['money', 'counted_cash'], 'variance_minor' => ['money', 'variance'], 'opened_at' => ['date', 'opened'], 'closed_at' => ['date', 'closed']];
}
