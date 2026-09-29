<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Application\Operations\QueueConsoleService;
use App\Filament\Shared\Actions\OperationsConsoleActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** REQ-OPS-001 failed jobs — GET operations/failed-jobs (operations.platform.view) via QueueConsoleService::failed; retry / forget. */
final class FailedJobs extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-server-crash';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'operations/failed-jobs';

    protected static array $permissions = ['operations.platform.view'];

    protected static string $screen = 'failed_jobs';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => self::keyed(app(QueueConsoleService::class)->failed(null, 100)->items(), 'uuid'))
            ->columns([
                TextColumn::make('failed_at')->label(self::col('failed_at'))->dateTime(),
                TextColumn::make('job')->label(self::col('job')),
                TextColumn::make('queue')->label(self::col('queue'))->badge(),
                TextColumn::make('attempts')->label(self::col('attempts')),
                TextColumn::make('exception')->label(self::col('exception'))->limit(80)->wrap(),
            ])
            ->recordActions([OperationsConsoleActions::failedJobRetry(), OperationsConsoleActions::failedJobForget()])
            ->emptyStateHeading(__('operations_actions.empty'));
    }
}
