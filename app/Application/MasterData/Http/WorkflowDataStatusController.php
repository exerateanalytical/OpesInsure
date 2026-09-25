<?php

declare(strict_types=1);

namespace App\Application\MasterData\Http;

use App\Application\MasterData\WorkflowDataStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Owner Workflow Data Master v1 — admin view of reference catalogue statuses (PENDING_SOURCE items listed with 0 values). */
final class WorkflowDataStatusController extends Controller
{
    public function index(Request $request, WorkflowDataStatuses $statuses): JsonResponse
    {
        $d = $request->validate(['status' => ['nullable', Rule::in(WorkflowDataStatuses::CODES)]]);
        $items = $statuses->all($d['status'] ?? null);

        return response()->json(['data' => $items, 'meta' => [
            'status_codes' => WorkflowDataStatuses::CODES, 'source' => WorkflowDataStatuses::SOURCE,
            'counts' => array_count_values(array_column($items, 'status')),
        ]]);
    }
}
