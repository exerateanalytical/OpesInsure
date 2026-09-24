<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Errors;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Throw from any service/controller to surface a specific business
 * condition to the client with a stable machine code (REQ-API-003):
 *
 *   throw ApiProblemException::paymentOkIssuanceFailed($detail, ['payment_id' => $id]);
 *
 * Rendered as the standard envelope (message + code + RFC 9457 fields);
 * ProblemDetails adds correlation_id/type/title on the way out.
 */
final class ApiProblemException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fieldErrors
     * @param  array<string, mixed>  $extra  extra top-level members (e.g. payment_id)
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $detail,
        public readonly array $fieldErrors = [],
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }

    public static function staleRecord(string $currentVersion, ?string $detail = null): self
    {
        return new self(ErrorCode::STALE_RECORD, 412, $detail ?? __('api_errors.stale_record'), [
            'version' => [__('api_errors.stale_record')],
            'current_version' => [$currentVersion],
        ], ['current_version' => $currentVersion], ['ETag' => '"'.$currentVersion.'"']);
    }

    public static function authorityExceeded(?string $detail = null, array $extra = []): self
    {
        return new self(ErrorCode::AUTHORITY_EXCEEDED, 403, $detail ?? __('api_errors.authority_exceeded'), [], $extra);
    }

    /** Money was taken but the policy could not be issued — the client must
     * NOT offer "pay again"; it shows a pending-issuance state instead. */
    public static function paymentOkIssuanceFailed(?string $detail = null, array $extra = []): self
    {
        return new self(ErrorCode::PAYMENT_OK_ISSUANCE_FAILED, 502, $detail ?? __('api_errors.payment_ok_issuance_failed'), [], $extra);
    }

    public static function integrationUnavailable(?string $detail = null, array $extra = [], ?int $retryAfter = null): self
    {
        return new self(ErrorCode::INTEGRATION_UNAVAILABLE, 503, $detail ?? __('api_errors.integration_unavailable'), [], $extra,
            $retryAfter ? ['Retry-After' => (string) $retryAfter] : []);
    }

    public static function duplicateSubmission(?string $detail = null): self
    {
        return new self(ErrorCode::DUPLICATE_SUBMISSION, 409, $detail ?? __('api_errors.duplicate_submission'));
    }

    public function render(Request $request): JsonResponse
    {
        $body = ['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra;

        if ($this->fieldErrors !== []) {
            $body['errors'] = $this->fieldErrors;
        }

        return new JsonResponse($body, $this->status, $this->headers);
    }
}
