<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\DocumentCatalogue\DocumentRequirementResolver;
use App\Filament\Admin\Concerns\DocumentCatalogueAccess;
use App\Models\DocumentCatalogue\DocumentProductType;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** Document Requirement Matrix grid: product type × lifecycle stage (resolved with baseline + inheritance). */
final class DocumentRequirementMatrix extends Page
{
    use DocumentCatalogueAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $navigationLabel = 'Requirement matrix';

    protected static ?int $navigationSort = 304;

    protected static ?string $slug = 'document-requirement-matrix';

    protected string $view = 'filament.admin.pages.document-requirement-matrix';

    #[Url]
    public ?string $productType = 'MOTOR_TPL';

    public static function canAccess(): bool
    {
        return static::canAccessDocumentCatalogue();
    }

    public function getTitle(): string
    {
        return 'Document requirement matrix';
    }

    protected function getViewData(): array
    {
        $types = DocumentProductType::orderBy('spec_section')->get();
        $code = $types->firstWhere('code', $this->productType)?->code ?? $types->first()?->code;
        $resolver = app(DocumentRequirementResolver::class);
        $rows = $code ? $resolver->resolve($code) : collect();

        return [
            'types' => $types,
            'current' => $types->firstWhere('code', $code),
            'chain' => $code ? $resolver->chain($code) : [],
            'stages' => ['PRE_CONTRACT', 'ISSUANCE', 'SERVICING', 'RENEWAL', 'TREATMENT', 'MOVEMENT', 'LIFECYCLE', 'CLAIM'],
            'grid' => $rows->groupBy('stage'),
            'overview' => $types->map(fn ($t) => ['code' => $t->code, 'status' => $t->status, 'counts' => $resolver->resolve($t->code)->groupBy('stage')->map->count()]),
        ];
    }
}
