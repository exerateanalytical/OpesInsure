<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Application\WebExperiences\BrokerBookMetrics;
use BackedEnum;
use Filament\Tables\Table;

/** BRK-019 KYC Dashboard: KYC status counts of the caller's book (BrokerBookMetrics::kycSummary) and the latest cases. */
final class KycDashboardPage extends KycScreen
{
    protected static ?string $slug = 'kyc';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-id-card';

    protected static ?int $navigationSort = 20;

    protected static string $screen = 'kyc_dashboard';

    public function getStats(): array
    {
        return $this->tenantId ? app(BrokerBookMetrics::class)->kycSummary($this->tenantId) : [];
    }

    public function table(Table $table): Table
    {
        return $this->submissionTable($table, self::submissions($this->tenantId)->whereNull('superseded_by_submission_id')->latest('updated_at'), [])
            ->heading(__('broker_screens_a.kyc_dashboard.latest'));
    }
}
