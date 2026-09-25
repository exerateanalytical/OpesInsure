<?php

declare(strict_types=1);

namespace App\Application\Search\Http;

use App\Application\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * REQ-SRC-001: GET /api/v1/search?q=…&types[]=policies&types[]=vehicles&limit=5
 * Results are grouped by `type`, ranked by score; `searched` lists the
 * entity types the caller was allowed to search (permission + data scope).
 */
final class SearchController
{
    public function __invoke(Request $r, GlobalSearchService $search): JsonResponse
    {
        $d = $r->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', Rule::in(GlobalSearchService::TYPES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json(['data' => $search->search($r->user(), $d['q'], $d['types'] ?? [], (int) ($d['limit'] ?? 5))]);
    }
}
