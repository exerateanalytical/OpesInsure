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

/** DOC-ADM-020 Quality & missing configuration. */
final class DocumentQuality extends DocumentEngineReportPage
{
    protected static ?string $navigationLabel = 'Quality & missing configuration';

    protected static ?int $navigationSort = 317;

    protected static ?string $slug = 'document-engine/quality';

    public function getTitle(): string
    {
        return 'Quality & missing configuration (DOC-ADM-020)';
    }

    protected function report(): array
    {
        $gate = app(ProductDocumentGate::class);
        $rows = InsuranceProduct::whereIn('status', ['ACTIVE', 'IN_REVIEW', 'DRAFT'])->orderBy('code')->limit(200)->get()->map(function ($p) use ($gate) {
            $r = $gate->evaluate($p);
            $failed = array_column(array_filter($r['checks'], fn ($c) => ! $c['passed']), 'code');

            return [$p->code.' v'.$p->version, $p->status, $r['mode'], $r['blocking'] ? 'blocking' : 'warning', $failed ? implode(', ', $failed) : 'all 16 checks pass'];
        })->all();

        return ['sections' => [['title' => 'Product document acceptance gate', 'description' => 'Blocking only for OPES_GENERATED issuance; carrier-document (manual upload) insurers pass with warnings.', 'headers' => ['Product', 'Status', 'Issuance', 'Gate', 'Failed checks'], 'rows' => $rows]]];
    }
}
