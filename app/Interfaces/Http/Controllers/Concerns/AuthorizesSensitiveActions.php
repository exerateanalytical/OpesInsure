<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Concerns;

use App\Application\Audit\AuditWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Defence-in-depth authorization for sensitive Wave 9 / Wave 11 actions.
 *
 * Route-level `permission:` middleware (or Gate::authorize in the controller)
 * is the primary gate; this trait re-asserts it inside the controller so a
 * misconfigured route can never silently drop enforcement, and makes sure
 * both the allow and the deny outcome leave a tamper-evident row in
 * `audit_log` — never the request payload itself, only static identifiers.
 */
trait AuthorizesSensitiveActions
{
    private function authorizePermission(string $permission, string $subjectType, ?string $subjectId = null): void
    {
        $user = Auth::user();
        $allowed = $user !== null && $user->hasPermission($permission);

        app(AuditWriter::class)->record(
            $allowed ? 'authorization.allowed' : 'authorization.denied',
            $subjectType,
            $subjectId,
            ['permission' => $permission],
            $allowed ? null : 'permission_denied',
        );

        abort_unless($allowed, 403, 'Permission denied.');
    }

    /**
     * REQ-DUP-009: a canonical action served by several routes (canonical + deprecated aliases with
     * their own historical permission names) re-asserts whichever `permission:` the matched route
     * declares. Returns that permission name (also used as the audit action). A route without a
     * permission middleware is refused (fail closed).
     */
    private function authorizeRoutePermission(\Illuminate\Http\Request $request, string $subjectType, ?string $subjectId = null): string
    {
        $permission = null;
        foreach ($request->route()?->gatherMiddleware() ?? [] as $m) {
            if (is_string($m) && str_starts_with($m, 'permission:')) {
                $permission = substr($m, strlen('permission:'));
                break;
            }
        }
        abort_if($permission === null, 403, 'Permission denied.');
        $this->authorizePermission($permission, $subjectType, $subjectId);

        return $permission;
    }

    /** True when the matched route is a deprecated trust/* alias (keeps that family's response shape). */
    private function viaTrustAlias(\Illuminate\Http\Request $request): bool
    {
        return str_starts_with((string) $request->route()?->uri(), 'api/v1/trust/');
    }

    /**
     * Same defence-in-depth guarantee as authorizePermission(), for the
     * model-policy-based abilities Wave 11 uses (Gate::authorize against an
     * auto-discovered Policy class). Logs the outcome before letting
     * AuthorizationException propagate as the usual 403.
     */
    private function gateAuthorize(string $ability, mixed $arg, string $subjectType, ?string $subjectId = null): void
    {
        try {
            Gate::authorize($ability, $arg);
            app(AuditWriter::class)->record('authorization.allowed', $subjectType, $subjectId, ['ability' => $ability]);
        } catch (AuthorizationException $e) {
            app(AuditWriter::class)->record('authorization.denied', $subjectType, $subjectId, ['ability' => $ability], 'permission_denied');
            throw $e;
        }
    }

    /**
     * Runs a service call that may reject the action for a business rule
     * (maker-checker, invalid transition, idempotency conflict, ...). Any
     * ValidationException is logged as a denial — with only the static rule
     * message, never the submitted payload — before being rethrown so the
     * HTTP response is unchanged.
     */
    private function auditedCall(callable $callback, string $action, string $subjectType, ?string $subjectId)
    {
        try {
            $result = $callback();
            app(AuditWriter::class)->record($action, $subjectType, $subjectId ?? $this->auditResultId($result), []);

            return $result;
        } catch (ValidationException $e) {
            app(AuditWriter::class)->record(
                $action.'.denied',
                $subjectType,
                $subjectId,
                [],
                $e->validator->errors()->first(),
            );

            throw $e;
        }
    }

    private function auditResultId(mixed $result): ?string
    {
        return is_object($result) && method_exists($result, 'getKey') ? (string) $result->getKey() : null;
    }
}
