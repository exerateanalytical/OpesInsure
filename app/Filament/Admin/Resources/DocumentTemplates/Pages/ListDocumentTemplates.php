<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentTemplates\Pages;

use App\Filament\Admin\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListDocumentTemplates extends ListRecords
{
    protected static string $resource = DocumentTemplateResource::class;

    /** "Pending approval" tab: templates in REVIEW, each with the Approve / Approve & publish row actions. */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Pending approval')->badge(fn () => DocumentTemplate::where('status', 'REVIEW')->count() ?: null)->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'REVIEW')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return DocumentTemplate::where('status', 'REVIEW')->exists() ? 'pending' : 'all';
    }
}
