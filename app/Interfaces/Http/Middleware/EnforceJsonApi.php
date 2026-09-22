<?php
namespace App\Interfaces\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceJsonApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() === false && ! $request->isJson()) abort(415, 'application/json is required.');
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('Referrer-Policy','no-referrer');
        $response->headers->set('Permissions-Policy','camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cache-Control','no-store, private');
        return $response;
    }
}
