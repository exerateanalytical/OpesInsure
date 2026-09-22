<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;

final class SystemController
{
    public function capabilities(): JsonResponse
    {
        return response()->json(['data' => [
            'api_version' => 'v1', 'currency' => 'XAF', 'locales' => ['en', 'fr'],
            'channels' => ['B2C', 'AGENT', 'BROKER'],
            'insurance_lines' => ['AUTOMOBILE', 'HEALTH', 'TRAVEL', 'PROPERTY'],
        ], 'meta' => ['request_id' => request()->header('X-Request-Id')]]);
    }
}
