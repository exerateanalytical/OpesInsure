<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\QuoteRequests\Models;

use App\Models\Quote;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REQ-QUO-006 — a manual (Mode 1) quotation request sent to one insurer. */
final class CarrierQuoteRequest extends Model
{
    use HasUuids;

    public const OPEN_STATES = ['REQUESTED', 'IN_PROGRESS'];

    protected $table = 'carrier_quote_requests';

    protected $guarded = [];

    protected $casts = ['risk_snapshot' => 'array', 'requested_at' => 'datetime', 'response_due_at' => 'datetime', 'responded_at' => 'datetime'];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(CarrierQuoteResponse::class)->orderBy('responded_at');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATES, true);
    }
}
