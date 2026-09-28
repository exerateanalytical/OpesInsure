<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Translation\Translator;

/**
 * Panel labels are translated by their English text ("Line code", "Security") through
 * resources/lang/fr.json. A dot-less key is also a lang group name, so on a case-insensitive
 * filesystem "Security" would return the whole security.php array. Group files are lower-case,
 * so a key that starts with a capital letter and resolves to an array is returned as text.
 */
final class LabelSafeTranslator extends Translator
{
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $line = parent::get($key, $replace, $locale, $fallback);

        return is_array($line) && is_string($key) && $key !== '' && ctype_upper($key[0]) && ! str_contains($key, '.')
            ? $this->makeReplacements($key, $replace)
            : $line;
    }
}
