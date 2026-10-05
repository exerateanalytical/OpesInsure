<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Public partner self-service application (/partners/apply). Written only by PartnerApplicationService. */
final class PartnerApplication extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'status_token_hash', 'brokerage_confirmation_hash', 'ip_hash'];

    protected function casts(): array
    {
        return [
            'documents' => 'array', 'duplicate_flags' => 'array', 'result' => 'array', 'licence_expires_on' => 'date',
            'code_expires_at' => 'datetime', 'code_sent_at' => 'datetime', 'verified_at' => 'datetime', 'reviewed_at' => 'datetime', 'decided_at' => 'datetime',
            'brokerage_confirmed_at' => 'datetime', 'brokerage_declined_at' => 'datetime',
        ];
    }

    public function brokerage(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'brokerage_tenant_id');
    }
}
