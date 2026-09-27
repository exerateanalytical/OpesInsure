<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Registers;

use App\Filament\Shared\Pages\RegisterPage;

/** Read-only register of reinsurance treaties (UI audit 2026-09-27). */
final class ReinsuranceTreatiesRegister extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-scroll-text';

    protected static ?string $slug = 'reinsurance-treaties';

    protected static ?int $navigationSort = 61;

    protected static string $registerTable = 'reinsurance_treaties';

    protected static array $permissions = ['reinsurance.treaties.view'];

    protected static string $label = 'Reinsurance treaties';

    protected static ?string $group = 'Reinsurance & co-insurance';

    protected static ?string $carrierColumn = null;

    protected static array $columns = ['treaty_number' => ['text', 'number'], 'name' => ['text', 'name'], 'treaty_type' => ['text', 'type'], 'underwriting_year' => ['text', 'year'], 'currency' => ['text', 'currency'], 'status' => ['status', 'status']];
}
