<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Registers;

use App\Filament\Shared\Pages\RegisterPage;

/** Read-only register of reinsurance cessions (UI audit 2026-09-27). */
final class ReinsuranceCessionsRegister extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-split';

    protected static ?string $slug = 'reinsurance-cessions';

    protected static ?int $navigationSort = 62;

    protected static string $registerTable = 'reinsurance_cessions';

    protected static array $permissions = ['reinsurance.cessions.view'];

    protected static string $label = 'Reinsurance cessions';

    protected static ?string $group = 'Reinsurance & co-insurance';

    protected static ?string $carrierColumn = null;

    protected static array $columns = ['treaty_type' => ['text', 'type'], 'ceded_percent' => ['text', 'ceded_percent'], 'gross_premium_minor' => ['money', 'gross_premium'], 'ceded_premium_minor' => ['money', 'ceded_premium'], 'net_ceded_premium_minor' => ['money', 'net_ceded_premium'], 'status' => ['status', 'status'], 'created_at' => ['date', 'created']];
}
