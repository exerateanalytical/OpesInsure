<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RegulatoryCrm;

use App\Application\Rules\Models\RuleSet;
use App\Filament\Shared\Actions\RuleSetActions;
use BackedEnum;
use Filament\Tables\Table;

/** REQ-RUL-002 rule sets — GET rule-sets (rules.view); author, validate, submit, approve/reject (four eyes), retire, simulate. */
final class RuleSets extends RegulatoryCrmPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-list-tree';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'rule-sets';

    protected static array $permissions = ['rules.view'];

    protected static string $screen = 'rule_sets';

    protected static string $group = 'Products & pricing';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->ready() ? self::keyed(RuleSet::query()->withCount('rules')->orderBy('code')->orderByDesc('version')->limit(500)
                ->get()->map(fn ($s) => $s->only(['id', 'code', 'version', 'domain', 'scope_type', 'line_code', 'operation', 'status', 'effective_from', 'effective_until', 'rules_count']))) : [])
            ->columns([
                self::col('code'), self::col('version'), self::col('domain'), self::col('scope_type'), self::col('rules_count'),
                self::col('status')->badge(), self::col('effective_from')->date(),
            ])
            ->headerActions([RuleSetActions::ruleSetCreate(), RuleSetActions::ruleSetValidate()])
            ->recordActions([RuleSetActions::ruleSetSubmit(), RuleSetActions::ruleSetApprove(), RuleSetActions::ruleSetReject(),
                RuleSetActions::ruleSetRetire(), RuleSetActions::ruleSetSimulate()])
            ->emptyStateHeading(__('regulatory_crm_actions.empty'));
    }
}
