<?php

declare(strict_types=1);

namespace App\Filament\Shared\Components;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * Overview-tab building blocks for core-record detail pages (used with
 * RecordShell::detailTabs). Labels come from web_experience.fields.* (EN/FR),
 * amounts are stored in minor units, statuses use the shared tone mapping.
 *
 *   CoreRecordOverview::section('policy', [
 *       CoreRecordOverview::text('policy_number', 'number')->copyable(),
 *       CoreRecordOverview::money('premium_minor'),
 *       CoreRecordOverview::date('coverage_ends_at', 'cover_end'),
 *   ])
 */
final class CoreRecordOverview
{
    /** @param  array<int, \Filament\Schemas\Components\Component>  $entries */
    public static function section(string $headingKey, array $entries, int $columns = 3): Section
    {
        return Section::make(__('web_experience.sections.'.$headingKey))->columns($columns)->columnSpanFull()->schema($entries);
    }

    public static function text(string $path, ?string $labelKey = null): TextEntry
    {
        return TextEntry::make($path)->label(self::label($path, $labelKey))->placeholder('—');
    }

    public static function status(string $path = 'status', ?string $labelKey = null): TextEntry
    {
        return self::text($path, $labelKey ?? 'status')->badge()->color(fn ($state) => RecordInfolist::color($state));
    }

    public static function money(string $path, string $currencyPath = 'currency', ?string $labelKey = null): TextEntry
    {
        return self::text($path, $labelKey)->state(function ($record) use ($path, $currencyPath) {
            $minor = data_get($record, $path);

            return $minor === null ? null : \App\Application\WebExperiences\Money::format((int) $minor, (string) (data_get($record, $currencyPath) ?: 'XAF'));
        });
    }

    public static function date(string $path, ?string $labelKey = null, bool $withTime = false): TextEntry
    {
        $e = self::text($path, $labelKey);

        return $withTime ? $e->dateTime() : $e->date();
    }

    private static function label(string $path, ?string $key): string
    {
        return __('web_experience.fields.'.($key ?? str_replace('.', '_', preg_replace('/_(minor|at|id)$/', '', $path))));
    }
}
