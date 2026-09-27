<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Filament bordereaux resource used the auto-derived slug "bordereaux/bordereaus"; it is now "bordereaux".
 * Old links (/admin|broker|insurer/bordereaux/bordereaus[/...]) get a 301. Global so it runs before panel auth.
 */
final class RedirectLegacyBordereauxUrls
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && preg_match('#^(admin|broker|insurer)/bordereaux/bordereaus(/.*)?$#', $request->path(), $m)) {
            $query = $request->getQueryString();

            return redirect('/'.$m[1].'/bordereaux'.($m[2] ?? '').($query ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
