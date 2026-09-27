<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Registers;

use App\Filament\Shared\Pages\RegisterPage;

/** Read-only register of KYC submissions (UI audit 2026-09-27). */
final class KycRegister extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-id-card';

    protected static ?string $slug = 'kyc';

    protected static ?int $navigationSort = 30;

    protected static string $registerTable = 'kyc_submissions';

    protected static array $permissions = ['kyc.view'];

    protected static string $label = 'KYC reviews';

    protected static ?string $group = 'Customers & partners';

    protected static ?string $carrierColumn = null;

    protected static array $columns = ['subject_kind' => ['text', 'subject'], 'kyc_level' => ['text', 'level'], 'status' => ['status', 'status'], 'screening_status' => ['status', 'screening'], 'submitted_at' => ['date', 'submitted'], 'expires_at' => ['date', 'expires']];
}
