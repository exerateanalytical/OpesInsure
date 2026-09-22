<?php

namespace Database\Seeders;

use App\Models\CanonicalEventSchema;
use Illuminate\Database\Seeder;

/**
 * Reference data, not demo data — this must run in every environment
 * including production, unlike DatabaseSeeder's demo accounts.
 *
 * Each schema here was written from the ACTUAL payload a service emits
 * today (grepped from app/Application/**), not from the aspirational event
 * names IntegrationController used to hard-code ('quote.offered',
 * 'payment.succeeded', 'policy.changed', ... — none of which anything ever
 * emits). Partners can only subscribe to an event listed here — see
 * IntegrationController::subscribe().
 */
final class CanonicalEventSchemaSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->schemas() as $schema) {
            CanonicalEventSchema::updateOrCreate(
                ['event_name' => $schema['event_name'], 'version' => $schema['version']],
                $schema,
            );
        }
    }

    private function schemas(): array
    {
        $uuid = ['type' => 'string', 'format' => 'uuid'];

        return [
            [
                'event_name' => 'policy.issued', 'version' => 1, 'status' => 'ACTIVE',
                'description' => 'A policy has been issued following carrier authorization.',
                'json_schema' => ['type' => 'object', 'required' => ['policy_id', 'carrier_id'], 'properties' => ['policy_id' => $uuid, 'carrier_id' => $uuid]],
                'example_payload' => ['policy_id' => '0199a1b2-...', 'carrier_id' => '0199a1b3-...'],
                'privacy_classification' => 'INTERNAL',
            ],
            [
                'event_name' => 'claim.transitioned', 'version' => 1, 'status' => 'ACTIVE',
                'description' => 'A claim moved from one lifecycle status to another.',
                'json_schema' => ['type' => 'object', 'required' => ['from', 'to'], 'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string']]],
                'example_payload' => ['from' => 'CARRIER_REVIEW', 'to' => 'DECIDED'],
                'privacy_classification' => 'INTERNAL',
            ],
            [
                'event_name' => 'payment.status.changed', 'version' => 1, 'status' => 'ACTIVE',
                'description' => 'A payment intent transitioned status after provider webhook processing.',
                'json_schema' => ['type' => 'object', 'required' => ['payment_intent_id', 'previous', 'status'], 'properties' => ['payment_intent_id' => $uuid, 'previous' => ['type' => 'string'], 'status' => ['type' => 'string']]],
                'example_payload' => ['payment_intent_id' => '0199a1b4-...', 'previous' => 'PROCESSING', 'status' => 'SUCCEEDED'],
                'privacy_classification' => 'RESTRICTED',
            ],
            [
                'event_name' => 'quote.rated', 'version' => 1, 'status' => 'ACTIVE',
                'description' => 'A quote received one or more carrier offers.',
                'json_schema' => ['type' => 'object', 'required' => ['quote_id', 'status', 'offer_count'], 'properties' => ['quote_id' => $uuid, 'status' => ['type' => 'string'], 'offer_count' => ['type' => 'integer']]],
                'example_payload' => ['quote_id' => '0199a1b5-...', 'status' => 'RATED', 'offer_count' => 3],
                'privacy_classification' => 'INTERNAL',
            ],
            [
                'event_name' => 'proposal.submitted', 'version' => 1, 'status' => 'ACTIVE',
                'description' => 'A proposal was submitted for underwriting review.',
                'json_schema' => ['type' => 'object', 'required' => ['proposal_id', 'underwriting_case_id'], 'properties' => ['proposal_id' => $uuid, 'underwriting_case_id' => $uuid]],
                'example_payload' => ['proposal_id' => '0199a1b6-...', 'underwriting_case_id' => '0199a1b7-...'],
                'privacy_classification' => 'INTERNAL',
            ],
            [
                'event_name' => 'underwriting.decided', 'version' => 1, 'status' => 'ACTIVE',
                'description' => 'An underwriting case reached a decision (APPROVED/DECLINED/...).',
                'json_schema' => ['type' => 'object', 'required' => ['proposal_id', 'decision'], 'properties' => ['proposal_id' => $uuid, 'decision' => ['type' => 'string']]],
                'example_payload' => ['proposal_id' => '0199a1b8-...', 'decision' => 'APPROVED'],
                'privacy_classification' => 'INTERNAL',
            ],
            [
                'event_name' => 'certificate.issued', 'version' => 1, 'status' => 'ACTIVE',
                'description' => 'A policy certificate/sticker was issued.',
                'json_schema' => ['type' => 'object', 'required' => ['certificate_id', 'policy_id'], 'properties' => ['certificate_id' => $uuid, 'policy_id' => $uuid]],
                'example_payload' => ['certificate_id' => '0199a1b9-...', 'policy_id' => '0199a1ba-...'],
                'privacy_classification' => 'INTERNAL',
            ],
            [
                'event_name' => 'renewal.completed', 'version' => 1, 'status' => 'ACTIVE',
                'description' => 'A renewal case produced a successor policy.',
                'json_schema' => ['type' => 'object', 'required' => ['renewal_case_id', 'successor_policy_id'], 'properties' => ['renewal_case_id' => $uuid, 'successor_policy_id' => $uuid]],
                'example_payload' => ['renewal_case_id' => '0199a1bb-...', 'successor_policy_id' => '0199a1bc-...'],
                'privacy_classification' => 'INTERNAL',
            ],
            [
                'event_name' => 'commission.accrued', 'version' => 1, 'status' => 'ACTIVE',
                'description' => 'A commission accrual was recorded against a policy.',
                'json_schema' => ['type' => 'object', 'required' => ['accrual_id'], 'properties' => ['accrual_id' => $uuid]],
                'example_payload' => ['accrual_id' => '0199a1bd-...'],
                'privacy_classification' => 'RESTRICTED',
            ],
        ];
    }
}
