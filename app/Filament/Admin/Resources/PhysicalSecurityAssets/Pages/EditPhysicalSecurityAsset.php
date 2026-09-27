<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PhysicalSecurityAssets\Pages;

use App\Application\Audit\AuditWriter;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\PhysicalSecurityAssets\PhysicalSecurityAssetResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

final class EditPhysicalSecurityAsset extends EditRecord
{
    protected static string $resource = PhysicalSecurityAssetResource::class;

    protected function getHeaderActions(): array
    {
        // Maker-checker verify step: a different administrator than the recorder (PhysicalSecurityAssetResource::prepare).
        return [
            Action::make('verify')->label('Verify (second administrator)')->icon('lucide-shield-check')->color('success')->requiresConfirmation()
                ->visible(fn () => ! in_array($this->record->status, ['VERIFIED', 'RETIRED'], true))
                ->modalDescription('Confirm you have checked the physical asset / artwork against the supplier documents. You cannot verify an asset you recorded.')
                ->action(function () {
                    $record = $this->record;
                    $data = ServiceValidation::run(fn () => PhysicalSecurityAssetResource::prepare(['status' => 'VERIFIED'] + $record->only([
                        'artwork_path', 'quantity_received', 'quantity_issued', 'quantity_spoiled', 'quantity_destroyed', 'serial_from', 'serial_to']), $record));
                    if ($data === null) {
                        return;
                    }
                    $record->forceFill(array_intersect_key($data, array_flip(['status', 'verified_by', 'verified_at', 'artwork_sha256'])))->save();
                    app(AuditWriter::class)->record('document_security.physical_asset.verified', 'document_physical_security_asset', $record->id,
                        ['asset_kind' => $record->asset_kind, 'seal_profile_code' => $record->seal_profile_code, 'artwork_sha256' => $record->artwork_sha256]);
                    Notification::make()->title('Asset verified')->success()->send();
                    $this->refreshFormData(['status']);
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PhysicalSecurityAssetResource::prepare($data, $this->record);
    }
}
