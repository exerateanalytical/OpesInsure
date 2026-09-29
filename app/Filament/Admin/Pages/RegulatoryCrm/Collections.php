<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RegulatoryCrm;

use App\Filament\Shared\Actions\CollectionActions;
use BackedEnum;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** REQ-REC-003 collections worklist — GET collections (collections.view): active accounts of this tenant, keyed by obligation. */
final class Collections extends RegulatoryCrmPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-hand-coins';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'collections';

    protected static array $permissions = ['collections.view'];

    protected static string $screen = 'collections';

    protected static string $group = 'Financial operations';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->ready() ? self::keyed(DB::table('collection_accounts as a')->join('financial_obligations as o', 'o.id', '=', 'a.financial_obligation_id')
                ->where('a.tenant_id', $this->tenantId)->where('a.status', 'ACTIVE')->orderBy('o.due_at')->limit(500)
                ->get(['a.financial_obligation_id', 'a.stage', 'a.case_id', 'o.type', 'o.outstanding_minor', 'o.currency', 'o.due_at']), 'financial_obligation_id') : [])
            ->columns([
                self::col('type'), self::col('outstanding_minor')->numeric(), self::col('currency'), self::col('due_at')->date(), self::col('stage')->badge(),
            ])
            ->headerActions([CollectionActions::collectionsRun()])
            ->recordActions([CollectionActions::collectionPromise(), CollectionActions::collectionEscalate(), CollectionActions::writeOffRequest(),
                CollectionActions::writeOffApprove(), CollectionActions::writeOffReject()])
            ->emptyStateHeading(__('regulatory_crm_actions.empty'));
    }
}
