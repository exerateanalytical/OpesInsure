<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Application\Temporal\TimezoneResolver;
use App\Application\WebExperiences\Money;
use App\Filament\Shared\Components\RecordInfolist;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;

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
            ->formatStateUsing(fn ($state, $record) => Money::display($state === null ? null : (int) $state, $record?->{$currencyAttribute} ?: 'XAF'));
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

        return $v === '' ? '—' : ucfirst(strtolower(str_replace('_', ' ', $v)));
    }

    /** Panel-wide defaults: viewer timezone for every date column, status tones for every badge column. */
    public static function applyDefaults(): void
    {
        // Lazy: resolved when a column renders, i.e. after the panel tenant middleware has run.
        FilamentTimezone::set(fn () => rescue(fn () => app(TimezoneResolver::class)->forUser(auth()->user()), null, false) ?: null);
        TextColumn::configureUsing(fn (TextColumn $c) => $c->color(fn ($state) => $c->isBadge() ? RecordInfolist::color($state) : null));
    }
}
