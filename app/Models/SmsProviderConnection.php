<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One configured SMS provider. `secrets` is encrypted at rest (APP_KEY), hidden from serialisation, never shown back. */
final class SmsProviderConnection extends Model
{
    use HasUuids;

    public const ROLES = ['PRIMARY', 'FALLBACK', 'STANDBY'];

    protected $fillable = ['provider', 'label', 'role', 'status', 'settings', 'secrets', 'health_status', 'consecutive_failures',
        'last_success_at', 'last_failure_at', 'last_error', 'created_by', 'updated_by'];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'secrets' => 'encrypted:array', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime'];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $v = data_get($this->settings ?? [], $key);

        return ($v === null || $v === '') ? $default : $v;
    }

    public function secret(string $key): string
    {
        return (string) (($this->secrets ?? [])[$key] ?? '');
    }
}
