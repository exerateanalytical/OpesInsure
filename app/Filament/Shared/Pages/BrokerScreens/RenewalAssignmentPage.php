<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Admin\Resources\Renewals\RenewalResource;
use App\Models\RenewalCase;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\{SelectFilter, TernaryFilter};
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Facades\DB;

/**
 * BRK-066 Renewal Assignment (WF-040): the open renewal cases of the book (DUE / CONTACTED / QUOTED) with who works
 * each one, the contact attempts and the due date, filterable by assignee and "unassigned"; a row opens the renewal
 * case (quote / link successor, RenewalService, renewals.manage). Rows: policies of the caller's book.
 * S3 2026-09-29: each open row can be (re)assigned or unassigned (RenewalActions::reassign → RenewalService::reassign,
 * POST renewals/{r}/assignment, renewals.manage + own book).
 */
final class RenewalAssignmentPage extends BrokerScreen
{
    protected static string $key = 'renewal_assignment';

    protected static ?string $slug = 'renewal-assignment';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-user-round-check';

    protected static ?int $navigationSort = 76;

    protected static ?string $group = 'Policy operations';

    protected static array $readPermissions = ['policies.read'];

    protected function query(): Builder
    {
        return RenewalCase::query()->with('policy.party')->where('renewal_cases.tenant_id', $this->tenant())
            ->whereIn('renewal_cases.policy_id', $this->visibleIds('policies'))
            ->whereIn('renewal_cases.status', ['DUE', 'CONTACTED', 'QUOTED']);
    }

    protected function defaultSort(): string
    {
        return 'due_on';
    }

    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        return parent::table($table)->defaultSort('due_on', 'asc');
    }

    protected function columns(): array
    {
        return [
            self::text('policy.policy_number', 'policy')->searchable(),
            self::text('policy.party.display_name', 'customer'),
            self::date('due_on', 'due_on', false),
            self::status(),
            TextColumn::make('assignee')->label(self::col('assignee'))->placeholder(__('broker_screens_b.renewal_assignment.unassigned'))
                ->state(fn (RenewalCase $record) => $record->assigned_to ? DB::table('users')->where('id', $record->assigned_to)->value('full_name') : null),
            TextColumn::make('contact_attempts')->label(self::col('contacts'))->alignEnd(),
            self::date('last_contacted_at', 'last_contacted_at'),
        ];
    }

    protected function filters(): array
    {
        return [
            TernaryFilter::make('assigned')->label(self::col('assignee'))
                ->trueLabel(__('broker_screens_b.renewal_assignment.assigned'))->falseLabel(__('broker_screens_b.renewal_assignment.unassigned'))
                ->queries(true: fn (Builder $q) => $q->whereNotNull('assigned_to'), false: fn (Builder $q) => $q->whereNull('assigned_to')),
            SelectFilter::make('assigned_to')->label(self::col('assignee'))
                ->options(fn () => DB::table('users')->whereIn('id', RenewalCase::query()->where('tenant_id', $this->tenant())->whereNotNull('assigned_to')->select('assigned_to'))->pluck('full_name', 'id')->all()),
        ];
    }

    public function kpis(): array
    {
        $q = fn () => $this->query();

        return [
            self::kpi('renewals_open', $q()->count(), 'primary'),
            self::kpi('renewals_unassigned', $q()->whereNull('assigned_to')->count(), 'warning'),
            self::kpi('renewals_due_14', $q()->where('due_on', '<=', now()->addDays(14)->toDateString())->count(), 'danger'),
        ];
    }

    protected function recordActions(): array
    {
        return [\App\Filament\Shared\Actions\RenewalActions::reassign()];
    }

    protected function recordlink(model $record): ?string
    {
        return self::viewUrl(RenewalResource::class, $record);
    }
}
