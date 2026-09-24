<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\DocumentEngine;

use App\Application\Documents\Engine\DocumentPackResolver;
use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Documents\Engine\ProductDocumentGate;
use App\Models\DocumentIssuanceProfile;
use App\Models\DocumentPackManifest;
use App\Models\InsuranceProduct;
use Illuminate\Support\Facades\DB;

/** DOC-ADM-002/003 Document types. */
final class DocumentTypeRegistry extends DocumentEngineReportPage
{
    protected static ?string $navigationLabel = 'Document types';

    protected static ?int $navigationSort = 301;

    protected static ?string $slug = 'document-engine/types';

    public function getTitle(): string
    {
        return 'Document types (DOC-ADM-002/003)';
    }

    protected function report(): array
    {
        $q = mb_strtoupper(trim((string) request()->query('q', '')));
        $rows = collect(app(DocumentRegister::class)->types())->filter(fn ($t) => $q === '' || str_contains($t['code'], $q) || str_contains((string) $t['id'], $q))
            ->map(fn ($t) => [$t['id'], $t['code'], $t['name_en'], $t['name_fr'], $t['group_code'], $t['display_group'], $t['security_level'], $t['numbering_family'], $t['input_document'] ? 'LINK (input)' : 'GENERATE', $t['life_specific'] ? 'life template' : ''])->values()->all();

        return ['sections' => [['title' => 'Canonical Document Register ('.count($rows).')', 'description' => 'Type details (DOC-ADM-003): add ?q=CODE to filter. Source: '.basename(DocumentRegister::path()).' + catalogue document_types.', 'headers' => ['ID', 'Code', 'English', 'Français', 'Group', 'App group', 'Security', 'Numbering', 'Engine mode', 'Notes'], 'rows' => $rows]]];
    }
}
