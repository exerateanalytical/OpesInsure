<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Registers;

use App\Application\WebExperiences\PortalScope;
use App\Filament\Shared\Pages\RegisterPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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

    /** Owner decision 2026-09-27: a carrier-linked user sees the KYC of their own carrier's policyholders only. */
    protected function scope(Builder $q, string $tenantId): Builder
    {
        $q = parent::scope($q, $tenantId);
        $carrier = PortalScope::carrierId();

        return $carrier === null ? $q : $q->whereIn('kyc_submissions.party_id', DB::table('policies')->where('tenant_id', $tenantId)->where('carrier_id', $carrier)->select('party_id'));
    }
}
