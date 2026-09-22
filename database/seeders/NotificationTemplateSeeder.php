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
