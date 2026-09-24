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

/** DOC-ADM-018 Localization. */
final class DocumentLocalization extends DocumentEngineReportPage
{
    protected static ?string $navigationLabel = 'Localization';

    protected static ?int $navigationSort = 315;

    protected static ?string $slug = 'document-engine/localization';

    public function getTitle(): string
    {
        return 'Localization (DOC-ADM-018)';
    }

    protected function report(): array
    {
        $tpl = DB::table('document_templates')->whereIn('status', ['PUBLISHED', 'APPROVED'])->get()->groupBy('document_type_code');
        $rows = $tpl->map(function ($g, $code) {
            $langs = $g->pluck('language')->unique()->values()->all();
            $ok = in_array('BILINGUAL', $langs, true) || (in_array('FR', $langs, true) && in_array('EN', $langs, true));

            return [$code, implode(', ', $langs), $ok ? 'FR + EN covered' : 'MISSING '.(in_array('FR', $langs, true) ? 'EN' : 'FR')];
        })->values()->all();

        return ['sections' => [['title' => 'Template languages per document type', 'headers' => ['Document type', 'Languages', 'Coverage'], 'rows' => $rows]]];
    }
}
