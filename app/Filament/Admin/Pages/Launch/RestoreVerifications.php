<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Launch;

use App\Models\RecoveryExercise;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** OPS-018 Backup & recovery — GET operations/restore-verifications (operations.console.view): the last 50 BACKUP_RESTORE exercises and the RPO/RTO targets. */
final class RestoreVerifications extends LaunchScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-database-backup';

    protected static ?int $navigationSort = 81;

    protected static ?string $slug = 'operations/backup-recovery';

    protected static array $permissions = ['operations.console.view'];

    protected static string $screen = 'backup_recovery';

    public function getSubheading(): ?string
    {
        return __('launch_screens.intro.backup_recovery', [
            'rpo' => (string) (config('operations.dr.target_rpo_minutes') ?? '—'),
            'rto' => (string) (config('operations.dr.target_rto_minutes') ?? '—'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => self::keyed(
                RecoveryExercise::query()->where('exercise_type', 'BACKUP_RESTORE')->latest('created_at')->limit(50)->get()
                    ->map(fn ($e) => $e->only(['id', 'environment', 'status', 'created_at', 'conducted_at', 'actual_rto_minutes', 'actual_rpo_minutes', 'target_rto_minutes', 'target_rpo_minutes']))
            ))
            ->columns([
                TextColumn::make('conducted_at')->label(self::col('occurred_at'))->dateTime()->placeholder('—'),
                TextColumn::make('environment')->label(self::col('environment'))->badge(),
                TextColumn::make('status')->label(self::col('status'))->badge(),
                TextColumn::make('actual_rto_minutes')->label(self::col('rto'))->placeholder('—')
                    ->formatStateUsing(fn ($state, $record) => $state.' / '.($record['target_rto_minutes'] ?? '—')),
                TextColumn::make('actual_rpo_minutes')->label(self::col('rpo'))->placeholder('—')
                    ->formatStateUsing(fn ($state, $record) => $state.' / '.($record['target_rpo_minutes'] ?? '—')),
            ])
            ->emptyStateHeading(__('launch_screens.empty'));
    }
}
