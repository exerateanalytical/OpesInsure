<?php

declare(strict_types=1);

namespace App\Filament\Shared\Middleware;

use App\Interfaces\Http\Controllers\Web\SetPublicLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonical UI handoff "Bilingual implementation": EN/FR for every Filament
 * panel (admin, insurer, broker). Precedence: ?lang= (remembered in the
 * session, same key as the public site so the choice follows the user) >
 * session > signed-in user's `locale` > browser Accept-Language > app default.
 * Presentation only: API values, stored data and business rules are untouched.
 */
final class SetPanelLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = SetPublicLocale::SUPPORTED;
        $query = strtolower((string) $request->query('lang', ''));
        $session = $request->hasSession() ? $request->session()->get('public_locale') : null;

        if (in_array($query, $supported, true)) {
            $locale = $query;
            if ($request->hasSession()) {
                $request->session()->put('public_locale', $locale);
            }
        } elseif (in_array($session, $supported, true)) {
            $locale = $session;
        } elseif (in_array($userLocale = substr((string) $request->user()?->locale, 0, 2), $supported, true)) {
            $locale = $userLocale;
        } else {
            $locale = $request->getPreferredLanguage($supported) ?: config('app.locale', 'en');
        }

        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
