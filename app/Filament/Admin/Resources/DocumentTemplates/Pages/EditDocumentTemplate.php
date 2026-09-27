<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentTemplates\Pages;

use App\Application\Documents\Engine\DocumentTemplateService;
use App\Filament\Admin\Concerns\NotifiesServiceValidationErrors;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/** DOC-ADM-008 designer (DRAFT only): bilingual sections, specimen PDF preview of the unsaved state, save & submit. */
final class EditDocumentTemplate extends EditRecord
{
    use NotifiesServiceValidationErrors;

    protected static string $resource = DocumentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocumentTemplateResource::previewAction(fn (self $page) => (array) ($page->data ?? [])),
            // Submit saves first so the reviewer sees exactly what is on screen.
            Action::make('saveAndSubmit')->label('Save & submit for review')->icon(Heroicon::OutlinedPaperAirplane)->requiresConfirmation()
                ->visible(fn () => $this->record->status === 'DRAFT')
                ->action(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    if (ServiceValidation::run(fn () => app(DocumentTemplateService::class)->submit($this->record->refresh(), auth()->user()))) {
                        Notification::make()->title('Submitted for review')->success()->send();
                        $this->redirect(DocumentTemplateResource::getUrl('view', ['record' => $this->record]));
                    }
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(DocumentTemplateService::class)->updateDraft($record, $data, auth()->user());
    }
}
