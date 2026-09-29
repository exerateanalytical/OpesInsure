<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Filament\Shared\Actions\LegalMatterActions;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Litigation matters — GET legal-matters (legal.matters.view). */
final class LegalMatters extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-gavel';

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'risk-transfer/legal-matters';

    protected static array $permissions = ['legal.matters.view'];

    protected static string $screen = 'legal_matters';

    protected static string $group = 'Claims operations';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn (string $t) => self::rows(DB::table('legal_matters')->where('tenant_id', $t)->orderByDesc('created_at')->limit(200)->get()),
            ['court' => 'text', 'court_reference' => 'text', 'role' => 'text', 'opposing_party_name' => 'text', 'claimed_amount_minor' => 'money', 'status' => 'status', 'outcome' => 'text'],
            [LegalMatterActions::legalOpen()],
            [LegalMatterActions::legalHearing(), LegalMatterActions::legalDeadline(), LegalMatterActions::legalCost(), LegalMatterActions::legalOutcome()]);
    }
}
