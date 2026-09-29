<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Application\Rating\ChargeTableService;
use App\Filament\Shared\Actions\RatingActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * REQ-RAT-003 tax / levy / fee tables — GET rating/charge-tables/{kind} (rating.charges.view), both kinds in one list
 * (same query as RatingController::chargeTables). Create, approve and the maker-checker rate verification via RatingActions.
 */
final class ChargeTables extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-percent';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'rating/charge-tables';

    protected static array $permissions = ['rating.charges.view'];

    protected static string $screen = 'charge_tables';

    protected static string $group = 'Products & pricing';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $rows = [];
                foreach (ChargeTableService::TABLES as $kind => $t) {
                    foreach (DB::table($t)->orderByDesc('effective_from')->orderByDesc('version')->limit(200)->get() as $r) {
                        $rows[] = ['kind' => $kind, 'key' => $kind === 'tax' ? ($r->jurisdiction.' / '.$r->line_code) : ($r->code.($r->tenant_id ? ' *' : ''))]
                            + array_diff_key((array) $r, ['rules' => 1, 'verification_evidence' => 1]);
                    }
                }

                return self::keyed($rows);
            })
            ->columns([
                TextColumn::make('kind')->label(self::col('kind'))->badge()->formatStateUsing(fn ($state) => __('operations_actions.codes.kind.'.$state)),
                TextColumn::make('key')->label(self::col('key')),
                TextColumn::make('version')->label(self::col('version')),
                TextColumn::make('effective_from')->label(self::col('effective_from'))->date(),
                TextColumn::make('effective_until')->label(self::col('effective_until'))->date()->placeholder(__('operations_actions.open_ended')),
                TextColumn::make('status')->label(self::col('status'))->badge(),
                TextColumn::make('verification_status')->label(self::col('verification_status'))->badge()
                    ->color(fn ($state) => $state === 'VERIFIED' ? 'success' : 'warning'),
            ])
            ->headerActions([RatingActions::chargeTableCreate()])
            ->recordActions([RatingActions::chargeTableApprove(), RatingActions::chargeVerificationRequest(), RatingActions::chargeVerificationDecide()])
            ->emptyStateHeading(__('operations_actions.empty'));
    }
}
