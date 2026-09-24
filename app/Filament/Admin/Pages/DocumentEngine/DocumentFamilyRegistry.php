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

/** DOC-ADM-004 Document families. */
final class DocumentFamilyRegistry extends DocumentEngineReportPage
{
    protected static ?string $navigationLabel = 'Document families';

    protected static ?int $navigationSort = 302;

    protected static ?string $slug = 'document-engine/families';

    public function getTitle(): string
    {
        return 'Document families (DOC-ADM-004)';
    }

    protected function report(): array
    {
        $types = collect(app(DocumentRegister::class)->types());
        $rows = $types->groupBy('group_code')->map(fn ($g, $k) => [$k, $g->count(), $g->pluck('code')->take(8)->implode(', ').($g->count() > 8 ? ' …' : '')])->values()->all();

        return ['sections' => [['title' => 'Groups and subtype families', 'headers' => ['Family / group', 'Types', 'Examples'], 'rows' => $rows]]];
    }
}
