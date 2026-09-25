<?php

declare(strict_types=1);

namespace App\Application\Security\Purpose;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class PurposeOfUseDenied extends RuntimeException
{
    public function __construct(public readonly PurposeDecision $decision)
    {
        parent::__construct("Processing for purpose {$decision->purposeCode} is not permitted ({$decision->refusalCode}).");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'PURPOSE_OF_USE_DENIED',
            'errors' => ['purpose' => [$this->decision->refusalCode]]], 422);
    }
}
