<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Application\Temporal\TimezoneResolver;
use App\Application\WebExperiences\Money;
use App\Filament\Shared\Components\RecordInfolist;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * UI audit 2026-09-27: the ONE list-column convention for every panel.
 *  - money:  Money::display (FCFA, locale grouping), right-aligned;
 *  - dates:  shown in the viewer's timezone (user preference, else branch/tenant, else platform) via
 *            FilamentTimezone, so every existing ->date()/->dateTime() column follows it too;
 *  - status: badge coloured by RecordInfolist::color (same tones as detail pages), label humanised.
 * applyDefaults() is registered once per panel request (WebExperienceServiceProvider), so legacy
 * columns that only call ->badge() or ->dateTime() get the convention without being edited.
 */
final class Columns
{
    public static function money(string $name, string $currencyAttribute = 'currency', ?string $label = null): TextColumn
    {
        return TextColumn::make($name)->label($label)->alignEnd()
            ->formatStateUsing(fn ($state, $record) => Money::display($state === null ? null : (int) $state, data_get($record, $currencyAttribute) ?: 'XAF'));
    }

    public static function date(string $name, bool $withTime = true, ?string $label = null): TextColumn
    {
        $c = TextColumn::make($name)->label($label)->sortable();

        return $withTime ? $c->dateTime('d/m/Y H:i') : $c->date('d/m/Y');
    }

    public static function status(string $name = 'status', ?string $label = null): TextColumn
    {
        return TextColumn::make($name)->label($label)->badge()
            ->color(fn ($state) => RecordInfolist::color($state))
            ->formatStateUsing(fn ($state) => self::humanise($state));
    }

    public static function text(string $name, ?string $label = null): TextColumn
    {
        return TextColumn::make($name)->label($label)->placeholder('—');
    }

    public static function humanise(mixed $state): string
    {
        $v = $state instanceof \BackedEnum ? (string) $state->value : (string) $state;

        return $v === '' ? '—' : LocalizedResource::t(ucfirst(strtolower(str_replace('_', ' ', $v))));
    }

    /** Panel-wide defaults: viewer timezone for every date column, status tones for every badge column. */
    public static function applyDefaults(): void
    {
        // Lazy: resolved when a column renders, i.e. after the panel tenant middleware has run.
        FilamentTimezone::set(fn () => rescue(fn () => app(TimezoneResolver::class)->forUser(auth()->user()), null, false) ?: null);
        TextColumn::configureUsing(fn (TextColumn $c) => $c->color(fn ($state) => $c->isBadge() ? RecordInfolist::color($state) : null));
        // One FR/EN layer for every label (explicit or generated from the attribute name): the
        // English label is the key in resources/lang/fr.json, so untranslated resources still read in French.
        \Filament\Tables\Columns\Column::configureUsing(fn ($c) => $c->translateLabel());
        \Filament\Tables\Filters\BaseFilter::configureUsing(fn ($f) => $f->translateLabel());
        \Filament\Forms\Components\Field::configureUsing(fn ($f) => $f->translateLabel());
        \Filament\Infolists\Components\Entry::configureUsing(fn ($e) => $e->translateLabel());
        \Filament\Actions\Action::configureUsing(fn ($a) => $a->translateLabel());
        \Filament\Schemas\Components\Tabs\Tab::configureUsing(fn ($t) => $t->translateLabel());
        \Filament\Schemas\Components\Fieldset::configureUsing(fn ($f) => $f->translateLabel());
        \Filament\Schemas\Components\Wizard\Step::configureUsing(fn ($s) => $s->translateLabel());
        // Section headings are passed to make() before configureUsing runs, so translate the stored text.
        \Filament\Schemas\Components\Section::configureUsing(function (\Filament\Schemas\Components\Section $s): void {
            $heading = (fn () => $this->heading)->call($s);
            if (is_string($heading) && $heading !== '') {
                $s->heading(LocalizedResource::t($heading));
            }
        });
        // Every table: newest first when it declares no sort of its own, and the one EN/FR empty state
        // (resources that set their own heading/description/icon or defaultSort still override these).
        Table::configureUsing(fn (Table $t) => $t
            ->defaultSort(fn (Builder $query) => self::newestFirst($query))
            ->emptyStateHeading(fn () => __('web_experience.list.empty_heading'))
            ->emptyStateDescription(fn () => __('web_experience.list.empty_description'))
            ->emptyStateIcon('lucide-inbox')
            // Cameroon convention (EN and FR): dd/mm/yyyy, 24h clock; ->date()/->dateTime() without a format use these.
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i'));
        \Filament\Schemas\Schema::configureUsing(fn (\Filament\Schemas\Schema $s) => $s
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i'));
    }

    /** created_at DESC for timestamped models; null (key sort) otherwise. */
    public static function newestFirst(Builder $query): ?Builder
    {
        $model = $query->getModel();
        $column = $model->usesTimestamps() ? $model->getCreatedAtColumn() : null;

        return $column ? $query->orderByDesc($model->qualifyColumn($column)) : null;
    }
}
