<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Filament\Shared\Actions\IssuanceActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-058 Failed Issuance Queue (WF-084) + BRK-059 Issuance Exception Details: paid new-business proposals of the book
 * whose policy was not issued (issuance_exceptions, IssuanceQueueService). Each row opens the exception details
 * (kind, blockers, provider error, attempts, escalation, resolution, event history). Retry / escalate / resolve and the
 * scan run IssuanceQueueService (policies.issuance_queue.manage / .resolve), the same service as the issuance-queue API.
 * Rows: portal tenant + the proposals of the caller's book (PortalScope::narrowTable('proposals')).
 */
class FailedIssuanceQueuePage extends BrokerScreen
{
    protected static string $key = 'failed_issuance';

    protected static ?string $slug = 'failed-issuance-queue';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-warning';

    protected static ?int $navigationSort = 71;

    protected static ?string $group = 'Policy operations';

    protected static array $readPermissions = ['policies.read', 'policies.issuance_queue.view'];

    protected function renewals(): bool
    {
        return false;
    }

    protected function query(): Builder
    {
        return IssuanceException::query()->where('issuance_exceptions.tenant_id', $this->tenant())
            ->whereIn('issuance_exceptions.proposal_id', $this->visibleIds('proposals'))
            ->when($this->renewals(), fn ($q) => $q->whereNotNull('renewal_case_id'), fn ($q) => $q->whereNull('renewal_case_id'));
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('proposal')->label(self::col('proposal'))
                ->state(fn (IssuanceException $record) => DB::table('proposals')->where('id', $record->proposal_id)->value('proposal_number') ?? '—'),
            TextColumn::make('customer')->label(self::col('customer'))
                ->state(fn (IssuanceException $record) => DB::table('proposals as p')->join('parties as y', 'y.id', '=', 'p.party_id')->where('p.id', $record->proposal_id)->value('y.display_name') ?? '—'),
            self::status('kind', 'kind'),
            self::status(),
            self::text('reason_code', 'reason'),
            TextColumn::make('attempts')->label(self::col('attempts'))->alignEnd(),
            self::date('last_attempt_at', 'last_attempt_at'),
            self::date('created_at', 'created_at'),
        ];
    }

    protected function filters(): array
    {
        return [SelectFilter::make('status')->label(self::col('status'))
            ->options(fn () => IssuanceException::query()->where('tenant_id', $this->tenant())->distinct()->pluck('status', 'status')->map(fn ($s) => \App\Filament\Shared\Columns::humanise($s))->all())];
    }

    protected function headerActions(): array
    {
        return [IssuanceActions::exceptionScan()];
    }

    protected function recordActions(): array
    {
        return [self::details(), IssuanceActions::exceptionRetry(), IssuanceActions::exceptionEscalate(), IssuanceActions::exceptionResolve()];
    }

    /** BRK-059 Issuance Exception Details (read-only slide-over; the actions stay on the row). */
    public static function details(): Action
    {
        $t = fn (string $name, string $label) => TextEntry::make($name)->label(self::col($label))->placeholder('—');

        return Action::make('exceptionDetails')->label(__('broker_screens_b.failed_issuance.details'))->icon('lucide-eye')->color('gray')
            ->slideOver()->modalSubmitAction(false)->modalCancelActionLabel(__('broker_screens_b.close'))
            ->modalHeading(__('broker_screens_b.failed_issuance.details'))
            ->schema([
                $t('kind', 'kind'), $t('status', 'status'), $t('reason_code', 'reason'),
                TextEntry::make('blockers')->label(self::col('blockers'))->placeholder('—')
                    ->state(fn (IssuanceException $record) => collect((array) ($record->blockers ?? []))->map(fn ($b) => is_scalar($b) ? (string) $b : json_encode($b))->implode(' · ') ?: null),
                $t('error_message', 'error'), $t('territory', 'territory'), $t('attempts', 'attempts'),
                TextEntry::make('last_attempt_at')->label(self::col('last_attempt_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('escalated_at')->label(self::col('escalated_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                $t('resolution', 'resolution'), $t('resolution_notes', 'notes'),
                TextEntry::make('customer_notified_at')->label(self::col('customer_notified_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('events')->label(self::col('history'))->placeholder('—')->listWithLineBreaks()
                    ->state(fn (IssuanceException $record) => DB::table('issuance_exception_events')->where('issuance_exception_id', $record->id)->orderBy('occurred_at')->limit(50)->get()
                        ->map(fn ($e) => \Illuminate\Support\Carbon::parse($e->occurred_at)->format('d/m/Y H:i').' · '.\App\Filament\Shared\Columns::humanise($e->action)
                            .($e->to_status ? ' → '.\App\Filament\Shared\Columns::humanise($e->to_status) : ''))->all()),
            ]);
    }
}
