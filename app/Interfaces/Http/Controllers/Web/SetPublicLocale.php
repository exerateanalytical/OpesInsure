<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Web;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public website language switch (EN/FR).
 *
 * Precedence: ?lang= (remembered in the session) > session > browser
 * Accept-Language > app default. Only the public web routes use it; the API
 * and the Filament panel keep their own locale handling.
 */
final class SetPublicLocale
{
    public const SUPPORTED = ['en', 'fr'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;
        $query = strtolower((string) $request->query('lang', ''));

        if (in_array($query, self::SUPPORTED, true)) {
            $locale = $query;
            if ($request->hasSession()) {
                $request->session()->put('public_locale', $locale);
            }
        } elseif ($request->hasSession() && in_array($request->session()->get('public_locale'), self::SUPPORTED, true)) {
            $locale = $request->session()->get('public_locale');
        } else {
            $locale = $request->getPreferredLanguage(self::SUPPORTED);
        }

        app()->setLocale($locale ?: config('app.locale', 'en'));

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        $response->setVary(array_unique(array_merge($response->getVary(), ['Accept-Language', 'Cookie'])));

        return $response;
    }
}
