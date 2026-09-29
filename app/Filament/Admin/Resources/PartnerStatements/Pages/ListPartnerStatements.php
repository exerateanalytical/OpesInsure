<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PartnerStatements\Pages;

use App\Filament\Admin\Resources\PartnerStatements\PartnerStatementResource;
use App\Filament\Shared\Actions\StatementPayoutActions;
use Filament\Resources\Pages\ListRecords;

final class ListPartnerStatements extends ListRecords
{
    protected static string $resource = PartnerStatementResource::class;

    /** Every panel (D4 lifted 2026-09-29): gated by the API permission (docs/spec/PORTAL_WRITE_RULES.md). */
    protected function getHeaderActions(): array
    {
        return [StatementPayoutActions::statementGenerate(), StatementPayoutActions::statementPrepare()];
    }
}
