<?php

declare(strict_types=1);

namespace App\Application\OperationsTaxonomy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Idempotent seeding of Gap Closure Pack file 10 (PLATFORM_NORMALIZED codes only). Inserts missing rows, never
 * overwrites or deletes. Seeds platform platform DRAFT notification templates (one per event × EMAIL/SMS × en/fr) that an administrator must
 * approve before use. No retention period, SLA target, numbering profile or signatory is seeded (pack gates).
 */
final class OperationsSeeder
{
    public const DOMAIN = 'operations';

    public const TEMPLATE_CHANNELS = ['EMAIL', 'SMS'];

    /** @return array<string, int> */
    public function run(): array
    {
        $n = ['templates' => 0];
        $now = now();
        if (Schema::hasTable('notification_templates') && Schema::hasColumn('notification_templates', 'event_code')) {
            foreach (OperationsCatalogue::list('notification_events') as $event) {
                foreach (self::TEMPLATE_CHANNELS as $channel) {
                    foreach (['en', 'fr'] as $locale) {
                        $code = 'event.'.strtolower($event);
                        if (DB::table('notification_templates')->whereNull('tenant_id')->where(['code' => $code, 'channel' => $channel, 'locale' => $locale])->exists()) {
                            continue;
                        }
                        [$subject, $body] = self::text($event, $locale);
                        DB::table('notification_templates')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'code' => $code, 'event_code' => $event,
                            'purpose' => self::purpose($event), 'channel' => $channel, 'locale' => $locale, 'version' => 1, 'status' => 'DRAFT',
                            'subject' => $channel === 'EMAIL' ? $subject : null, 'body' => $body, 'required_variables' => json_encode(['reference']),
                            'created_at' => $now, 'updated_at' => $now]);
                        $n['templates']++;
                    }
                }
            }
        }

        return $n;
    }

    public static function purpose(string $event): string
    {
        return match (true) {
            str_starts_with($event, 'CLAIM_') => 'CLAIMS',
            str_starts_with($event, 'RENEWAL_'), $event === 'POLICY_EXPIRING' => 'RENEWALS',
            in_array($event, ['SECURITY_ALERT', 'ACCOUNT_LOCKED', 'PASSWORD_CHANGED'], true) => 'SECURITY',
            default => 'TRANSACTIONAL',
        };
    }

    /** Neutral operational wording (no legal or financial content): the event label plus the business reference. */
    public static function text(string $event, string $locale): array
    {
        return $locale === 'fr'
            ? ['OpesInsure : '.OperationsLabels::fr($event), 'OpesInsure : '.OperationsLabels::fr($event).' (réf. {{reference}}). Consultez votre espace OpesInsure pour le détail.']
            : ['OpesInsure: '.OperationsLabels::en($event), 'OpesInsure: '.OperationsLabels::en($event).' (ref. {{reference}}). Open your OpesInsure account for details.'];
    }
}
