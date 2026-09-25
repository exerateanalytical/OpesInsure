<?php

declare(strict_types=1);

namespace App\Application\Settlements\Http;

use App\Application\Settlements\SettlementService;
use App\Models\SettlementBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-DUP-011 — the one settlement representation: {batch, items, approvals, lifecycle}.
 * Served by GET settlements/{batch} and its alias GET carrier-settlements/{settlement}.
 *
 * @property SettlementBatch $resource
 */
final class SettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return app(SettlementService::class)->present($this->resource);
    }
}
