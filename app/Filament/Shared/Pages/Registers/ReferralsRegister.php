<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Registers;

use App\Application\WebExperiences\PortalScope;
use App\Filament\Shared\Pages\RegisterPage;
use Illuminate\Database\Eloquent\Builder;

/** Read-only register of underwriting referrals (UI audit 2026-09-27). */
final class ReferralsRegister extends RegisterPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-flag';

    protected static ?string $slug = 'referrals';

    protected static ?int $navigationSort = 42;

    protected static string $registerTable = 'underwriting_referral_tasks';

    protected static array $permissions = ['carrier.referrals.read', 'underwriting.decide'];

    protected static string $label = 'Referrals';

    protected static ?string $group = 'Underwriting';

    protected static array $columns = ['reason_code' => ['text', 'reason'], 'severity' => ['status', 'severity'], 'status' => ['status', 'status'], 'due_at' => ['date', 'due'], 'resolved_at' => ['date', 'resolved'], 'created_at' => ['date', 'created']];

    /** Referral tasks carry no tenant: scoped through their underwriting case. */
    protected function scope(Builder $q, string $tenantId): Builder
    {
        $q->join('underwriting_cases as uc', 'uc.id', '=', 'underwriting_referral_tasks.underwriting_case_id')->where('uc.tenant_id', $tenantId);
        if (($carrier = PortalScope::carrierId()) !== null) {
            $q->where('uc.carrier_id', $carrier);
        }

        return $q;
    }
}
