<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\DocumentEngine;

use App\Filament\Admin\Concerns\DocumentEngineAccess;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Shared read-only report layout for the DOC-ADM report screens. */
abstract class DocumentEngineReportPage extends Page
{
    use DocumentEngineAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected string $view = 'filament.admin.pages.document-engine-report';

    /** @return array{cards?: array<int, array{0: string, 1: mixed, 2: ?string}>, sections: array<int, array<string, mixed>>} */
    abstract protected function report(): array;

    protected function getViewData(): array
    {
        return $this->report() + ['cards' => [], 'sections' => []];
    }
}
