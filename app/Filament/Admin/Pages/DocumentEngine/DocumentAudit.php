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

/** DOC-ADM-019 Audit. */
final class DocumentAudit extends DocumentEngineReportPage
{
    protected static ?string $navigationLabel = 'Audit';

    protected static ?int $navigationSort = 316;

    protected static ?string $slug = 'document-engine/audit';

    public function getTitle(): string
    {
        return 'Audit (DOC-ADM-019)';
    }

    protected function report(): array
    {
        $rows = DB::table('audit_log')->where('action', 'like', 'document.%')->orderByDesc('sequence')->limit(200)->get()
            ->map(fn ($a) => [$a->created_at, $a->action, $a->subject_type, $a->subject_id, $a->actor_id ?? 'system', $a->reason_code ?? '', mb_substr((string) $a->metadata, 0, 160)])->all();

        return ['sections' => [['title' => 'Document engine audit trail (latest 200)', 'description' => 'Hash-chained audit_log entries: generation, templates, carrier uploads, revoke/replace.', 'headers' => ['At', 'Action', 'Subject', 'ID', 'Actor', 'Reason', 'Details'], 'rows' => $rows]]];
    }
}
