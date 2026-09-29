<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Runs an API controller method in-process for a web action whose API route has its logic inline (no application
 * service to call): the SAME validation rules, tenant checks, state guards and audit records apply, with no copy of
 * the logic in the UI. Validation / abort() / problem exceptions propagate to WorkflowAction::run, which shows them.
 * Precedent: ReportsPage calls InsuranceReportController the same way. Permission gating stays with WorkflowAction.
 */
final class ControllerCall
{
    /**
     * @param  array<string, mixed>  $input  request body (validated by the controller)
     * @param  array<string, mixed>  $params  route parameters, by the controller argument name
     */
    public static function invoke(string $controller, string $method, array $input = [], array $params = [], string $verb = 'POST'): mixed
    {
        $request = Request::create('/', $verb, $input, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => auth()->user());
        $previous = app('request');
        app()->instance('request', $request);
        try {
            $response = app()->call([app($controller), $method], $params + ['r' => $request, 'request' => $request]);
        } finally {
            app()->instance('request', $previous);
        }

        return $response instanceof JsonResponse ? ($response->getData(true)['data'] ?? null) : $response;
    }
}
