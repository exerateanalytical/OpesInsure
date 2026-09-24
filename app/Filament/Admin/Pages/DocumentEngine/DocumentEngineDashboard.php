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

/** DOC-ADM-001 Dashboard. */
final class DocumentEngineDashboard extends DocumentEngineReportPage
{
    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 300;

    protected static ?string $slug = 'document-engine';

    public function getTitle(): string
    {
        return 'Dashboard (DOC-ADM-001)';
    }

    protected function report(): array
    {
        $docs = DB::table('documents')->whereNotNull('document_type_code')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $tpl = DB::table('document_templates')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $awaiting = DocumentPackManifest::all()->sum(fn ($m) => count(array_filter($m->items ?? [], fn ($i) => $i['state'] === 'AWAITING_CARRIER_DOCUMENT')));
        $pending = DB::table('document_status_changes')->where('status', 'PENDING')->count();

        return ['cards' => [
            ['Issued documents (current)', $docs->only(DocumentRegister::CURRENT_STATUSES)->sum(), 'SUPERSEDED/REPLACED/REVOKED: '.$docs->only(['SUPERSEDED', 'REPLACED', 'REVOKED'])->sum()],
            ['Published templates', $tpl['PUBLISHED'] ?? 0, 'In draft/review: '.(($tpl['DRAFT'] ?? 0) + ($tpl['REVIEW'] ?? 0))],
            ['Awaiting carrier documents', $awaiting, 'Pack items the insurer must upload'],
            ['Pending revoke/replace', $pending, 'Maker-checker queue'],
            ['Carrier originals', DB::table('documents')->where('is_carrier_original', true)->count(), null],
            ['Pack manifests', DocumentPackManifest::count(), null],
            ['Register document types', count(app(DocumentRegister::class)->types()), 'Canonical Document Register'],
            ['Verification lookups (30d)', DB::table('public_verification_lookups')->whereNotNull('document_id')->where('occurred_at', '>=', now()->subDays(30))->count(), null],
        ], 'sections' => []];
    }
}
