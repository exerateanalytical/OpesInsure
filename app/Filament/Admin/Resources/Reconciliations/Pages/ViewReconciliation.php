<?php
namespace App\Filament\Admin\Resources\Reconciliations\Pages;use App\Filament\Admin\Resources\Reconciliations\ReconciliationResource;use App\Filament\Shared\Pages\RecordDetailPage;final class ViewReconciliation extends RecordDetailPage{protected static string $resource=ReconciliationResource::class;
    /** UI batch 26: approve the reconciliation (ReconciliationService::approve, maker-checker). */
    protected function getHeaderActions(): array
    {
        return [\App\Filament\Shared\Actions\ReconciliationActions::approve(), ...parent::getHeaderActions()];
    }
}
