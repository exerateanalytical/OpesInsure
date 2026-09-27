<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GeneratedDocuments\Pages;

use App\Application\WebExperiences\DocumentPanelQuery;
use App\Filament\Admin\Resources\GeneratedDocuments\GeneratedDocumentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/** Document detail: RecordShell tabs + download / public verification links + revoke/replace (DocumentStatusService). */
final class ViewGeneratedDocument extends ViewRecord
{
    protected static string $resource = GeneratedDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')->label(__('web_experience.documents.download'))->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => DocumentPanelQuery::downloadUrl($this->record) !== null)
                ->url(fn () => DocumentPanelQuery::downloadUrl($this->record), shouldOpenInNewTab: true),
            Action::make('verify')->label(__('web_experience.documents.verify'))->icon('heroicon-o-qr-code')->color('gray')
                ->visible(fn () => filled($this->record->verification_code))
                ->url(fn () => route('public.verify', ['code' => $this->record->verification_code]), shouldOpenInNewTab: true),
            GeneratedDocumentResource::statusChangeAction()->record($this->record),
        ];
    }
}
