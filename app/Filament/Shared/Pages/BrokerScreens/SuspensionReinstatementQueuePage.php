<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\PolicyServicingActions;
use App\Models\Policy;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-063 Suspension / Reinstatement Queue (WF-046, WF-047): suspended policies of the book with the open suspension
 * (reason, since, reinstatement requested or not). Request / decide reinstatement run PolicySuspensionService
 * (policies.reinstatement.request / .approve), exactly as POST policies/{p}/reinstatement[...].
 */
final class SuspensionReinstatementQueuePage extends PolicyScreen
{
    protected static string $key = 'suspension_queue';

    protected static ?string $slug = 'suspension-reinstatement-queue';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-pause-circle';

    protected static ?int $navigationSort = 73;

    protected function query(): Builder
    {
        return $this->policies(['SUSPENDED']);
    }

    private static function suspension(Policy $p): ?object
    {
        return DB::table('policy_suspensions')->where('policy_id', $p->id)->whereNull('ended_at')->orderByDesc('created_at')->first();
    }

    protected function columns(): array
    {
        return [
            self::text('policy_number', 'policy')->searchable()->copyable(),
            self::text('party.display_name', 'customer')->searchable(),
            TextColumn::make('suspension_status')->label(self::col('case_status'))->badge()->state(fn (Policy $record) => \App\Filament\Shared\Columns::humanise(self::suspension($record)?->status)),
            TextColumn::make('suspension_reason')->label(self::col('reason'))->state(fn (Policy $record) => self::suspension($record)?->reason_code ?? '—'),
            TextColumn::make('suspended_at')->label(self::col('suspended_at'))->state(fn (Policy $record) => ($s = self::suspension($record)) && $s->suspended_at ? \Illuminate\Support\Carbon::parse($s->suspended_at)->format('d/m/Y') : '—'),
            self::date('coverage_ends_at', 'coverage_ends_at', false),
        ];
    }

    protected function recordActions(): array
    {
        return [PolicyServicingActions::requestReinstatement(), PolicyServicingActions::decideReinstatement()];
    }
}
