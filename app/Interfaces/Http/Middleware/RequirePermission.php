<?php

namespace App\Interfaces\Http\Middleware;

use App\Application\Audit\AuditWriter;
use Closure;
use Illuminate\Http\Request;

final class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        if (! $request->user()->hasPermission($permission)) {
            app(AuditWriter::class)->record('authorization.denied', 'route', null, ['permission' => $permission, 'route' => $request->path()], 'permission_denied');
            abort(403, 'Permission denied.');
        }

        return $next($request);
    }
}
