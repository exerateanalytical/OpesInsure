<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\PolicyActions;
use App\Models\Policy;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-062 Cancellation Queue (WF-045): policies of the book with an open cancellation case (policy_cancellations
 * REQUESTED / UNDER_REVIEW), with the reason, requested effective date and refund basis. Review and approve / reject run
 * CancellationService::review / approve / reject (policies.cancellation.review / .approve), as the cancellation API.
 */
final class CancellationQueuePage extends PolicyScreen
{
    protected static string $key = 'cancellation_queue';

    protected static ?string $slug = 'cancellation-queue';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-x-2';

    protected static ?int $navigationSort = 72;

    private const OPEN = ['REQUESTED', 'UNDER_REVIEW'];

    protected function query(): Builder
    {
        return $this->policies()->whereIn('policies.id', DB::table('policy_cancellations')->whereIn('status', self::OPEN)->select('policy_id'));
    }

    private static function case(Policy $p): ?object
    {
        return DB::table('policy_cancellations')->where('policy_id', $p->id)->whereIn('status', self::OPEN)->orderByDesc('created_at')->first();
    }

    protected function columns(): array
    {
        return [
            self::text('policy_number', 'policy')->searchable()->copyable(),
            self::text('party.display_name', 'customer')->searchable(),
            TextColumn::make('case_status')->label(self::col('case_status'))->badge()->state(fn (Policy $record) => \App\Filament\Shared\Columns::humanise(self::case($record)?->status)),
            TextColumn::make('case_reason')->label(self::col('reason'))->state(fn (Policy $record) => self::case($record)?->reason_code ?? '—'),
            TextColumn::make('case_effective')->label(self::col('effective_at'))->state(fn (Policy $record) => ($c = self::case($record)) && $c->effective_at ? \Illuminate\Support\Carbon::parse($c->effective_at)->format('d/m/Y') : '—'),
            TextColumn::make('case_refund')->label(self::col('refund'))->alignEnd()
                ->state(fn (Policy $record) => ($c = self::case($record)) && $c->refund_minor !== null ? \App\Application\WebExperiences\Money::display((int) $c->refund_minor, $c->currency ?: 'XAF') : '—'),
            TextColumn::make('case_requested')->label(self::col('requested_at'))->state(fn (Policy $record) => ($c = self::case($record)) ? \Illuminate\Support\Carbon::parse($c->created_at)->format('d/m/Y H:i') : '—'),
        ];
    }

    protected function recordActions(): array
    {
        return [PolicyActions::reviewCancellation(), PolicyActions::decideCancellation()];
    }
}
