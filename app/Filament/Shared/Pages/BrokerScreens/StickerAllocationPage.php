<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\StickerCustodyActions;
use App\Models\StickerStock;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-075 Sticker Allocation (WF-032, WF-033): the motor stickers held by the brokerage (sticker_stock custodied by the
 * portal tenant: brokerage, branch or agent level), with custody level, holder and the policy each used sticker was
 * assigned to; plus the pending handovers. Hand over stickers to a branch / agent and accept / reject a handover run
 * StickerCustodyService (stickers.handover), the same service as the sticker custody API.
 */
final class StickerAllocationPage extends BrokerScreen
{
    protected static string $key = 'sticker_allocation';

    protected static ?string $slug = 'sticker-allocation';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-sticker';

    protected static ?int $navigationSort = 85;

    protected static ?string $group = 'Policy operations';

    protected static array $readPermissions = ['stickers.view', 'stickers.handover', 'stickers.allocate'];

    protected function query(): Builder
    {
        return StickerStock::query()->where('sticker_stock.custodian_tenant_id', $this->tenant());
    }

    protected function defaultSort(): string
    {
        return 'updated_at';
    }

    protected function columns(): array
    {
        return [
            self::text('serial_number', 'serial')->searchable()->copyable(),
            self::text('batch_number', 'batch')->searchable(),
            self::status(),
            self::status('custody_level', 'custody_level'),
            TextColumn::make('holder')->label(self::col('holder'))->placeholder('—')->state(fn (StickerStock $record) => match (true) {
                $record->custodian_user_id !== null => DB::table('users')->where('id', $record->custodian_user_id)->value('full_name'),
                $record->custodian_branch_id !== null => DB::table('tenant_branches')->where('id', $record->custodian_branch_id)->value('name'),
                default => null,
            }),
            TextColumn::make('policy')->label(self::col('policy'))->placeholder('—')
                // R1: the policy number only for a policy inside the caller's book (the stock list is tenant-wide).
                ->state(fn (StickerStock $record) => $record->assigned_policy_id && \App\Application\WebExperiences\PortalScope::visibleOf('policies', [(string) $record->assigned_policy_id]) !== [] ? DB::table('policies')->where('id', $record->assigned_policy_id)->value('policy_number') : null),
            self::date('assigned_at', 'assigned_at'),
        ];
    }

    protected function filters(): array
    {
        return [
            SelectFilter::make('status')->label(self::col('status'))
                ->options(fn () => $this->query()->reorder()->distinct()->pluck('status', 'status')->map(fn ($s) => \App\Filament\Shared\Columns::humanise($s))->all()),
            SelectFilter::make('custody_level')->label(self::col('custody_level'))
                ->options(fn () => $this->query()->reorder()->distinct()->whereNotNull('custody_level')->pluck('custody_level', 'custody_level')->map(fn ($s) => \App\Filament\Shared\Columns::humanise($s))->all()),
        ];
    }

    protected function headerActions(): array
    {
        return [StickerCustodyActions::handover(), StickerCustodyActions::decideHandover()];
    }

    public function kpis(): array
    {
        $q = fn () => $this->query();

        return [
            self::kpi('stickers_in_stock', $q()->where('status', 'IN_STOCK')->count(), 'success'),
            self::kpi('stickers_assigned', $q()->whereNotNull('assigned_policy_id')->count()),
            self::kpi('stickers_at_agents', $q()->where('custody_level', 'AGENT')->count()),
            self::kpi('handovers_pending', DB::table('sticker_handovers')->where('status', 'PENDING')
                ->where(fn ($w) => $w->where('from_tenant_id', $this->tenant())->orWhere('to_tenant_id', $this->tenant()))->count(), 'warning'),
        ];
    }
}
