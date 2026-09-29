<?php

namespace App\Filament\Admin\Resources\UnderwritingCases\Pages;

use App\Filament\Admin\Resources\UnderwritingCases\UnderwritingCaseResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

/** Q8 underwriting workbench case view (UND-005): workbench tabs from UnderwritingCaseResource::infolist. */
final class ViewUnderwritingCase extends ViewRecord
{
    protected static string $resource = UnderwritingCaseResource::class;

    public function infolist(Schema $schema): Schema
    {
        return UnderwritingCaseResource::infolist($schema);
    }

    protected function getHeaderActions(): array
    {
        return [\App\Filament\Shared\Actions\UnderwritingCaseActions::group()];
    }
}
