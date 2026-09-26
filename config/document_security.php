<?php

declare(strict_types=1);

/*
 | Canonical document security (docs/spec/canonical/OpesInsure_Canonical_Implementation_Specification_v1.json:
 | document_system, document_implementation_policy, cryptographic_print_verification_security).
 |
 | Nothing here is a regulatory or insurer value. Secrets are never committed: the signing key is read
 | from the environment / a key file outside the repository. When it is absent the signature control is
 | recorded as CONFIG_REQUIRED on every document that needs it (never faked).
 */
return [
    // Canonical spec source (seeded idempotently on deploy through `optimize`).
    'spec_path' => env('DOCUMENT_SPEC_PATH', base_path('docs/spec/canonical/OpesInsure_Canonical_Implementation_Specification_v1.json')),
    'manifest_path' => env('DOCUMENT_SPEC_MANIFEST_PATH', base_path('docs/spec/canonical/OpesInsure_Canonical_Implementation_Manifest_v1.json')),

    // Security Matrix v1 markdown (§4–11: physical / watermark / seal profiles, public verification rules).
    'security_matrix_path' => env('DOCUMENT_SECURITY_MATRIX_PATH', base_path('docs/spec/canonical/OpesInsure_220_Document_Security_Matrix_v1.md')),

    // Field completeness (document_implementation_policy §1.1): a required field whose canonical source
    // exists but is empty blocks issuance of that document (pack item state BLOCKED_MISSING_FIELDS).
    'field_enforcement' => env('DOCUMENT_FIELD_ENFORCEMENT', 'block'), // block | record

    // Security acceptance gate (crypto spec §51): when true, an S3+ document whose required controls are
    // CONFIG_REQUIRED (no signing key, no maker-checker evidence) is not issued. Off until the owner
    // provisions the signing key; the per-document control status is always recorded either way.
    'enforce_controls' => (bool) env('DOCUMENT_ENFORCE_CONTROLS', false),

    // S5 (controlled physical stock, UV, hologram) needs real secure printing, serial inventory and custody.
    // No such operation exists yet: S5 controls are CONFIG_REQUIRED and documents issue at their digital floor.
    'physical_issuance_enabled' => (bool) env('DOCUMENT_PHYSICAL_ISSUANCE', false),

    'verification' => [
        // Authoritative verifier; the QR only points here (crypto spec §10/§11).
        'url' => env('DOCUMENT_VERIFY_URL', env('POLICY_VERIFY_URL', 'https://insurance.opesdatacenter.tech/verify')),
        'token_bytes' => 16, // >= 128 bits (crypto spec §9.1)
        // Registration number on public verification of PUBLIC_VERIFY motor proofs: FULL | PARTIAL (crypto spec §27).
        'registration_disclosure' => env('DOCUMENT_VERIFY_REGISTRATION', 'FULL'),
    ],

    'signing' => [
        // Ed25519 (libsodium) detached signature over the canonical signing payload (file hash, content
        // hash, snapshot hash, template hash, number, issue time). PAdES embedding and RFC 3161 timestamps
        // need a PAdES library / TSA contract: CONFIG_REQUIRED.
        'algorithm' => 'ED25519',
        // base64 of the 64-byte sodium secret key, or a path to a file holding it (outside the repository).
        'private_key' => env('DOCUMENT_SIGNING_KEY'),
        'private_key_path' => env('DOCUMENT_SIGNING_KEY_PATH'),
        'key_id' => env('DOCUMENT_SIGNING_KEY_ID'),
        // The environment the key was issued for; a key is refused in any other environment (crypto spec §7.2).
        'key_environment' => env('DOCUMENT_SIGNING_KEY_ENV'),
        // Retired public keys kept for validating historical documents (crypto spec §7.5): {"key_id":"base64 public key"}.
        'public_keys' => json_decode((string) env('DOCUMENT_SIGNING_PUBLIC_KEYS', '{}'), true) ?: [],
        'tsa_url' => env('DOCUMENT_TSA_URL'), // RFC 3161 TSA: CONFIG_REQUIRED while null
    ],
];
