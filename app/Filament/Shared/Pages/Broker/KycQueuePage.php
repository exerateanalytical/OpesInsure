<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Application\Kyc\KycService;
use BackedEnum;
use Filament\Tables\Table;

/** BRK-020 KYC Review Queue: the caller's book submissions not yet decided (drafts, submitted, in review, information requested), oldest first. */
final class KycQueuePage extends KycScreen
{
    protected static ?string $slug = 'kyc/queue';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-list-checks';

    protected static ?int $navigationSort = 21;

    protected static string $screen = 'kyc_queue';

    public function table(Table $table): Table
    {
        return $this->submissionTable($table, self::submissions($this->tenantId)->whereIn('status', array_values(array_unique([...KycService::EDITABLE, ...KycService::IN_REVIEW])))
            ->orderByRaw('submitted_at NULLS LAST')->oldest('created_at'), self::caseActions());
    }
}
