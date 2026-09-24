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

/** DOC-ADM-010 Product mapping. */
final class ProductDocumentMapping extends DocumentEngineReportPage
{
    protected static ?string $navigationLabel = 'Product mapping';

    protected static ?int $navigationSort = 306;

    protected static ?string $slug = 'document-engine/product-mapping';

    public function getTitle(): string
    {
        return 'Product mapping (DOC-ADM-010)';
    }

    protected function report(): array
    {
        $resolver = app(DocumentPackResolver::class);
        $rows = InsuranceProduct::with('carrier.party')->orderBy('code')->limit(300)->get()->map(function ($p) use ($resolver) {
            $nb = $resolver->resolveForProduct($p, 'POLICY_ISSUED');

            return [$p->code.' v'.$p->version, $p->carrier?->party?->display_name, $p->line_code, $nb['insurance_class'], $nb['pack_code'], count($nb['items']), \Illuminate\Support\Facades\Schema::hasTable('product_document_requirements') ? DB::table('product_document_requirements')->where('insurance_product_id', $p->id)->where('status', 'ACTIVE')->count() : 0];
        })->all();

        return ['sections' => [['title' => 'Product → class → pack', 'description' => 'Packs (DOC-ADM-005) and insurer requirement overrides (DOC-ADM-011) are maintained in the Document Catalogue screens (Document packs, Product document requirements); the engine reads them.', 'headers' => ['Product', 'Insurer', 'Line', 'Class', 'New-business pack', 'Items', 'Active insurer overrides'], 'rows' => $rows]]];
    }
}
