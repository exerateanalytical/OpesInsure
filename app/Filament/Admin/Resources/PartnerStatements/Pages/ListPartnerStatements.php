<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PartnerStatements\Pages;

use App\Application\WebExperiences\PortalScope;
use App\Filament\Admin\Resources\PartnerStatements\PartnerStatementResource;
use App\Filament\Shared\Actions\StatementPayoutActions;
use Filament\Resources\Pages\ListRecords;

final class ListPartnerStatements extends ListRecords
{
    protected static string $resource = PartnerStatementResource::class;

    /** Finance actions are offered in the admin panel only; the broker / insurer portals reuse this page read-only (owner decision D4). */
    protected function getHeaderActions(): array
    {
        return PortalScope::panel() === null ? [StatementPayoutActions::statementGenerate(), StatementPayoutActions::statementPrepare()] : [];
    }
}
