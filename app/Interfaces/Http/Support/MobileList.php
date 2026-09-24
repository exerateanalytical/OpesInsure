<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Support;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The one list envelope mobile collection endpoints return, so the app can
 * always do `response.data.map(...)`:
 *
 *   { "data": [ ...items ], "meta": { "current_page": 1, "last_page": 3,
 *     "per_page": 20, "total": 47, "next_page": 2 } }
 *
 * next_page is null on the last page. Pass ?page=N (and optionally
 * ?per_page=, max 100) to page.
 */
final class MobileList
{
    /** @return array{data: array<int, mixed>, meta: array<string, int|null>} */
    public static function fromPaginator(LengthAwarePaginator $page, ?callable $map = null): array
    {
        $items = collect($page->items());

        return [
            'data' => ($map ? $items->map($map) : $items)->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            ],
        ];
    }
}
