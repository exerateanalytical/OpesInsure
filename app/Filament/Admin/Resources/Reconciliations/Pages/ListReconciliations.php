<?php
namespace App\Filament\Admin\Resources\Reconciliations\Pages;use App\Filament\Admin\Resources\Reconciliations\ReconciliationResource;use Filament\Resources\Pages\ListRecords;final class ListReconciliations extends ListRecords{protected static string $resource=ReconciliationResource::class;
    /** UI batch 26: import a statement (ReconciliationService::import). */
    protected function getHeaderActions(): array
    {
        return [\App\Filament\Shared\Actions\ReconciliationActions::import()];
    }
}
