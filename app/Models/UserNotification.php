<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UserNotification extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'tenant_id', 'type', 'title', 'body', 'severity', 'path', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** The shape app/notifications/*.tsx renders (CustomerNotification). */
    public function toMobile(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'path' => $this->path,
            'severity' => $this->severity,
        ];
    }

    /** Convenience for domain code: drop a notification for a user. */
    public static function notify(User $user, string $type, string $title, string $body, string $severity = 'INFO', ?string $path = null, ?string $tenantId = null): self
    {
        return self::create(compact('type', 'title', 'body', 'severity', 'path') + ['user_id' => $user->id, 'tenant_id' => $tenantId]);
    }
}
