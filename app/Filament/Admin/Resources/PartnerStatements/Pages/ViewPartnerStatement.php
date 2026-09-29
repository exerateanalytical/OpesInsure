<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PartnerStatements\Pages;

use App\Filament\Admin\Resources\PartnerStatements\PartnerStatementResource;
use App\Filament\Shared\Actions\StatementPayoutActions;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewPartnerStatement extends RecordDetailPage
{
    protected static string $resource = PartnerStatementResource::class;

    /** Every panel (D4 lifted 2026-09-29): each action is gated by its API permission + own-organisation record (docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return StatementPayoutActions::statementActions();
    }
}
