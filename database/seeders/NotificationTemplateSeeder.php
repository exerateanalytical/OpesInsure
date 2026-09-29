<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Reference data, not demo data — runs in every environment including
 * production, same as CanonicalEventSchemaSeeder. A global (tenant_id=null)
 * ACTIVE template, since notification_templates.tenant_id is nullable and
 * NotificationController::queue()'s own lookup already treats a null
 * tenant_id as "usable by any tenant".
 *
 * fulfilment.delivery_otp is the one FulfilmentController::store() needs to
 * actually deliver the OTP challenge via SMS instead of returning it in the
 * API response (see DeliveryOtpNotifier).
 */
final class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            NotificationTemplate::updateOrCreate(
                ['tenant_id' => null, 'code' => $template['code'], 'locale' => $template['locale'], 'channel' => $template['channel'], 'version' => 1],
                $template + ['version' => 1, 'status' => 'ACTIVE'],
            );
        }

        // S8: platform IN_APP / EMAIL / SMS templates for every customer notification code, EN + FR.
        // Created once and never overwritten, so copy edited in the operations desk survives a re-seed;
        // a tenant overrides one by adding its own ACTIVE row (tenant_id set), see NotificationTemplateRenderer.
        foreach (self::catalogTemplates() as $template) {
            NotificationTemplate::firstOrCreate(
                ['tenant_id' => null, 'code' => $template['code'], 'locale' => $template['locale'], 'channel' => $template['channel'], 'version' => 1],
                $template + ['version' => 1, 'status' => 'ACTIVE'],
            );
        }
    }

    /**
     * Generated from resources/lang/{en,fr}/customer_notifications.php (the NotificationCatalog copy):
     * IN_APP = the catalog text; EMAIL = greeting by first name + text; SMS = brand + title + one
     * reference, GSM-7 only, no personal data.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalogTemplates(): array
    {
        $copy = [
            'en' => ['hello' => 'Hello {{first_name}},', 'cta' => 'Open the OpesInsure app for details.', 'sms_cta' => 'Details in the app.', 'sign' => 'The OpesInsure team'],
            'fr' => ['hello' => 'Bonjour {{first_name}},', 'cta' => 'Ouvrez l’application OpesInsure pour plus de détails.', 'sms_cta' => "Détails dans l'appli.", 'sign' => 'L’équipe OpesInsure'],
        ];
        $placeholders = fn (string $text) => (string) preg_replace('/:([A-Za-z][A-Za-z0-9_]*)/', '{{$1}}', $text);
        $out = [];
        foreach (\App\Application\Notifications\NotificationCatalog::LOCALES as $locale) {
            foreach ((array) trans('customer_notifications', [], $locale) as $code => $line) {
                if (str_starts_with((string) $code, '_') || ! is_array($line) || ! isset($line['title'], $line['body'])) {
                    continue;
                }
                $title = $placeholders((string) $line['title']);
                $body = $placeholders((string) $line['body']);
                preg_match_all('/\{\{([A-Za-z0-9_]+)\}\}/', $title.' '.$body, $m);
                $vars = array_values(array_unique(array_map('lcfirst', $m[1])));
                $ref = collect(['policy', 'claim', 'reference', 'request'])->first(fn ($k) => in_array($k, $vars, true));
                $sms = $code === 'staff_invitation'
                    ? "OpesInsure: {$title}. Code: {{code}} ({{hours}}h)."
                    : 'OpesInsure: '.rtrim($title, '.').($ref ? ' - {{'.$ref.'}}' : '').'. '.$copy[$locale]['sms_cta'];
                $base = ['code' => (string) $code, 'locale' => $locale, 'purpose' => 'TRANSACTIONAL', 'required_variables' => $vars];
                $out[] = $base + ['channel' => 'IN_APP', 'subject' => $title, 'body' => $body];
                $out[] = $base + ['channel' => 'EMAIL', 'subject' => $title,
                    'body' => $copy[$locale]['hello']."\n\n{$body}\n\n".$copy[$locale]['cta']."\n\n".$copy[$locale]['sign']];
                $out[] = $base + ['channel' => 'SMS', 'subject' => null, 'body' => \App\Application\Notifications\NotificationTemplateRenderer::gsm($sms)];
            }
        }

        return $out;
    }

    private function templates(): array
    {
        return [
            [
                'code' => 'fulfilment.delivery_otp', 'locale' => 'en', 'purpose' => 'TRANSACTIONAL', 'channel' => 'SMS',
                'subject' => null, 'body' => 'OpesInsure: your delivery code is {{otp}}. Give this code to the courier only once your item has arrived.',
                'required_variables' => ['otp'],
            ],
            [
                'code' => 'fulfilment.delivery_otp', 'locale' => 'fr', 'purpose' => 'TRANSACTIONAL', 'channel' => 'SMS',
                'subject' => null, 'body' => 'OpesInsure : votre code de livraison est {{otp}}. Ne le communiquez au livreur qu\'a la reception de votre colis.',
                'required_variables' => ['otp'],
            ],
        ];
    }
}
