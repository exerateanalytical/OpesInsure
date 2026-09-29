<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Filament\Shared\Actions\KycActions;
use BackedEnum;
use Filament\Tables\Table;

/**
 * BRK-022 KYC Remediation: the caller's book cases needing a new KYC cycle or more evidence: information requested,
 * rejected, expired, or approved but expiring within 60 days (not yet superseded). Actions: remediate (POST
 * kyc/submissions/{s}/remediate, kyc.manage), attach documents and declare sources (kyc.manage).
 */
final class KycRemediationPage extends KycScreen
{
    protected static ?string $slug = 'kyc/remediation';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-rotate-ccw';

    protected static ?int $navigationSort = 22;

    protected static string $screen = 'kyc_remediation';

    public function table(Table $table): Table
    {
        $q = self::submissions($this->tenantId)->whereNull('superseded_by_submission_id')->where(fn ($w) => $w
            ->whereIn('status', ['MORE_INFO_REQUIRED', 'REJECTED', 'EXPIRED'])
            ->orWhere(fn ($x) => $x->where('status', 'APPROVED')->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(60))))
            ->orderByRaw('expires_at NULLS FIRST')->oldest('updated_at');

        return $this->submissionTable($table, $q, [KycActions::remediate(), KycActions::attachDocument(), KycActions::declareSources()]);
    }
}
