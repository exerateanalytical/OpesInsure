<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Runtime;

use App\Models\MobileIssueReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated "something is broken here" sink so a report can be
 * filed from any screen, including pre-auth ones. If the request carries a
 * valid bearer token the report is attributed to that user; otherwise it is
 * stored anonymously rather than rejected.
 */
final class MobileIssueReportController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route' => 'required|string|max:255',
            'note' => 'required|string|min:3|max:2000',
            'platform' => 'nullable|string|max:32',
            'app_version' => 'nullable|string|max:32',
        ]);

        $report = MobileIssueReport::create([
            ...$data,
            'user_id' => $request->user('api')?->id,
            'ip_hash' => hash('sha256', $request->ip() ?? ''),
        ]);

        return response()->json(['data' => ['accepted' => true, 'id' => $report->id]], 201);
    }
}
