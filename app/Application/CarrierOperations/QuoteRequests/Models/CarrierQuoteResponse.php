<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\QuoteRequests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** REQ-QUO-006 — append-only insurer answer (OFFER / DECLINE) to a manual quotation request. */
final class CarrierQuoteResponse extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'carrier_quote_responses';

    protected $guarded = [];

    protected $casts = ['premium_breakdown' => 'array', 'conditions' => 'array', 'document_ids' => 'array', 'valid_until' => 'datetime', 'responded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Carrier quote responses are append-only.'));
        static::deleting(fn () => throw new LogicException('Carrier quote responses are append-only.'));
    }
}
