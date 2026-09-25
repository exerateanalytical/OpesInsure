<?php

declare(strict_types=1);

namespace App\Application\DataReadiness\Http;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Workflow Data Master readiness registry (read-only). */
final class DataReadinessController extends Controller
{
    public function __construct(private readonly DataReadinessRegistry $registry) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['domain' => 'nullable|string|max:64', 'status' => 'nullable|in:'.implode(',', DataStatus::CODES)]);
        $items = isset($d['domain']) ? $this->registry->domain($d['domain']) : $this->registry->items();
        if (isset($d['status'])) {
            $items = array_values(array_filter($items, fn ($i) => $i['status'] === $d['status']));
        }

        return response()->json(['data' => $items, 'meta' => $this->registry->summary($items)]);
    }

    public function vocabulary(): JsonResponse
    {
        return response()->json(['data' => ['codes' => DataStatus::CODES, 'production' => DataStatus::PRODUCTION, 'mapping' => DataStatus::mapping(), 'source' => DataStatus::SOURCE]]);
    }
}
