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
        // De-duplicate the same event reaching a user twice within a few
        // minutes (e.g. issuance notified by PolicyIssuanceService and again
        // by a demo/ops path, or a webhook replay). A POLICY notification is
        // one-per-policy, so any title counts as the same event.
        if ($path !== null) {
            $existing = self::where('user_id', $user->id)->where('type', $type)->where('path', $path)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->when($type !== 'POLICY', fn ($q) => $q->where('title', $title))
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return self::create(compact('type', 'title', 'body', 'severity', 'path') + ['user_id' => $user->id, 'tenant_id' => $tenantId]);
    }
}
