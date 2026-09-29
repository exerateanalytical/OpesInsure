<?php

namespace App\Filament\Admin\Resources\UnderwritingCases\Pages;

use App\Filament\Admin\Resources\UnderwritingCases\UnderwritingCaseResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** Underwriting case list with the Q8 work views: UND-004 assigned to me, unassigned, awaiting information, overdue. */
final class ListUnderwritingCases extends ListRecords
{
    protected static string $resource = UnderwritingCaseResource::class;

    private const OPEN = ['QUEUED', 'IN_REVIEW', 'AWAITING_INFORMATION', 'DECISION_PENDING'];

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('uw_workbench.list.all')),
            'mine' => Tab::make(__('uw_workbench.list.mine'))->modifyQueryUsing(fn (Builder $query) => $query->where('assigned_to', auth()->id())->whereIn('status', self::OPEN)),
            'unassigned' => Tab::make(__('uw_workbench.list.unassigned'))->modifyQueryUsing(fn (Builder $query) => $query->whereNull('assigned_to')->whereIn('status', self::OPEN)),
            'awaiting' => Tab::make(__('uw_workbench.list.awaiting'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'AWAITING_INFORMATION')),
            'overdue' => Tab::make(__('uw_workbench.list.overdue'))->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', self::OPEN)->where('decision_due_at', '<', now())),
        ];
    }
}
