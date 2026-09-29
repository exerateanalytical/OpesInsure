<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Channel copy (EMAIL / SMS / IN_APP) from notification_templates for a
 * NotificationCatalog code. A tenant's own ACTIVE template overrides the
 * platform one (tenant_id null); the highest ACTIVE version wins; a missing
 * locale falls back to English. Placeholders are {{param}} (same convention
 * as NotificationDispatchService), filled with the catalog's localised values
 * plus {{first_name}} — the only personal data a template may carry.
 *
 * Returns null when no template exists, so callers keep their previous
 * behaviour (catalog title/body) for codes nobody has templated.
 */
final class NotificationTemplateRenderer
{
    public const CHANNELS = ['IN_APP', 'EMAIL', 'SMS'];

    /** @return object|null the notification_templates row */
    public function find(string $code, string $channel, string $locale, ?string $tenantId): ?object
    {
        try {
            if (! Schema::hasTable('notification_templates')) {
                return null;
            }
            foreach (array_unique([NotificationCatalog::locale($locale), 'en']) as $loc) {
                $row = DB::table('notification_templates')
                    ->where(['code' => $code, 'channel' => $channel, 'locale' => $loc, 'status' => 'ACTIVE'])
                    ->where(fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id') : $q->whereNull('tenant_id'))
                    ->orderByRaw('tenant_id IS NULL')->orderByDesc('version')
                    ->first();
                if ($row) {
                    return $row;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    /** @return array{subject: ?string, body: string, template_id: string}|null */
    public function render(?string $code, string $channel, string $locale, ?string $tenantId, array $params = [], ?User $user = null): ?array
    {
        if (! $code || ! ($row = $this->find($code, $channel, $locale, $tenantId))) {
            return null;
        }
        $values = NotificationCatalog::values($params, $row->locale);
        $values['first_name'] = self::firstName($user);
        $replace = [];
        foreach ($values as $key => $value) {
            $replace['{{'.$key.'}}'] = $value;
            $replace['{{'.ucfirst($key).'}}'] = ucfirst($value);
        }
        $fill = fn (?string $text) => $text === null ? null : trim((string) preg_replace(['/\{\{[A-Za-z_]+\}\}/', '/ +,/', '/ {2,}/'], ['', ',', ' '], strtr($text, $replace)));
        $body = (string) $fill($row->body);
        if ($channel === 'SMS') {
            $body = mb_substr(self::gsm($body), 0, 160);
        }

        return ['subject' => $fill($row->subject), 'body' => $body, 'template_id' => $row->id];
    }

    /** First name only (never the full name) — templates may address the recipient, nothing more. */
    public static function firstName(?User $user): string
    {
        $name = trim((string) ($user?->full_name ?? $user?->name ?? ''));

        return $name === '' ? '' : (string) strtok($name, ' ');
    }

    /** Transliterates characters outside the GSM 03.38 basic set so an SMS stays one 7-bit segment. */
    public static function gsm(string $text): string
    {
        return strtr($text, [
            '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '«' => '"', '»' => '"', '—' => '-', '–' => '-', '…' => '...', "\u{00A0}" => ' ', "\u{202F}" => ' ',
            'ê' => 'e', 'ë' => 'e', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c', 'œ' => 'oe', 'Œ' => 'OE',
            'Ê' => 'E', 'È' => 'E', 'À' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U', 'ÿ' => 'y', 'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        ]);
    }

    /** GSM-7 basic character set check (no extension table). */
    public static function isGsm7(string $text): bool
    {
        $basic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        foreach (mb_str_split($text) as $ch) {
            if (mb_strpos($basic, $ch) === false) {
                return false;
            }
        }

        return true;
    }
}
