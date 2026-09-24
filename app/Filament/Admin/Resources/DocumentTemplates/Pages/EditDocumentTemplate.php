<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentTemplates\Pages;

use App\Filament\Admin\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Resources\Pages\EditRecord;

final class EditDocumentTemplate extends EditRecord
{
    protected static string $resource = DocumentTemplateResource::class;

    use \App\Filament\Admin\Concerns\NotifiesServiceValidationErrors;

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        return app(\App\Application\Documents\Engine\DocumentTemplateService::class)->updateDraft($record, $data, auth()->user());
    }
}
