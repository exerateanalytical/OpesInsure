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

/** DOC-ADM-014 QR configuration. */
final class QrConfiguration extends DocumentEngineReportPage
{
    protected static ?string $navigationLabel = 'QR configuration';

    protected static ?int $navigationSort = 310;

    protected static ?string $slug = 'document-engine/qr';

    public function getTitle(): string
    {
        return 'QR configuration (DOC-ADM-014)';
    }

    protected function report(): array
    {
        $rows = DocumentIssuanceProfile::with('carrier.party', 'product')->get()->map(fn ($p) => [$p->carrier?->party?->display_name, $p->product?->code ?? 'All products', $p->qr_enabled, $p->qr_payload, rtrim((string) config('lifecycle.verify_url'), '/').'?code=…'])->all();

        return ['sections' => [['title' => 'QR verification per insurer / product', 'description' => 'Every verifiable document carries its verification code; the QR opens the public /verify page.', 'headers' => ['Insurer', 'Product', 'QR enabled', 'Payload', 'Target'], 'rows' => $rows]]];
    }
}
