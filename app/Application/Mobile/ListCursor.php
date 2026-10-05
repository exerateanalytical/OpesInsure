<?php

declare(strict_types=1);

namespace App\Application\Mobile;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Phase-1 fix S (2026-09-30): opaque cursor paging for the mobile staff lists that used a fixed limit(100).
 *
 * Backward compatible: a request without `cursor` / `limit` gets the same first page as before (the default size,
 * same ordering), and the response only gains `meta.next_cursor` (null on the last page). A client asks for the next
 * page with `?cursor=<meta.next_cursor>`; `limit` (1..max) sets the page size.
 *
 * The cursor encodes an offset over the endpoint's deterministic ordering (every caller orders by its sort key and
 * then by id), so a page never repeats or skips a row while the list is unchanged.
 */
final class ListCursor
{
    private const MAX_OFFSET = 1_000_000;

    private bool $more = false;

    private function __construct(public readonly int $offset, public readonly int $limit) {}

    public static function from(Request $request, int $default = 100, int $max = 100): self
    {
        $limit = $request->query('limit');
        if ($limit !== null && (! is_numeric($limit) || (int) $limit < 1)) {
            throw ValidationException::withMessages(['limit' => __('validation.integer', ['attribute' => 'limit'])]);
        }
        $cursor = $request->query('cursor');

        return new self($cursor === null || $cursor === '' ? 0 : self::decode((string) $cursor), min($max, $limit === null ? $default : (int) $limit));
    }

    /** Offset + one extra row, so slice() knows whether another page exists. */
    public function apply(EloquentBuilder|QueryBuilder $q): EloquentBuilder|QueryBuilder
    {
        return $q->offset($this->offset)->limit($this->limit + 1);
    }

    /**
     * @template T
     *
     * @param  Collection<int, T>  $rows  the result of a query passed through apply()
     * @return Collection<int, T>
     */
    public function slice(Collection $rows): Collection
    {
        $this->more = $rows->count() > $this->limit;

        return $rows->take($this->limit)->values();
    }

    public function nextCursor(): ?string
    {
        return $this->more ? self::encode($this->offset + $this->limit) : null;
    }

    /** @return array{next_cursor: ?string} */
    public function meta(): array
    {
        return ['next_cursor' => $this->nextCursor()];
    }

    public static function encode(int $offset): string
    {
        return rtrim(strtr(base64_encode('o:'.$offset), '+/', '-_'), '=');
    }

    private static function decode(string $cursor): int
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || ! preg_match('/^o:(\d{1,7})$/', $raw, $m) || (int) $m[1] > self::MAX_OFFSET) {
            throw ValidationException::withMessages(['cursor' => __('mobile_workspace.invalid_cursor')]);
        }

        return (int) $m[1];
    }
}
