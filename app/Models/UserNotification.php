<?php

declare(strict_types=1);

namespace App\Models;

use App\Application\Notifications\NotificationCatalog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UserNotification extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'tenant_id', 'type', 'code', 'params', 'title', 'body', 'severity', 'path', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'params' => 'array'];
    }

    /**
     * The shape app/notifications/*.tsx renders (CustomerNotification).
     * With a locale, title/body are rendered in that language from the stable
     * `code` (NotificationCatalog); the stored English text is the fallback.
     */
    public function toMobile(?string $locale = null): array
    {
        $copy = $locale === null
            ? ['title' => $this->title, 'body' => $this->body, 'code' => $this->code, 'params' => $this->params ?? []]
            : NotificationCatalog::localize($this->code, $this->params, (string) $this->title, (string) $this->body, $locale);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'code' => $copy['code'],
            'params' => (object) $copy['params'],
            'title' => $copy['title'],
            'body' => $copy['body'],
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'path' => $this->path,
            'severity' => $this->severity,
        ];
    }

    /**
     * Convenience for domain code: drop a notification for a user. Pass the
     * NotificationCatalog `code` + `params` so every reader sees it in their
     * language; title/body keep the English text.
     */
    public static function notify(User $user, string $type, string $title, string $body, string $severity = 'INFO', ?string $path = null, ?string $tenantId = null, ?string $code = null, array $params = []): self
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

        return self::create(compact('type', 'title', 'body', 'severity', 'path') + ['user_id' => $user->id, 'tenant_id' => $tenantId, 'code' => $code, 'params' => $code ? $params : null]);
    }
}
