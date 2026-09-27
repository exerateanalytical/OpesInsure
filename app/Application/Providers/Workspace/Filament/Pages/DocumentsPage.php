<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Application\Providers\Workspace\ProviderAccess;
use App\Application\Providers\Workspace\ProviderDocumentService;
use App\Interfaces\Http\Errors\ApiProblemException;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Provider Portal screen "documents" (Gap-Free spec): the provider's engine-issued documents (ProviderDocumentService —
 * eligibility confirmation, preauthorization request/decision, GOP, admission/extension authorisations, EOB, settlement
 * statement, contract, tariff schedule) plus the GOP pack of its own preauthorizations (clinical roles only). Download
 * goes through ProviderDocumentService::downloadForProvider (scope, clinical restriction, audit); verification opens the
 * public /verify page for the document number.
 */
final class DocumentsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-folder';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'documents';

    protected static string $permission = 'provider.documents.view';

    protected static string $screen = 'documents';

    public ?string $type = null;

    public function extraView(): ?string
    {
        return 'provider-workspace.documents-filter';
    }

    /** @return list<string> */
    public function typeOptions(): array
    {
        return ProviderDocumentService::PROVIDER_TYPES;
    }

    public function download(string $id): ?StreamedResponse
    {
        try {
            return app(ProviderDocumentService::class)->downloadForProvider($this->user(), $this->scope(), $id);
        } catch (ApiProblemException $e) {
            $this->state = 'VALIDATION_FAILED';
            $this->stateMessage = $e->getMessage();

            return null;
        }
    }

    public function rowActions(array $row): array
    {
        if (! isset($row['id'])) {
            return [];
        }
        $a = [['label' => __('provider_workspace.ui.download'), 'action' => 'download', 'arg' => $row['id']]];
        if (! empty($row['document_number'])) {
            $a[] = ['label' => __('provider_workspace.ui.verify'), 'url' => route('public.verify', ['ref' => $row['document_number']])];
        }

        return $a;
    }

    protected function columns(): array
    {
        return ['document_type_code', 'document_number', 'title', 'status', 'issued_at', 'valid_from', 'valid_until'];
    }

    protected function rows(): array
    {
        $rows = app(ProviderDocumentService::class)->listForProvider($this->user(), $this->scope(), ['type' => $this->type ?: null]);
        if (app(ProviderAccess::class)->mayReadClinical($this->user(), $this->scope())) {
            $m = $this->ws()->preauthQuery($this->tenantId(), $this->user(), $this->scope())->whereNotNull('gop_manifest_id')->pluck('gop_manifest_id');
            $seen = array_column($rows, 'id');
            foreach (DB::table('documents')->whereIn('pack_manifest_id', $m)->whereIn('document_type_code', ProviderDocumentService::PROVIDER_TYPES)
                ->when($this->type, fn ($q, $t) => $q->where('document_type_code', $t))->limit(500)
                ->get(['id', 'document_type_code', 'document_number', 'title', 'status', 'issued_at', 'valid_from', 'valid_until']) as $d) {
                if (! in_array($d->id, $seen, true)) {
                    $rows[] = (array) $d;
                }
            }
        }

        return array_map(fn ($r) => array_intersect_key($r, array_flip(['id', 'document_type_code', 'document_number', 'title', 'status', 'issued_at', 'valid_from', 'valid_until'])), $rows);
    }
}
