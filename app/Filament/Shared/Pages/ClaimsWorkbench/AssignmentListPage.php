<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\ClaimsWorkbench;

use App\Application\Claims\Adjusters\AdjusterWorkbench;
use App\Application\WebExperiences\Money;
use App\Filament\Shared\Components\RecordInfolist;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

/** The caller's own expert assignments (ExpertAssignmentService::forAdjuster), narrowed to the portal carrier. */
abstract class AssignmentListPage extends WorkbenchPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    /** @var list<string> */
    protected static array $statuses = [];

    #[Url]
    public ?string $status = null;

    public function table(Table $table): Table
    {
        $statuses = static::$statuses;

        return $table
            ->records(function (?array $filters = null) use ($statuses): array {
                $user = auth()->user();
                if ($this->tenantId === null || ! $user instanceof User) {
                    return [];
                }
                $want = $filters['status']['value'] ?? null;
                $only = filled($want) && in_array($want, $statuses, true) ? [$want] : $statuses;
                $out = [];
                foreach (app(AdjusterWorkbench::class)->assignments($this->tenantId, $user, $only) as $r) {
                    $r = (array) $r;
                    $out[(string) $r['id']] = ['__key' => (string) $r['id'], 'fee' => Money::format($r['fee_amount_minor'] === null ? null : (int) $r['fee_amount_minor'], $r['fee_currency'])] + $r;
                }

                return $out;
            })
            ->columns([
                TextColumn::make('claim_number')->label(self::t('fields.claim_number'))->searchable(false),
                TextColumn::make('status')->label(self::t('fields.status'))->badge()->color(fn ($state) => RecordInfolist::color($state))
                    ->formatStateUsing(fn ($state) => self::t('stages.'.$state)),
                TextColumn::make('assigned_at')->label(self::t('fields.assigned_at'))->dateTime(),
                TextColumn::make('loss_occurred_at')->label(self::t('fields.loss_occurred_at'))->dateTime()->toggleable(),
                TextColumn::make('loss_location')->label(self::t('fields.location'))->limit(40),
                TextColumn::make('inspection_scheduled_for')->label(self::t('fields.inspection_scheduled_for'))->dateTime()->placeholder('—'),
                TextColumn::make('report_submitted_at')->label(self::t('fields.report_submitted_at'))->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('fee')->label(self::t('fields.fee')),
            ])
            ->filters([
                SelectFilter::make('status')->label(self::t('fields.status'))
                    ->options(collect($statuses)->mapWithKeys(fn ($s) => [$s => self::t('stages.'.$s)])->all())
                    ->default(in_array($this->status, $statuses, true) ? $this->status : null),
            ])
            ->recordUrl(fn (array $record) => AssignmentWorkbench::getUrl(['assignment' => $record['id']]))
            ->emptyStateHeading(self::t('empty'));
    }
}
