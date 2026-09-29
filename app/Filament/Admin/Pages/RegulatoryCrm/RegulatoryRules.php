<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RegulatoryCrm;

use App\Filament\Shared\Actions\RegulatoryReturnActions;
use BackedEnum;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** REQ-RPT-002 regulatory change rules — GET regulatory/rules (regulatory.rules.view); draft → review → approve → activate. */
final class RegulatoryRules extends RegulatoryCrmPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-scale';

    protected static ?int $navigationSort = 51;

    protected static ?string $slug = 'regulatory/rules';

    protected static array $permissions = ['regulatory.rules.view'];

    protected static string $screen = 'rules';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->ready() ? self::keyed(DB::table('regulatory_rules')->orderBy('code')->orderByDesc('version')->limit(500)
                ->get(['id', 'jurisdiction', 'code', 'version', 'title', 'rule_type', 'status', 'effective_from', 'effective_until'])) : [])
            ->columns([
                self::col('code'), self::col('version'), self::col('title')->wrap(), self::col('rule_type'),
                self::col('status')->badge(), self::col('effective_from')->date(), self::col('effective_until')->date(),
            ])
            ->headerActions([RegulatoryReturnActions::ruleDraft()])
            ->recordActions([RegulatoryReturnActions::ruleReview(), RegulatoryReturnActions::ruleApprove(), RegulatoryReturnActions::ruleActivate()])
            ->emptyStateHeading(__('regulatory_crm_actions.empty'));
    }
}
