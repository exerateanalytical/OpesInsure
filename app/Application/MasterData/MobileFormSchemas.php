<?php

declare(strict_types=1);

namespace App\Application\MasterData;

/**
 * Selection-first schemas for the mobile forms that are not quote risk
 * wizards: customer profile + beneficiaries, KYC identifier/document, claim
 * FNOL, agent lead. Same field format and contract as the risk wizard
 * (InputFieldContract). Served by GET /api/v1/forms/{form}.
 *
 * Field keys are the request keys of `submit_to`; `submit: false`
 * marks helper pickers used only to filter a child list (e.g. department for
 * city) or to compose a text target (`compose_into`). Endpoints keep
 * accepting strings, so a picked code, or "OTHER" + `{key}_other`, is sent.
 */
final class MobileFormSchemas
{
    public const VERSION = 1;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return array_map([InputFieldContract::class, 'annotate'], [
            'customer_profile' => self::profile(),
            'kyc_identifier' => self::kycIdentifier(),
            'kyc_document' => self::kycDocument(),
            'claim_fnol' => self::claimFnol(),
            'agent_lead' => self::agentLead(),
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function for(string $form): ?array
    {
        return self::all()[strtolower($form)] ?? null;
    }

    private static function profile(): array
    {
        return self::form('PATCH /api/v1/mobile/account/customer-profile', [
            ['identity', 'About you', 'Vous', [
                self::f('date_of_birth', 'Date of birth', 'Date de naissance', 'date', false, ['min' => '1900-01-01', 'max' => 'today']),
                self::m('occupation', 'Occupation', 'Profession', 'occupations', 'occupation', false),
            ]],
            ['address', 'Address', 'Adresse', self::address('')],
            ['beneficiaries', 'Beneficiaries', 'Bénéficiaires', [
                self::f('beneficiaries', 'Beneficiaries', 'Bénéficiaires', 'repeater', false, [
                    'max_items' => 10, 'allocation' => ['field' => 'share_percent', 'total' => 100],
                    'item_fields' => [
                        self::f('name', 'Full name', 'Nom complet', 'text', true, ['max_length' => 160]),
                        self::m('relationship', 'Relationship', 'Lien de parenté', 'persons', 'relationship', true),
                        self::f('share_percent', 'Share (%)', 'Quote-part (%)', 'number', true, ['min' => 0.01, 'max' => 100]),
                    ],
                ]),
            ]],
        ]);
    }

    private static function kycIdentifier(): array
    {
        return self::form('PATCH /api/v1/mobile/kyc/profile', [
            ['identifier', 'Identity document', "Pièce d'identité", [
                self::m('identifier_type', 'Document type', 'Type de pièce', 'documents', 'identity_document', true),
                self::f('identifier_value', 'Document number', 'Numéro de la pièce', 'text', true, ['max_length' => 64]),
                self::m('identifier_country', 'Issuing country', "Pays d'émission", 'geography', 'country', false, ['default' => 'CM', 'other_allowed' => false]),
            ]],
        ]);
    }

    private static function kycDocument(): array
    {
        return self::form('POST /api/v1/mobile/kyc/documents', [
            ['document', 'Upload', 'Téléversement', [
                self::m('purpose', 'Document type', 'Type de document', 'documents', 'identity_document', true),
                self::f('document_id', 'File', 'Fichier', 'file', true),
            ]],
        ]);
    }

    private static function claimFnol(): array
    {
        return self::form('POST /api/v1/mobile/claims', [
            ['incident', 'What happened', "Ce qui s'est passé", [
                self::f('policy_id', 'Policy', 'Contrat', 'select', true, ['endpoint' => '/api/v1/policies', 'options' => null, 'value_key' => 'id']),
                self::m('claim_category', 'Type of claim', 'Type de sinistre', 'claims', 'claim_category', true, ['submit' => false, 'other_allowed' => false]),
                self::m('incident_type', 'Cause', 'Cause', 'claims', 'cause_of_loss', true, ['parent_field' => 'claim_category']),
                self::f('incident_at', 'Date and time', 'Date et heure', 'date', true, ['with_time' => true, 'max' => 'now']),
                self::m('severity', 'Severity', 'Gravité', 'claims', 'severity', false, ['submit' => false, 'other_allowed' => false]),
            ]],
            ['location', 'Where', 'Lieu', self::address('incident_', 'incident_location')],
            ['details', 'Details', 'Détails', [
                self::f('injuries_reported', 'Anyone injured?', 'Des blessés ?', 'boolean', false),
                self::f('police_report_filed', 'Police report filed?', 'Constat de police établi ?', 'boolean', false),
                self::f('police_reference', 'Police report number', 'Numéro du constat', 'text', false, ['max_length' => 120, 'visible_if' => ['police_report_filed' => true]]),
                self::f('estimated_loss_minor', 'Estimated loss (FCFA)', 'Perte estimée (FCFA)', 'money', false, ['min' => 0]),
                self::f('description', 'Describe what happened', 'Décrivez les faits', 'text', true, ['min_length' => 10, 'max_length' => 5000, 'multiline' => true]),
            ]],
        ]);
    }

    private static function agentLead(): array
    {
        return self::form('POST /api/v1/mobile/partner/agent/leads', [
            ['lead', 'Prospect', 'Prospect', [
                self::f('full_name', 'Full name', 'Nom complet', 'text', true, ['max_length' => 160]),
                self::f('phone_e164', 'Phone', 'Téléphone', 'text', true, ['pattern' => '^\+[1-9][0-9]{7,14}$', 'free_text' => ['reason' => 'IDENTIFIER']]),
                self::m('region', 'Region', 'Région', 'geography', 'cameroon_region', false, ['submit' => false]),
                self::m('department', 'Department', 'Département', 'geography', 'cameroon_department', false, ['parent_field' => 'region', 'submit' => false]),
                self::m('city', 'City', 'Ville', 'geography', 'city', false, ['parent_field' => 'department']),
                self::f('product_interest', 'Product of interest', "Produit d'intérêt", 'select', false, ['endpoint' => '/api/v1/catalogue/lines', 'options' => null, 'value_key' => 'code']),
            ]],
        ]);
    }

    /**
     * Region → department → city pickers + street/landmark (the only typed part).
     * With $composeInto the pickers are helpers composed into one text target.
     */
    private static function address(string $prefix, ?string $composeInto = null): array
    {
        $helper = $composeInto ? ['submit' => false, 'compose_into' => $composeInto] : [];
        $fields = [
            self::m($prefix.'region', 'Region', 'Région', 'geography', 'cameroon_region', false, $helper),
            self::m($prefix.'department', 'Department', 'Département', 'geography', 'cameroon_department', false, ['parent_field' => $prefix.'region', 'submit' => false] + $helper),
            self::m($prefix.'city', 'City / town', 'Ville', 'geography', 'city', false, ['parent_field' => $prefix.'department'] + $helper),
        ];
        $fields[] = $composeInto
            ? self::f($composeInto, 'Street / landmark', 'Rue / repère', 'text', false, ['max_length' => 255, 'compose_from' => [$prefix.'city', $prefix.'department', $prefix.'region']])
            : self::f('address_line1', 'Street / landmark', 'Rue / repère', 'text', false, ['max_length' => 255]);

        return $fields;
    }

    private static function form(string $endpoint, array $steps): array
    {
        $fields = [];
        foreach ($steps as [$key, , , $stepFields]) {
            foreach ($stepFields as $f) {
                $fields[] = ['step' => $key] + $f;
            }
        }

        return [
            'version' => self::VERSION,
            'submit_to' => $endpoint,
            'steps' => array_map(fn ($s) => ['key' => $s[0], 'label' => $s[1], 'label_en' => $s[1], 'label_fr' => $s[2]], $steps),
            'fields' => $fields,
        ];
    }

    private static function f(string $key, string $en, string $fr, string $type, bool $required, array $extra = []): array
    {
        return array_filter(['key' => $key, 'label' => $en, 'label_en' => $en, 'label_fr' => $fr, 'type' => $type, 'required' => $required] + $extra, fn ($v) => $v !== null);
    }

    private static function m(string $key, string $en, string $fr, string $domain, string $list, bool $required, array $extra = []): array
    {
        return self::f($key, $en, $fr, 'select_master', $required, $extra + ['source' => ['domain' => $domain, 'list' => $list], 'other_allowed' => true]);
    }
}
