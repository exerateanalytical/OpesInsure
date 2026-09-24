<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Errors;

use App\Interfaces\Http\Middleware\AssignCorrelationId;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standard /api error envelope (REQ-API-003, RFC 9457 members) layered ON
 * TOP of the existing Laravel body so current clients keep working:
 *
 *   {
 *     "message": "...",              // unchanged — mobile ApiError.message
 *     "errors": {"field": ["..."]},  // unchanged — mobile ApiError.fields (422)
 *     "code": "VALIDATION_FAILED",   // mobile ApiError.code (was REQUEST_FAILED fallback)
 *     "type": "urn:opesinsure:problem:validation-failed",
 *     "title": "Unprocessable Content",
 *     "status": 422,
 *     "detail": "...",               // == message
 *     "field_errors": {...},         // == errors when it is a field map
 *     "correlation_id": "..."        // == X-Correlation-ID response header
 *   }
 *
 * Existing members always win: a body that already has `code` (e.g.
 * STEP_UP_REQUIRED) keeps it. Content-Type stays application/json because
 * the mobile client only parses JSON bodies.
 */
final class ProblemDetails
{
    public static function decorate(Response $response): Response
    {
        if ($response->getStatusCode() < 400 || ! $response instanceof JsonResponse) {
            return $response;
        }

        $body = $response->getData(true);

        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            return $response;
        }

        $status = $response->getStatusCode();
        $correlationId = AssignCorrelationId::current();
        $errors = $body['errors'] ?? null;
        $fieldMap = is_array($errors) && $errors !== [] && ! array_is_list($errors) ? $errors : null;
        $code = $body['code'] ?? (is_array($errors) && array_is_list($errors) ? ($errors[0]['code'] ?? null) : null) ?? self::infer($status, $fieldMap);
        $message = $body['message'] ?? $body['detail'] ?? (Response::$statusTexts[$status] ?? 'Error');

        $body += [
            'message' => $message,
            'code' => $code,
            'type' => 'urn:opesinsure:problem:'.strtolower(str_replace('_', '-', (string) $code)),
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $message,
        ];

        if ($fieldMap !== null) {
            $body += ['field_errors' => $fieldMap];
        }

        if ($correlationId !== null) {
            $body += ['correlation_id' => $correlationId];
            $response->headers->set(AssignCorrelationId::HEADER, $correlationId);
        }

        $response->setData($body);

        return $response;
    }

    /** @param  array<string, mixed>|null  $fields */
    private static function infer(int $status, ?array $fields): string
    {
        // Existing EnforcesOptimisticConcurrency trait: 409 + errors.version/current_version.
        if ($status === 409 && $fields !== null && array_key_exists('current_version', $fields)) {
            return ErrorCode::STALE_RECORD;
        }

        return ErrorCode::forStatus($status);
    }
}
