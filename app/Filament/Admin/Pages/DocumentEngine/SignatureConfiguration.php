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

/** DOC-ADM-013 Signature configuration. */
final class SignatureConfiguration extends DocumentEngineReportPage
{
    protected static ?string $navigationLabel = 'Signature configuration';

    protected static ?int $navigationSort = 309;

    protected static ?string $slug = 'document-engine/signature';

    public function getTitle(): string
    {
        return 'Signature configuration (DOC-ADM-013)';
    }

    protected function report(): array
    {
        $rows = DocumentIssuanceProfile::with('carrier.party', 'product')->get()->map(fn ($p) => [$p->carrier?->party?->display_name, $p->product?->code ?? 'All products', $p->signature_mode, $p->signatory_name ?? '—', $p->signatory_title ?? '—'])->all();

        return ['sections' => [['title' => 'Signature per insurer / product', 'description' => 'Edit in Issuance rules. DIGITAL leaves documents PENDING_SIGNATURE until signed.', 'headers' => ['Insurer', 'Product', 'Mode', 'Signatory', 'Title'], 'rows' => $rows]]];
    }
}
