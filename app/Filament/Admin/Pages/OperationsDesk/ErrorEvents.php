<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\Rbac\PlatformAuthority;
use App\Filament\Shared\Actions\WorkflowAction;
use App\Models\ErrorEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * S12 Errors — unhandled exceptions grouped by fingerprint (ErrorEventRecorder). PLATFORM tenant only (error events are
 * platform-wide, no tenant column); read with operations.platform.view, resolve / ignore / reopen with
 * operations.incidents.manage (audited). Labels: resources/lang/{en,fr}/monitoring.php.
 */
final class ErrorEvents extends OperationsDeskPage
{
    public const MANAGE = 'operations.incidents.manage';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-bug';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'operations/errors';

    protected static array $permissions = ['operations.platform.view'];

    protected static string $screen = 'errors';

    public static function canAccess(): bool
    {
        return parent::canAccess() && (bool) rescue(fn () => app(PlatformAuthority::class)->isPlatformTenant(), false, false);
    }

    public static function getNavigationLabel(): string
    {
        return __('monitoring.errors.nav');
    }

    public function getTitle(): string
    {
        return __('monitoring.errors.title');
    }

    public function getSubheading(): ?string
    {
        return __('monitoring.errors.subtitle');
    }

    public function table(Table $table): Table
    {
        $c = fn (string $k): string => __('monitoring.errors.columns.'.$k);

        return $table
            ->query(ErrorEvent::query())
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('status')->label($c('status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('monitoring.errors.status.'.$state))
                    ->color(fn (string $state): string => match ($state) { ErrorEvent::OPEN => 'danger', ErrorEvent::RESOLVED => 'success', default => 'gray' }),
                TextColumn::make('exception_class')->label($c('exception'))->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->description(fn (ErrorEvent $r): string => (string) $r->message)->wrap()->searchable(['exception_class', 'message']),
                TextColumn::make('occurrences')->label($c('occurrences'))->numeric()->sortable(),
                TextColumn::make('route')->label($c('route'))->placeholder('—')->toggleable(),
                TextColumn::make('file')->label($c('location'))->formatStateUsing(fn (ErrorEvent $r): string => $r->file.':'.$r->line)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('first_seen_at')->label($c('first_seen'))->since()->sortable(),
                TextColumn::make('last_seen_at')->label($c('last_seen'))->since()->sortable(),
                TextColumn::make('release_id')->label($c('release'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label($c('status'))->default(ErrorEvent::OPEN)
                    ->options(collect([ErrorEvent::OPEN, ErrorEvent::RESOLVED, ErrorEvent::IGNORED])->mapWithKeys(fn ($s) => [$s => __('monitoring.errors.status.'.$s)])->all()),
            ])
            ->recordActions([
                $this->transition('errorResolve', ErrorEvent::RESOLVED, 'lucide-check', 'success'),
                $this->transition('errorIgnore', ErrorEvent::IGNORED, 'lucide-eye-off', 'gray'),
                $this->transition('errorReopen', ErrorEvent::OPEN, 'lucide-rotate-ccw', 'warning'),
            ])
            ->emptyStateHeading(__('monitoring.errors.empty'));
    }

    private function transition(string $name, string $to, string $icon, string $color): Action
    {
        return Action::make($name)->label(__('monitoring.errors.actions.'.$name))->icon($icon)->color($color)->requiresConfirmation()
            ->visible(fn (ErrorEvent $record): bool => $record->status !== $to && WorkflowAction::allowed(self::MANAGE))
            ->action(function (ErrorEvent $record) use ($to, $name): void {
                abort_unless(WorkflowAction::allowed(self::MANAGE), 403);
                $record->update(['status' => $to, 'resolved_at' => $to === ErrorEvent::OPEN ? null : now(), 'resolved_by' => $to === ErrorEvent::OPEN ? null : auth()->id()]);
                app(AuditWriter::class)->record('monitoring.error.'.strtolower($to), 'error_event', $record->id, ['fingerprint' => $record->fingerprint]);
                Notification::make()->title(__('monitoring.errors.actions.done'))->success()->send();
            });
    }
}
