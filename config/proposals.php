<?php

/*
 | Batch 6D — proposal workflow configuration (REQ-PRP-002 / 003 / 005).
 |
 | Declaration statements are platform wording, NOT reviewed legal text: legal_status stays UNVERIFIED_LEGAL_WORDING (owner decision 30)
 | until counsel approves a version (then bump `version` so earlier acceptances keep their own hash).
 */
return [

    'declarations' => [
        'DISCLOSURE_ACCURACY' => [
            'version' => '2026-10-v1',
            'legal_status' => 'UNVERIFIED_LEGAL_WORDING',
            'required_for_submit' => true,
            'statement' => [
                'en' => 'I declare that the answers I have given are true and complete to the best of my knowledge.',
                'fr' => "Je déclare que les réponses fournies sont exactes et complètes à ma connaissance.",
            ],
        ],
        'TERMS_ACCEPTANCE' => [
            'version' => '2026-10-v1',
            'legal_status' => 'UNVERIFIED_LEGAL_WORDING',
            'required_for_submit' => false,
            'statement' => [
                'en' => 'I accept the offered terms, premium and conditions shown to me.',
                'fr' => "J'accepte les conditions, la prime et les garanties qui m'ont été présentées.",
            ],
        ],
        'DATA_PROCESSING_CONSENT' => [
            'version' => '2026-10-v1',
            'legal_status' => 'UNVERIFIED_LEGAL_WORDING',
            'required_for_submit' => false,
            'statement' => [
                'en' => 'I consent to the processing of my data to assess this proposal.',
                'fr' => "J'accepte le traitement de mes données pour l'évaluation de cette proposition.",
            ],
        ],
    ],

    /*
     | Document requirements come from DocumentCatalogueService::requirementsFor(product, PRE_CONTRACT).
     | Rows issued by the insurer/platform are generated, not uploaded. Rows whose canonical code matches one of
     | these patterns are the proposal form itself and are satisfied by the attested digital questionnaire.
     */
    'documents' => [
        'stage' => 'PRE_CONTRACT',
        'uploader_issuers' => ['CUSTOMER', 'THIRD_PARTY', 'POLICYHOLDER', 'INSURED', null],
        'mandatory_levels' => ['M', 'T'],
        'optional_levels' => ['C', 'O'],
        'form_satisfied_patterns' => ['/PROPOSAL/', '/RISK_DECLARATION/', '/QUESTIONNAIRE/'],
        // Owner decision 31: submission may accept UPLOADED_NOT_YET_REVIEWED documents (per product override:
        // insurance_products.submission_accepts_unreviewed_documents); issuance always needs ACCEPTED.
        'submission_accepts_unreviewed' => true,
        // Automated controls the owner has approved to accept ISSUANCE_REQUIRED documents. None yet.
        'approved_automated_controls' => [],
    ],

    /* REQ-PRP-005 defaults when no cover_term_rules row applies (keeps today's behaviour: start now, 12 months, single payment). */
    'cover_terms' => [
        'effective_date_rules' => ['IMMEDIATE', 'SPECIFIED_DATE'],
        'default_effective_rule' => 'IMMEDIATE',
        'durations' => [['unit' => 'MONTH', 'value' => 12]],
        'instalment_plans' => ['SINGLE'],
        'max_advance_days' => 90,
        'timezone' => 'Africa/Douala',
    ],
];
