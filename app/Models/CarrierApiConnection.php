<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One carrier API connection (carrier / provider / environment). The subscription key and the per-service logins are
 * encrypted at rest (APP_KEY) and hidden from serialisation; nothing ever reads them back into a form or a log.
 *
 * @property array<string, array<string, string>>|null $service_credentials
 */
final class CarrierApiConnection extends Model
{
    use HasUuids;

    public const STATUSES = ['CONFIG_REQUIRED', 'PENDING_VERIFICATION', 'ACTIVE', 'AUTH_FAILED', 'DISABLED'];

    protected $table = 'carrier_api_connections';

    protected $fillable = ['carrier_id', 'tenant_id', 'provider', 'environment', 'status', 'subscription_key', 'service_credentials', 'settings', 'service_health',
        'last_error_code', 'last_tested_at', 'last_success_at', 'last_reference_sync_at', 'last_reconciled_at', 'updated_by'];

    protected $hidden = ['subscription_key', 'service_credentials'];

    protected function casts(): array
    {
        return [
            'subscription_key' => 'encrypted',
            'service_credentials' => 'encrypted:array',
            'settings' => 'array',
            'service_health' => 'array',
            'last_tested_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_reference_sync_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /** Stable fingerprint of the secrets (for token cache keys): changes whenever a secret changes, reveals nothing. */
    public function secretsFingerprint(): string
    {
        return substr(hash('sha256', (string) $this->getRawOriginal('subscription_key').'|'.(string) $this->getRawOriginal('service_credentials').'|'.$this->environment), 0, 16);
    }
}
