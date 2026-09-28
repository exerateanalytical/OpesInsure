<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use Filament\Resources\Resource;
use Illuminate\Support\Str;

use function Filament\Support\get_model_label;

/**
 * Base for every panel resource: page titles, breadcrumbs and buttons ("New quote") use the model
 * label, so it goes through the same FR/EN layer as column labels (resources/lang/fr.json, keyed by
 * the English label). French labels keep sentence case instead of Filament's English Title Case.
 */
abstract class LocalizedResource extends Resource
{
    public static function getModelLabel(): string
    {
        return self::t(static::$modelLabel ?? static::getLabel() ?? get_model_label(static::getModel()));
    }

    public static function getPluralModelLabel(): string
    {
        $english = static::$pluralModelLabel ?? static::getPluralLabel()
            ?? Str::plural(static::$modelLabel ?? static::getLabel() ?? get_model_label(static::getModel()));

        return self::t($english);
    }

    public static function getTitleCaseModelLabel(): string
    {
        return self::caseFor(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return self::caseFor(static::getPluralModelLabel());
    }

    /** JSON-only lookup: a label such as "quotes" must never resolve to the quotes.php group. */
    public static function t(string $label): string
    {
        $out = __($label);
        if (is_string($out) && $out !== $label) {
            return $out;
        }
        // Model labels are lower case ("quote"); fr.json keys are sentence case ("Quote").
        $key = Str::ucfirst($label);
        $out = __($key);
        if (! is_string($out) || $out === $key) {
            return $label;
        }

        return $label === $key || mb_strtoupper(mb_substr($out, 1, 1)) === mb_substr($out, 1, 1) ? $out : Str::lcfirst($out);
    }

    private static function caseFor(string $label): string
    {
        if (! static::hasTitleCaseModelLabel()) {
            return $label;
        }

        return app()->getLocale() === 'en' ? Str::ucwords($label) : Str::ucfirst($label);
    }
}
