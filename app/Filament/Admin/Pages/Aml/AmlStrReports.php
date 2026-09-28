<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Aml;

use App\Application\Compliance\Aml\Str\StrService;
use App\Filament\Shared\Actions\AmlActions;
use App\Models\Party;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Suspicious transaction reports (DOS) — GET aml/str-reports via StrService::index. Visible only to holders of
 * cases.str.view (StrService::PERMISSION); for anyone else the screen does not exist (404, never listed), exactly as
 * the API treats an STR (tipping-off, Reg. 003-25). Draft / submit (four eyes) via AmlActions.
 */
final class AmlStrReports extends AmlPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-warning';

    protected static ?int $navigationSort = 43;

    protected static ?string $slug = 'aml/str-reports';

    protected static string $permission = StrService::PERMISSION;

    protected static string $screen = 'str';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->tenantId === null || auth()->user() === null) {
                    return [];
                }
                $rows = app(StrService::class)->index($this->tenantId, auth()->user());
                $names = Party::whereIn('id', array_values(array_filter(array_column($rows, 'party_id'))))->pluck('display_name', 'id')->all();

                return collect($rows)->mapWithKeys(fn ($r) => [$r['id'] => ['__key' => $r['id'], 'party' => $names[$r['party_id'] ?? ''] ?? null,
                    'status' => $r['status'], 'grounds' => $r['grounds'], 'regulator_reference' => $r['regulator_reference'],
                    'submitted_at' => $r['submitted_at'], 'created_at' => $r['created_at'], 'id' => $r['id']]])->all();
            })
            ->columns([
                TextColumn::make('created_at')->label(self::col('created_at'))->dateTime(),
                TextColumn::make('party')->label(self::col('party')),
                TextColumn::make('status')->label(self::col('status'))->badge()
                    ->formatStateUsing(fn ($state) => AmlActions::codes([(string) $state], 'str_status')[(string) $state] ?? $state),
                TextColumn::make('grounds')->label(self::col('grounds'))->limit(80)->wrap(),
                TextColumn::make('regulator_reference')->label(self::col('regulator_reference')),
                TextColumn::make('submitted_at')->label(self::col('submitted_at'))->dateTime(),
            ])
            ->headerActions([AmlActions::strDraft()])
            ->recordActions([AmlActions::strSubmit()])
            ->emptyStateHeading(__('aml_actions.empty'));
    }
}
