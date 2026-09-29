<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\StickerCustodyActions;
use App\Models\RegisterRow;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-076 Sticker Reconciliation (WF-032): the physical sticker counts of the brokerage (sticker_reconciliations of the
 * portal tenant: expected vs counted, missing / unexpected / damaged serials, status). A new count runs
 * StickerCustodyService::reconcile (stickers.reconcile), the same service as the sticker custody API.
 */
final class StickerReconciliationPage extends BrokerScreen
{
    protected static string $key = 'sticker_reconciliation';

    protected static ?string $slug = 'sticker-reconciliation';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-clipboard-check';

    protected static ?int $navigationSort = 86;

    protected static ?string $group = 'Policy operations';

    protected static array $readPermissions = ['stickers.view', 'stickers.reconcile'];

    protected function query(): Builder
    {
        return RegisterRow::on_('sticker_reconciliations')->where('sticker_reconciliations.tenant_id', $this->tenant());
    }

    protected function defaultSort(): string
    {
        return 'performed_at';
    }

    private static function count(mixed $list): int
    {
        $list = is_string($list) ? json_decode($list, true) : $list;

        return is_array($list) ? count($list) : 0;
    }

    protected function columns(): array
    {
        return [
            self::date('performed_at', 'performed_at'),
            self::status('level', 'custody_level'),
            TextColumn::make('holder')->label(self::col('holder'))->placeholder('—')->state(fn ($record) => match (true) {
                $record->user_id !== null => DB::table('users')->where('id', $record->user_id)->value('full_name'),
                $record->branch_id !== null => DB::table('tenant_branches')->where('id', $record->branch_id)->value('name'),
                default => null,
            }),
            TextColumn::make('expected_count')->label(self::col('expected'))->alignEnd(),
            TextColumn::make('counted_count')->label(self::col('counted'))->alignEnd(),
            TextColumn::make('missing')->label(self::col('missing'))->alignEnd()->state(fn ($record) => self::count($record->missing_serials)),
            TextColumn::make('unexpected')->label(self::col('unexpected'))->alignEnd()->state(fn ($record) => self::count($record->unexpected_serials)),
            TextColumn::make('damaged')->label(self::col('damaged'))->alignEnd()->state(fn ($record) => self::count($record->damaged_serials)),
            self::status(),
            TextColumn::make('performer')->label(self::col('performed_by'))->placeholder('—')
                ->state(fn ($record) => $record->performed_by ? DB::table('users')->where('id', $record->performed_by)->value('full_name') : null),
        ];
    }

    protected function headerActions(): array
    {
        return [StickerCustodyActions::reconcile()];
    }
}
