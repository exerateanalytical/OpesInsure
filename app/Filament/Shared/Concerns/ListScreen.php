<?php

declare(strict_types=1);

namespace App\Filament\Shared\Concerns;

use Filament\Actions\BulkAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * REQ-UI-002 / SSR §22 standard list screen, applied to an already-built
 * Filament table (adopting resources call ListScreen::apply($table) last):
 *  - search + sort + filters persisted per user session ("saved filters");
 *  - every column toggleable (column selection);
 *  - pagination 10/25/50/100;
 *  - bulk selection with CSV export of the visible columns;
 *  - distinct empty vs no-result states (EN/FR).
 * Error/failure states are the shared failure-state component (FailureState).
 */
final class ListScreen
{
    public static function apply(Table $table, ?string $exportName = null): Table
    {
        foreach ($table->getColumns() as $column) {
            if (! $column->isToggleable()) {
                $column->toggleable();
            }
        }

        return $table
            ->persistSearchInSession()
            ->persistSortInSession()
            ->persistFiltersInSession()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading(fn ($livewire) => self::filtered($livewire) ? __('web_experience.list.no_result_heading') : __('web_experience.list.empty_heading'))
            ->emptyStateDescription(fn ($livewire) => self::filtered($livewire) ? __('web_experience.list.no_result_description') : __('web_experience.list.empty_description'))
            ->pushToolbarActions([self::exportAction($table, $exportName)]);
    }

    public static function exportAction(Table $table, ?string $exportName = null): BulkAction
    {
        return BulkAction::make('exportCsv')
            ->label(__('web_experience.list.export'))
            ->icon('lucide-download')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, Table $table): StreamedResponse => self::csv($records, $table, $exportName ?? 'export'));
    }

    /** CSV of the currently visible columns' states for the selected records. */
    public static function csv(Collection $records, Table $table, string $name): StreamedResponse
    {
        /** @var list<Column> $columns */
        $columns = array_values($table->getVisibleColumns());

        return response()->streamDownload(function () use ($records, $columns): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, array_map(fn (Column $c) => (string) $c->getLabel(), $columns));
            foreach ($records as $record) {
                fputcsv($out, array_map(function (Column $c) use ($record): string {
                    $value = data_get($record, $c->getName());

                    return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
                }, $columns));
            }
            fclose($out);
        }, $name.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private static function filtered($livewire): bool
    {
        if ($livewire === null) {
            return false;
        }
        if (filled(rescue(fn () => $livewire->getTableSearch(), null, false))) {
            return true;
        }
        $filters = (array) ($livewire->tableFilters ?? []);
        array_walk_recursive($filters, function ($v) use (&$active): void {
            if (filled($v) && $v !== false) {
                $active = true;
            }
        });

        return (bool) ($active ?? false);
    }
}
