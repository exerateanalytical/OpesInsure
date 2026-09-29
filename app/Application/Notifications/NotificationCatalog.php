<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Customer notification copy in every supported language.
 *
 * Producers pass a stable code plus parameters (message()); the English text
 * is still stored on user_notifications.title/body (old app versions, audit,
 * de-duplication) and the code + params next to it. Readers render the row in
 * the reader's language (localize()): the mobile inbox in the app language
 * (Accept-Language), push and SMS in users.locale. Rows written before codes
 * existed are recognised from their English text so they translate too.
 *
 * Copy lives in resources/lang/{en,fr}/customer_notifications.php.
 */
final class NotificationCatalog
{
    public const LOCALES = ['en', 'fr'];

    private const FILE = 'customer_notifications';

    /** English stand-ins older producers wrote when a value was missing (legacy rows only). */
    private const LEGACY_BLANKS = ['product' => ['your cover', 'your policy'], 'device' => ['a new device']];

    /** Parameters holding an ISO date, shown as a localised day-month-year. */
    private const DATE_PARAMS = ['starts', 'ends', 'effective'];

    /** Single-token values (numbers, references): matched without spaces when recognising legacy text. */
    private const TOKEN_PARAMS = ['policy', 'claim', 'request', 'reference', 'count', 'days', 'amount', 'refund', 'currency'];

    /**
     * Named arguments for CustomerNotifier::toParty()/toUser():
     * `$notifier->toParty($party, $tenant, 'PAYMENT', ...NotificationCatalog::message('payment_failed'), severity: 'ERROR')`.
     *
     * @return array{title: string, body: string, code: string, params: array<string, scalar|null>}
     */
    public static function message(string $code, array $params = []): array
    {
        $en = self::render($code, $params, 'en') ?? ['title' => $code, 'body' => ''];

        return ['title' => $en['title'], 'body' => $en['body'], 'code' => $code, 'params' => $params];
    }

    /** @return array{title: string, body: string}|null null for an unknown code */
    public static function render(string $code, array $params, string $locale): ?array
    {
        $locale = self::locale($locale);
        if ($code === '' || str_starts_with($code, '_') || ! Lang::has(self::FILE.".{$code}.title", 'en', false)) {
            return null;
        }
        $replace = self::prepare($params, $locale);
        $line = fn (string $part) => (string) Lang::get(self::FILE.".{$code}.{$part}", $replace, Lang::has(self::FILE.".{$code}.{$part}", $locale, false) ? $locale : 'en');

        return ['title' => $line('title'), 'body' => $line('body')];
    }

    /**
     * The row as the reader should see it. Unknown codes and unrecognised
     * legacy text keep the stored (English) title and body.
     *
     * @return array{title: string, body: string, code: ?string, params: array}
     */
    public static function localize(?string $code, ?array $params, string $title, string $body, string $locale): array
    {
        if (! $code) {
            $known = self::recognise($title, $body);
            $code = $known['code'] ?? null;
            $params = $known['params'] ?? null;
        }
        $rendered = $code ? self::render($code, $params ?? [], $locale) : null;

        return [
            'title' => $rendered['title'] ?? $title,
            'body' => $rendered['body'] ?? $body,
            'code' => $code,
            'params' => $params ?? [],
        ];
    }

    /**
     * Reverse lookup of a legacy (code-less) row from its English text.
     *
     * @return array{code: string, params: array<string, string|null>}|null
     */
    public static function recognise(string $title, string $body): ?array
    {
        foreach ((array) Lang::get(self::FILE, [], 'en') as $code => $copy) {
            if (str_starts_with((string) $code, '_') || ! is_array($copy)) {
                continue;
            }
            $t = self::match((string) $copy['title'], $title);
            $b = $t === null ? null : self::match((string) $copy['body'], $body);
            if ($b === null) {
                continue;
            }
            $params = array_merge($t, $b);
            foreach ($params as $key => $value) {
                // "your cover" was the English stand-in for a missing value, not a real one.
                if (in_array(strtolower((string) $value), self::LEGACY_BLANKS[$key] ?? [], true)) {
                    $params[$key] = null;
                }
            }

            return ['code' => (string) $code, 'params' => $params];
        }

        return null;
    }

    /** en | fr from a locale tag ("fr-CM", "FR", null → en). */
    public static function locale(?string $value): string
    {
        $value = strtolower(substr(trim((string) $value), 0, 2));

        return in_array($value, self::LOCALES, true) ? $value : 'en';
    }

    /** The app language when the request carries one (Accept-Language), else the user's saved locale. */
    public static function requestLocale(Request $request): string
    {
        if (trim((string) $request->header('Accept-Language')) !== '') {
            $preferred = $request->getPreferredLanguage(self::LOCALES);
            if ($preferred) {
                return self::locale($preferred);
            }
        }

        return self::locale($request->user()?->locale);
    }

    /** @return array<string, string> */
    private static function prepare(array $params, string $locale): array
    {
        $defaults = (array) Lang::get(self::FILE.'._defaults', [], $locale);
        $out = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            $value = $value === null ? '' : (string) $value;
            if ($value === '' && isset($defaults[$key])) {
                $value = (string) $defaults[$key];
            } elseif (in_array($key, self::DATE_PARAMS, true)) {
                $value = self::date($value, $locale);
            } elseif ($key === 'service') {
                $value = self::serviceType($value, $locale);
            } elseif ($key === 'status') {
                $value = strtolower(str_replace('_', ' ', $value));
            }
            $out[$key] = $value;
        }
        foreach ($defaults as $key => $value) {
            $out[$key] ??= (string) $value;
        }

        return $out;
    }

    private static function date(string $value, string $locale): string
    {
        try {
            $date = CarbonImmutable::parse($value)->locale($locale);

            return $locale === 'fr' ? $date->isoFormat('D MMMM YYYY') : $date->format('d M Y');
        } catch (Throwable) {
            return $value;
        }
    }

    private static function serviceType(string $value, string $locale): string
    {
        $types = (array) Lang::get(self::FILE.'._service_types', [], $locale);
        $code = strtoupper(str_replace(' ', '_', $value));

        return (string) ($types[$code] ?? strtolower(str_replace('_', ' ', $value)));
    }

    /**
     * Matches text against an English template; returns the placeholder values or null.
     *
     * @return array<string, string>|null
     */
    private static function match(string $template, string $text): ?array
    {
        $names = [];
        $pattern = preg_replace_callback('/\\\\:([A-Za-z][A-Za-z0-9_]*)/', function (array $m) use (&$names) {
            $name = lcfirst($m[1]);
            if (isset($names[$name])) {
                return '(?P='.$name.')';
            }
            $names[$name] = true;

            return '(?P<'.$name.'>'.(in_array($name, self::TOKEN_PARAMS, true) ? '\S+' : '.+?').')';
        }, preg_quote($template, '/'));
        if (! is_string($pattern) || @preg_match('/^'.$pattern.'$/su', $text, $m) !== 1) {
            return null;
        }

        return array_filter($m, fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
    }
}
