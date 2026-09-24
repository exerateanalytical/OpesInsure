<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Issuer authority + signature + QR configuration per insurer (optionally per product). */
final class DocumentIssuanceProfile extends Model
{
    use HasUuids;

    protected $fillable = ['carrier_id', 'product_id', 'issuance_mode', 'opes_rendering_authorized', 'authorization_reference', 'authorized_by', 'authorized_at', 'languages', 'default_language', 'signature_mode', 'signatory_name', 'signatory_title', 'qr_enabled', 'qr_payload', 'status'];

    protected function casts(): array
    {
        return ['languages' => 'array', 'opes_rendering_authorized' => 'boolean', 'qr_enabled' => 'boolean', 'authorized_at' => 'datetime'];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InsuranceProduct::class, 'product_id');
    }
}
