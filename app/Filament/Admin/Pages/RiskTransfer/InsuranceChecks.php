<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Filament\Shared\Actions\InsuranceCheckActions;
use Filament\Tables\Table;

/**
 * Stateless insurance checks (eligibility, completeness, tariff preview) — POST insurance/eligibility|completeness/check
 * (rules.evaluate) and POST insurance/rate (quotes.rate). Nothing is listed; each check shows its result once.
 */
final class InsuranceChecks extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-list-checks';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'risk-transfer/insurance-checks';

    protected static array $permissions = ['rules.evaluate', 'quotes.rate'];

    protected static string $screen = 'insurance_checks';

    protected static string $group = 'Operations';

    public function table(Table $table): Table
    {
        return $this->workbench($table, fn () => [], [],
            [InsuranceCheckActions::checkEligibility(), InsuranceCheckActions::checkCompleteness(), InsuranceCheckActions::checkRate()])
            ->emptyStateHeading(__('risk_transfer_actions.checks_empty'));
    }
}
