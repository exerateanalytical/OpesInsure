<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Application\Providers\Workspace\ProviderDocumentService;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Document;
use BackedEnum;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Provider Portal screens "settlements" and "settlement_detail" (remittance: statement figures derived from the batch claims, per-claim lines). */
final class SettlementsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-receipt-text';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'settlements';

    protected static string $permission = 'provider.settlement.view';

    protected static string $screen = 'settlements';

    public function rowActions(array $row): array
    {
        if (! isset($row['id'])) {
            return [];
        }
        $a = [['label' => __('provider_workspace.ui.open'), 'action' => 'open', 'arg' => $row['id']]];
        if (($row['status'] ?? null) === 'PAID') {
            $a[] = ['label' => __('provider_workspace.ui.download_statement'), 'action' => 'downloadStatement', 'arg' => $row['id']];
        }

        return $a;
    }

    /** DOC-198 settlement statement of a paid batch, through ProviderDocumentService (provider scope, audit). */
    public function downloadStatement(string $batchId): ?StreamedResponse
    {
        $id = Document::where('provider_profile_id', $this->scope()->providerId)->where('document_type_code', 'PROVIDER_SETTLEMENT_STATEMENT')
            ->where('subject_key', 'provider-settlement:'.$batchId)->latest('created_at')->value('id');
        try {
            if ($id === null) {
                throw new ApiProblemException('DOCUMENT_NOT_FOUND', 404, __('provider_workspace.ui.statement_not_ready'));
            }

            return app(ProviderDocumentService::class)->downloadForProvider($this->user(), $this->scope(), $id);
        } catch (ApiProblemException $e) {
            $this->state = 'VALIDATION_FAILED';
            $this->stateMessage = $e->getMessage();

            return null;
        }
    }

    protected function detail(): ?array
    {
        $d = $this->ws()->settlement($this->user(), $this->scope(), $this->selected);

        return ['title' => __('provider_workspace.screens.settlement_detail').' '.($d['settlement']['batch_number'] ?? ''), 'cards' => $d['statement'],
            'rows' => array_map(fn ($l) => (array) $l, $d['lines'])];
    }

    protected function rows(): array
    {
        return $this->ws()->settlements($this->user(), $this->scope(), ['per_page' => 100])['data'];
    }
}
