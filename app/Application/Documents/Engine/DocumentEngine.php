<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Application\Audit\AuditWriter;
use App\Application\Documents\Security\DocumentFieldRequirements;
use App\Application\Documents\Security\DocumentSecurityProfile;
use App\Application\Documents\Security\DocumentSigner;
use App\Application\Documents\Security\VerificationCredentials;
use App\Application\Notifications\CustomerNotifier;
use App\Application\Shared\CanonicalJson;
use App\Models\Claim;
use App\Models\Document;
use App\Models\DocumentIssuanceProfile;
use App\Models\DocumentPackManifest;
use App\Models\DocumentTemplate;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyTransaction;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pack generation. The ONLY entry point is fire(trigger, policy, context):
 * every document is produced by a lifecycle trigger whose state is checked
 * first (POLICY_ISSUED needs an issued in-force policy, ENDORSEMENT_ISSUED an
 * APPROVED endorsement transaction, CLAIM_APPROVED an approved claim …) —
 * there is no arbitrary "generate this document" call.
 *
 * For each pack item (class + trigger + product overrides, expanded per
 * vehicle / member / shipment):
 *  - LINK items (declarations, questionnaires, proposals): linked if on file;
 *  - a current carrier original for the type → CARRIER_PROVIDED (official);
 *  - otherwise the issuer is resolved (INSURER / BROKER / PLATFORM); an
 *    INSURER document is rendered by OpesInsure only when that insurer's
 *    issuance profile is OPES_GENERATED/HYBRID and authorizes rendering;
 *    else AWAITING_CARRIER_DOCUMENT;
 *  - a published template is resolved (insurer > broker > regulatory >
 *    platform); none → AWAITING_CARRIER_DOCUMENT / TEMPLATE_MISSING;
 *  - GENERATED: stored PDF + sha256 + continuous number + verification code;
 *    the previous current document of the same kind/subject is SUPERSEDED.
 * One manifest per (policy, trigger, event) records it all (idempotent).
 */
final class DocumentEngine
{
    public const SCHEDULE_KINDS = ['POLICY_SCHEDULE', 'REVISED_POLICY_SCHEDULE', 'LIFE_POLICY_SCHEDULE'];

    public function __construct(
        private DocumentRegister $register,
        private DocumentPackResolver $packs,
        private DocumentTemplateService $templates,
        private DocumentNumberAllocator $numbers,
        private AuditWriter $audit,
        private CustomerNotifier $notifier,
        private DocumentSecurityProfile $security,
        private DocumentFieldRequirements $fields,
        private DocumentSigner $signer,
        private CanonicalJson $json,
    ) {}

    /**
     * @param array{transaction?: PolicyTransaction, claim?: Claim, payment?: PaymentIntentRecord, cover_note?: bool, preauth?: object, extension?: object, subject?: array{type: string, key: string, label: string}, valid_from?: string, valid_until?: string} $ctx
     */
    public function fire(string $trigger, Policy $policy, array $ctx = [], ?User $actor = null): DocumentPackManifest
    {
        [$reference, $extra] = $this->assertState($trigger, $policy, $ctx);

        return DB::transaction(function () use ($trigger, $policy, $ctx, $actor, $reference, $extra): DocumentPackManifest {
            $existing = DocumentPackManifest::where(['policy_id' => $policy->id, 'trigger' => $trigger, 'event_reference' => $reference])->first();
            if ($existing) {
                return $existing;
            }
            $policy = Policy::with(['carrier.party', 'party', 'tenant', 'proposal.offer.product', 'proposal.offer.quote'])->findOrFail($policy->id);
            $pack = $this->packs->resolve($policy, $trigger, $this->included($trigger, $ctx));
            [$label, $sequence] = $this->label($trigger, $policy, $ctx);

            $manifest = DocumentPackManifest::create([
                'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id, 'pack_code' => $pack['pack_code'], 'trigger' => $trigger,
                'event_reference' => $reference, 'event_label' => $label, 'sequence' => $sequence, 'policy_version' => (int) $policy->version,
                'items' => [], 'generated_at' => now(),
            ]);

            if ($trigger === 'CANCELLATION_ISSUED') {
                $this->closeCurrentCertificates($policy, 'CANCELLED', 'Policy cancelled ('.$label.')', $actor);
            }

            $items = [];
            foreach ($pack['items'] as $item) {
                // REQ-HLT-002: preauthorization documents are per preauthorization (never supersede another member's GOP).
                if (! $item['per_subject'] && isset($ctx['subject']['type'])) {
                    $item['per_subject'] = $ctx['subject']['type'];
                }
                $subjects = isset($ctx['subject']['type']) && $item['per_subject'] === $ctx['subject']['type'] ? [$ctx['subject']]
                    : ($item['per_subject'] ? $this->packs->subjects($policy, $item['per_subject']) : []);
                foreach ($subjects ?: [null] as $subject) {
                    $items[] = $this->produce($policy, $manifest, $pack, $item, $subject, $trigger, $ctx + $extra, $label, $actor);
                }
            }
            $manifest->update(['items' => $items]);
            $generated = count(array_filter($items, fn ($i) => $i['state'] === 'GENERATED'));
            $this->audit->record('document.pack.generated', 'document_pack_manifest', $manifest->id, ['policy_id' => $policy->id, 'trigger' => $trigger, 'pack_code' => $pack['pack_code'], 'generated' => $generated]);
            if ($generated > 0) {
                $this->notifyCustomer($policy, $generated, $label);
            }

            return $manifest->refresh();
        });
    }

    /**
     * The customer learns about new documents through the one customer
     * notifier (inbox + push, SMS fallback). Never throws (CustomerNotifier
     * swallows and logs), so notification cannot fail the pack.
     */
    private function notifyCustomer(Policy $policy, int $count, string $label): void
    {
        $title = $count === 1 ? 'New document available' : 'New documents available';
        $body = sprintf('%d new document%s for policy %s (%s). Open your policy to view and download.', $count, $count === 1 ? '' : 's', (string) $policy->policy_number, $label);
        $this->notifier->toParty($policy->party_id, $policy->tenant_id, 'DOCUMENT', $title, $body, 'INFO', "/policy/{$policy->id}");
    }

    /**
     * D4 - provider-flow documents (canonical DOC-064 eligibility confirmation, DOC-065 preauthorization
     * request, DOC-072 explanation of benefits, DOC-198 provider settlement statement, DOC-215 provider
     * contract, DOC-216 provider tariff schedule). They belong to a provider event rather than to a policy
     * pack: no manifest, the policy is optional (a contract or a settlement has none), and the document is
     * addressed to the provider (documents.provider_profile_id). Same finalization sequence as a pack item
     * (issuer authorization, published template, required fields, number, verification code, snapshot,
     * master shell, signature, immutable record). Idempotent per (type, subject, trigger).
     *
     * @param  array{tenant_id: string, carrier_id: ?string, currency?: ?string, policy?: ?Policy, provider_id: string, subject: array{type: string, key: string, label: string}, fields?: array<string, mixed>, sources?: array<string, mixed>, valid_from?: mixed, valid_until?: mixed}  $ctx
     * @return array<int, array<string, mixed>> one item per issued type (state GENERATED + document_id, or the blocking state and reason)
     */
    public function issueProviderDocument(string $trigger, array $ctx, ?User $actor = null): array
    {
        $codes = self::PROVIDER_TRIGGERS[$trigger] ?? throw ValidationException::withMessages(['trigger' => "Document trigger {$trigger} refused: unsupported provider trigger."]);
        if (empty($ctx['tenant_id']) || empty($ctx['provider_id']) || empty($ctx['subject']['key'])) {
            throw ValidationException::withMessages(['trigger' => "Document trigger {$trigger} refused: tenant, provider and subject are required."]);
        }

        return DB::transaction(function () use ($trigger, $codes, $ctx, $actor): array {
            $policy = isset($ctx['policy']) && $ctx['policy'] instanceof Policy
                ? Policy::with(['carrier.party', 'party', 'tenant', 'proposal.offer.product', 'proposal.offer.quote'])->findOrFail($ctx['policy']->id)
                // No policy (contract, tariff, settlement): a transient, never-saved issuing context.
                : (new Policy())->forceFill(['tenant_id' => $ctx['tenant_id'], 'carrier_id' => $ctx['carrier_id'] ?? null, 'currency' => $ctx['currency'] ?? 'XAF', 'version' => 0]);
            $label = self::PROVIDER_LABELS[$trigger].' '.$ctx['subject']['label'];
            $out = [];
            foreach ($codes as $code) {
                $existing = Document::where('tenant_id', $ctx['tenant_id'])->where('document_type_code', $code)->where('subject_key', $ctx['subject']['key'])
                    ->where('generation_trigger', $trigger)->whereIn('status', DocumentRegister::CURRENT_STATUSES)->latest('created_at')->first();
                if ($existing) {
                    $out[] = ['state' => 'GENERATED', 'document_id' => $existing->id, 'document_type_code' => $code, 'idempotent' => true];

                    continue;
                }
                $item = ['document_type_code' => $code, 'required_level' => 'REQUIRED', 'per_subject' => $ctx['subject']['type'], 'mode' => 'GENERATE'];
                $out[] = $this->produce($policy, null, ['pack_code' => 'PROVIDER_'.$trigger, 'insurance_class' => 'HEALTH'], $item, $ctx['subject'], $trigger, $ctx, $label, $actor);
            }
            $this->audit->record('document.provider.issued', 'provider', $ctx['provider_id'], ['trigger' => $trigger, 'subject' => $ctx['subject']['key'],
                'items' => array_map(fn ($i) => ['type' => $i['document_type_code'], 'state' => $i['state'], 'document_id' => $i['document_id'] ?? null], $out)]);

            return $out;
        });
    }

    /** Provider trigger => canonical document codes it issues (D4). */
    public const PROVIDER_TRIGGERS = [
        'ELIGIBILITY_CHECKED' => ['ELIGIBILITY_CONFIRMATION'],
        'PREAUTH_SUBMITTED' => ['PREAUTHORIZATION_REQUEST'],
        'PROVIDER_CLAIM_ADJUDICATED' => ['EXPLANATION_OF_BENEFITS'],
        'PROVIDER_SETTLEMENT_PAID' => ['PROVIDER_SETTLEMENT_STATEMENT'],
        'PROVIDER_CONTRACT_ACTIVATED' => ['PROVIDER_CONTRACT'],
        'PROVIDER_TARIFF_APPROVED' => ['PROVIDER_TARIFF_SCHEDULE'],
    ];

    private const PROVIDER_LABELS = [
        'ELIGIBILITY_CHECKED' => 'ELIGIBILITY / ÉLIGIBILITÉ', 'PREAUTH_SUBMITTED' => 'PREAUTHORIZATION REQUEST / DEMANDE DE PRISE EN CHARGE',
        'PROVIDER_CLAIM_ADJUDICATED' => 'EOB / RELEVÉ DES PRESTATIONS', 'PROVIDER_SETTLEMENT_PAID' => 'SETTLEMENT / RÈGLEMENT',
        'PROVIDER_CONTRACT_ACTIVATED' => 'PROVIDER CONTRACT / CONVENTION', 'PROVIDER_TARIFF_APPROVED' => 'TARIFF / GRILLE TARIFAIRE',
    ];

    /** Same as fire() but never throws into the caller's business transaction (savepoint + report). */
    public function fireQuietly(string $trigger, Policy $policy, array $ctx = [], ?User $actor = null): ?DocumentPackManifest
    {
        try {
            return DB::transaction(fn () => $this->fire($trigger, $policy, $ctx, $actor));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Conditional pack documents whose condition this event meets: explicit
     * ctx['include'], a cover note request, and — on an endorsement touching the
     * vehicle/driver/usage — the re-issued motor attestation and certificate.
     *
     * @return array<int, string>
     */
    private function included(string $trigger, array $ctx): array
    {
        $include = (array) ($ctx['include'] ?? []);
        if (! empty($ctx['cover_note'])) {
            $include[] = 'COVER_NOTE';
        }
        $t = $ctx['transaction'] ?? null;
        if ($trigger === 'ENDORSEMENT_ISSUED' && $t instanceof PolicyTransaction
            && preg_grep('/vehicle|registration|usage|driver|subjects/i', array_keys((array) $t->requested_changes))) {
            array_push($include, 'MOTOR_INSURANCE_ATTESTATION', 'MOTOR_INSURANCE_CERTIFICATE', 'VEHICLE_REPLACEMENT_ENDORSEMENT');
        }

        return array_values(array_unique($include));
    }

    /** Effective status: an ISSUED/VALID document past its validity is EXPIRED. */
    public static function effectiveStatus(Document $d): string
    {
        $status = (string) ($d->status ?? 'VALID');
        if (in_array($status, ['VALID', 'ISSUED'], true) && $d->valid_until && $d->valid_until->isPast()) {
            return 'EXPIRED';
        }

        return $status;
    }

    public static function supersessionKey(string $code): string
    {
        return in_array($code, self::SCHEDULE_KINDS, true) ? 'SCHEDULE' : $code;
    }

    /** @return array<int, string> codes sharing the supersession key */
    public static function kindCodes(string $code): array
    {
        return self::supersessionKey($code) === 'SCHEDULE' ? self::SCHEDULE_KINDS : [$code];
    }

    public function profileFor(Policy $policy): ?DocumentIssuanceProfile
    {
        $productId = $policy->proposal?->offer?->product_id;

        return DocumentIssuanceProfile::where('carrier_id', $policy->carrier_id)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $productId))
            ->orderByRaw('product_id IS NULL')->first();
    }

    public static function mayRenderForInsurer(?DocumentIssuanceProfile $profile): bool
    {
        return $profile !== null && in_array($profile->issuance_mode, ['OPES_GENERATED', 'HYBRID'], true) && $profile->opes_rendering_authorized;
    }

    /** Issuer from the catalogue's document_origin (BROKER only for a broker tenant; SYSTEM = platform). */
    public function issuerFor(string $code, Policy $policy): string
    {
        $type = $this->register->describe($code);
        $origin = $type['origin'] ?? null;
        $broker = $policy->tenant?->type === 'BROKER';

        return match (true) {
            // PROVIDER origin (preauthorization request): compiled by the platform from the provider's submission.
            $origin === 'SYSTEM' || $origin === 'PROVIDER' || $code === 'POLICY_ISSUE_CONFIRMATION' => 'PLATFORM',
            $broker && ($origin === 'BROKER' || ($origin === null && $type['group_code'] === 'PRE_CONTRACT')) => 'BROKER',
            default => 'INSURER',
        };
    }

    /** One short-code generator for every issuer (checksummed, crypto spec §12): VerificationCredentials. */
    public static function newVerificationCode(): string
    {
        return VerificationCredentials::newShortCode();
    }

    /** @return array<string, mixed> manifest item */
    private function produce(Policy $policy, ?DocumentPackManifest $manifest, array $pack, array $item, ?array $subject, string $trigger, array $ctx, string $label, ?User $actor): array
    {
        $code = $item['document_type_code'];
        $type = $this->register->describe($code);
        $base = ['document_type_code' => $code, 'document_type_id' => $type['id'], 'title_en' => $type['name_en'], 'title_fr' => $type['name_fr'],
            'display_group' => $type['display_group'], 'required_level' => $item['required_level'], 'subject_type' => $subject ? $item['per_subject'] : null,
            'subject_key' => $subject['key'] ?? null, 'subject_label' => $subject['label'] ?? null, 'document_id' => null];

        $current = $this->currentOf($policy, $code, $subject['key'] ?? null);

        if ($item['mode'] === 'LINK') {
            $onFile = Document::where('policy_id', $policy->id)->where('document_type_code', $code)->latest('created_at')->first();

            return ['state' => $onFile ? 'ON_FILE' : 'NOT_ON_FILE', 'document_id' => $onFile?->id] + $base;
        }
        if ($item['mode'] === 'CONDITIONAL') {
            return ['state' => 'CONDITIONAL_NOT_TRIGGERED'] + $base;
        }
        if ($current && $current->is_carrier_original && $current->policy_version >= $policy->version) {
            return ['state' => 'CARRIER_PROVIDED', 'document_id' => $current->id] + $base;
        }

        $issuer = $this->issuerFor($code, $policy);
        $profile = $this->profileFor($policy);
        if ($issuer === 'INSURER' && ! self::mayRenderForInsurer($profile)) {
            return ['state' => 'AWAITING_CARRIER_DOCUMENT', 'reason' => 'ISSUER_NOT_AUTHORIZED'] + $base;
        }

        $class = $pack['insurance_class'];
        $language = $profile?->default_language ?? 'BILINGUAL';
        $template = $this->templates->resolve($code, [
            'carrier_id' => $policy->carrier_id, 'tenant_id' => $policy->tenant_id, 'broker' => $policy->tenant?->type === 'BROKER',
            'product_id' => $policy->proposal?->offer?->product_id, 'insurance_class' => in_array($class, ['LIFE', 'GROUP_LIFE'], true) ? 'LIFE' : $class,
        ], $language);
        if (! $template) {
            return ['state' => $issuer === 'INSURER' ? 'AWAITING_CARRIER_DOCUMENT' : 'TEMPLATE_MISSING', 'reason' => 'NO_PUBLISHED_TEMPLATE'] + $base;
        }

        try {
            $doc = $this->generate($policy, $manifest, $template, $type, $issuer, $profile, $subject, $item['per_subject'], $trigger, $ctx, $label, $actor);
        } catch (DocumentIssuanceBlocked $blocked) {
            // Canonical spec: a missing required field / unmet required control blocks issuance with a clear
            // reason — never a blank document. Nothing was numbered or stored (no numbering gap).
            $this->audit->record('document.issuance.blocked', $manifest ? 'document_pack_manifest' : 'provider', $manifest?->id ?? ($ctx['provider_id'] ?? null), ['type' => $code, 'state' => $blocked->state, 'missing' => $blocked->missing, 'subject' => $subject['key'] ?? null]);

            return ['state' => $blocked->state, 'reason' => $blocked->getMessage(), 'missing_fields' => $blocked->missing, 'template_id' => $template->id] + $base;
        }

        return ['state' => 'GENERATED', 'document_id' => $doc->id, 'template_id' => $template->id, 'template_version' => $template->version,
            'security_tier' => $doc->security_tier] + $base;
    }

    /**
     * Canonical finalization sequence (crypto spec §4): template -> canonical entities -> required-field
     * validation (before numbering: a blocked document consumes no number) -> number -> verification
     * token + short code -> frozen issuance snapshot (+ snapshot hash) -> content hash -> deterministic
     * render in the master shell with the tier's security controls -> final file SHA-256 -> platform
     * signature (CONFIG_REQUIRED without a key) -> immutable registry record -> audit -> ISSUED/VALID.
     */
    private function generate(Policy $policy, ?DocumentPackManifest $manifest, DocumentTemplate $template, array $type, string $issuer, ?DocumentIssuanceProfile $profile, ?array $subject, ?string $subjectType, string $trigger, array $ctx, string $label, ?User $actor): Document
    {
        $code = $type['code'];
        $certificateLike = $type['display_group'] === 'CERTIFICATES';
        $product = $policy->proposal?->offer?->product;
        $lang = $template->language;
        $carrierName = $policy->carrier?->party?->display_name ?? 'Insurer';
        $broker = $policy->tenant?->type === 'BROKER' ? ['name' => $policy->tenant->legal_name, 'licence' => $policy->tenant->getAttributes()['licence_number'] ?? null] : null;
        $issuerName = $issuer === 'BROKER' && $broker ? $broker['name'] : ($issuer === 'PLATFORM' ? 'OpesInsure' : $carrierName);
        $transaction = $ctx['transaction'] ?? null;
        $claim = $ctx['claim'] ?? null;
        // Letterhead (issuer + co-branding artwork); its version and file hashes are frozen into the snapshot and provenance.
        $letterhead = \App\Application\Documents\Letterhead\LetterheadResolver::forDocument($issuer, $issuerName, $policy->carrier_id, $carrierName, $policy->tenant_id, $broker);

        // Security profile (never below the catalogue's canonical floor).
        $evidence = $transaction instanceof PolicyTransaction && $transaction->approved_by && $transaction->approved_by !== $transaction->requested_by
            ? 'POLICY_TRANSACTION_APPROVED:'.$transaction->id : null;
        // Issuance state behind the Security Matrix §6 seals (a seal only when its backend authority exists).
        $security = $this->security->resolve($type, $profile, ['maker_checker_evidence' => $evidence, 'issuer_type' => $issuer, 'carrier_id' => $policy->carrier_id,
            'payment_reconciled' => $trigger === 'PAYMENT_RECONCILED', 'claim_authorized' => $trigger === 'CLAIM_APPROVED' && $claim !== null,
            'provider_guarantee' => in_array($trigger, ['PREAUTH_APPROVED', 'PREAUTH_PARTIALLY_APPROVED', 'PREAUTH_EXTENSION_APPROVED'], true),
            'duplicate' => (bool) ($ctx['duplicate_of'] ?? false)]);

        // Canonical field values + required-field validation (numbering not yet consumed).
        $subjectFacts = $this->packs->subjectFacts($policy, $subject ? $subjectType : null, $subject['key'] ?? null);
        $issuedAt = now();
        $requirements = $this->fields->requiredKeys($type, $subject ? $subjectType : null);
        $pendingNumber = '(pending)';
        // Event-supplied canonical values (provider flows: provider.name, member.reference ...) fill what the policy cannot.
        $values = $this->fields->resolve($policy, (array) ($ctx['fields'] ?? []) + [
            'document.number' => $pendingNumber, 'document.type_code' => $code, 'document.title' => trim($template->title_en.' / '.$template->title_fr, ' /'),
            'document.template_version' => $template->version, 'document.issued_at' => $issuedAt->toIso8601String(), 'document.status' => $certificateLike ? 'VALID' : 'ISSUED',
            'document.security_tier' => $security['tier'], 'issuer.legal_name' => $issuerName, 'verification.code' => 'pending', 'verification.token' => 'pending',
            'template.reference' => $template->code.' v'.$template->version, 'confidentiality.class' => $security['confidentiality_class'],
        ], $subject ? $subject + ['type' => $subjectType] : null, $subjectFacts, $ctx);
        $missing = DocumentFieldRequirements::missing($requirements['required'], $values);
        if ($missing !== [] && config('document_security.field_enforcement', 'block') === 'block') {
            throw new DocumentIssuanceBlocked('BLOCKED_MISSING_FIELDS', $missing, 'Required document fields missing: '.DocumentFieldRequirements::describeMissing($missing));
        }
        // Security Matrix §9 issuance gate (steps 1-6, 10, 11 before numbering): FAIL always refuses; CONFIG_REQUIRED /
        // PENDING_VERIFICATION refuse only under DOCUMENT_ENFORCE_CONTROLS. Recorded on the document (security_controls.issuance_gate).
        $gate = \App\Application\Documents\Security\IssuanceGate::evaluate($policy, $template, $security, [
            'issuer' => $issuer, 'issuer_authorized' => $issuer !== 'INSURER' || self::mayRenderForInsurer($profile), 'claim' => $claim, 'transaction' => $transaction,
            'missing_fields' => $missing, 'field_enforcement' => (string) config('document_security.field_enforcement', 'block'),
            // D4: a provider document's source is its provider event (contract, tariff, settlement ...), not a policy.
            'provider_source' => isset($ctx['provider_id']) && ! empty($ctx['subject']['key']) ? $ctx['subject']['key'] : null,
        ]);
        $refused = \App\Application\Documents\Security\IssuanceGate::refusals($gate, (bool) config('document_security.enforce_controls'));
        if ($refused !== []) {
            throw new DocumentIssuanceBlocked('BLOCKED_ISSUANCE_GATE', $refused, 'Security issuance gate refused: '.implode('; ', $refused));
        }
        if (config('document_security.enforce_controls') && $security['unmet_required_controls'] !== []) {
            throw new DocumentIssuanceBlocked('BLOCKED_SECURITY_CONTROLS', $security['unmet_required_controls'], 'Required security controls not provisioned (CONFIG_REQUIRED): '.implode(', ', $security['unmet_required_controls']));
        }

        $number = $this->numbers->allocate($policy->tenant_id, $code);
        $verification = self::newVerificationCode();
        $token = VerificationCredentials::newToken();
        $verifyBase = rtrim((string) config('document_security.verification.url', config('lifecycle.verify_url')), '/');
        $verifyUrl = $verifyBase.'?code='.$verification.'&t='.$token;
        $values = array_merge($values, ['document.number' => $number['number'], 'verification.code' => $verification, 'verification.token' => 'sha256:'.VerificationCredentials::tokenHash($token)]);

        $vars = [
            '{policy_number}' => (string) $policy->policy_number, '{insured_name}' => (string) ($policy->party?->display_name ?? ''),
            '{carrier_name}' => $carrierName, '{product_name}' => (string) ($product?->name ?? ''), '{subject}' => (string) ($subject['label'] ?? ''),
            '{coverage_start}' => (string) $policy->coverage_starts_at?->format('d/m/Y'), '{coverage_end}' => (string) $policy->coverage_ends_at?->format('d/m/Y'),
            '{document_number}' => $number['number'], '{event}' => $label,
        ];
        $sections = [];
        foreach ((array) ($template->content['sections'] ?? []) as $s) {
            $paragraphs = [];
            foreach (['fr', 'en'] as $l) {
                if (($lang === 'BILINGUAL' || strtolower($lang) === $l) && ! empty($s['body_'.$l])) {
                    $paragraphs[] = strtr((string) $s['body_'.$l], $vars);
                }
            }
            $heading = $lang === 'EN' ? ($s['heading_en'] ?? null) : ($lang === 'FR' ? ($s['heading_fr'] ?? null) : trim(($s['heading_fr'] ?? '').' / '.($s['heading_en'] ?? ''), ' /'));
            $sections[] = ['heading' => $heading, 'paragraphs' => $paragraphs];
        }
        // Event facts rendered after the template text (provider flows: eligibility result, EOB lines, settlement lines ...).
        foreach ((array) ($ctx['sections'] ?? []) as $s) {
            $sections[] = ['heading' => (string) ($s['heading'] ?? ''), 'paragraphs' => array_values(array_map('strval', (array) ($s['paragraphs'] ?? [])))];
        }

        // Frozen issuance snapshot (document_implementation_policy §1.4) and its hashes (crypto spec §5).
        $snapshot = [
            'document' => ['type_code' => $code, 'type_id' => $type['id'], 'canonical_spec_id' => $security['canonical_spec_id'], 'number' => $number['number'],
                'issued_at_utc' => $issuedAt->copy()->utc()->toIso8601String(), 'timezone' => config('app.timezone'), 'language' => $lang, 'event' => $label, 'trigger' => $trigger],
            'template' => ['id' => $template->id, 'code' => $template->code, 'version' => $template->version, 'hash' => $template->content_hash, 'ownership' => $template->ownership],
            'issuer' => ['type' => $issuer, 'name' => $issuerName, 'carrier_id' => $policy->carrier_id, 'tenant_id' => $policy->tenant_id, 'intermediary' => $broker,
                'authorization_reference' => $profile?->authorization_reference, 'letterhead' => $letterhead['snapshot']],
            'sources' => ['party_id' => $policy->party_id, 'policy_id' => $policy->id, 'policy_version' => (int) $policy->version, 'product_id' => $product?->id,
                'product_version' => $product?->version, 'claim_id' => $claim?->id, 'policy_transaction_id' => $transaction?->id, 'payment_id' => ($ctx['payment'] ?? null)?->id]
                + (isset($ctx['provider_id']) ? ['provider_profile_id' => $ctx['provider_id']] + (array) ($ctx['sources'] ?? []) : []),
            'subject' => $subject ? ['type' => $subjectType, 'key' => $subject['key'], 'label' => $subject['label'], 'facts' => $subjectFacts] : null,
            'fields' => $values, 'required_fields' => $requirements['required'],
            'security' => ['tier' => $security['tier'], 'confidentiality_class' => $security['confidentiality_class'], 'access_profiles' => $security['access_profiles'], 'master_shell_code' => $security['master_shell_code']],
        ];
        $snapshotHash = $this->json->hash($snapshot);
        $contentHash = $this->json->hash(['snapshot_hash' => $snapshotHash, 'template_hash' => $template->content_hash, 'sections' => $sections]);

        $qr = null;
        if ($security['controls']['qr']['status'] === 'APPLIED' || ! $profile || $profile->qr_enabled) {
            // The QR carries only the verifier URL + random token (crypto spec §11): no identity, no amounts.
            $qr = (new QRCode(new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true])))->render($verifyUrl);
        }
        $bytes = Pdf::loadView('pdf.engine-shell', DocumentShellView::data([
            'lang' => $lang, 'template' => $template, 'type' => $type, 'security' => $security, 'values' => $values, 'requirements' => $requirements,
            'number' => $number['number'], 'issuerName' => $issuerName, 'carrierName' => $carrierName, 'intermediary' => $broker, 'policy' => $policy, 'product' => $product,
            'subject' => $subject, 'subjectFacts' => $subjectFacts, 'label' => $label, 'issuedAt' => $issuedAt, 'verification' => $verification, 'qr' => $qr,
            'verifyUrl' => $verifyBase, 'sections' => $sections, 'contentHash' => $contentHash, 'profile' => $profile, 'claim' => $claim, 'transaction' => $transaction,
            'coverages' => (array) ($values['coverage.lines'] ?? []), 'status' => $certificateLike ? 'VALID' : 'ISSUED', 'letterhead' => $letterhead,
        ]))->setPaper('a4')->output();

        $sha = hash('sha256', $bytes);
        $signature = $this->signer->sign([
            'document_number' => $number['number'], 'document_type_code' => $code, 'final_file_hash' => $sha, 'content_hash' => $contentHash,
            'snapshot_hash' => $snapshotHash, 'template_hash' => $template->content_hash, 'issued_at_utc' => $issuedAt->copy()->utc()->toIso8601String(),
            'verification_token_hash' => VerificationCredentials::tokenHash($token), 'security_tier' => $security['tier'],
        ]);
        $security['controls']['signature']['status'] = $signature['status'] === 'SIGNED' ? 'APPLIED' : $security['controls']['signature']['status'];

        $key = 'documents/'.$policy->tenant_id.'/'.($policy->id ?? 'provider-'.($ctx['provider_id'] ?? 'none')).'/'.$number['number'].'.pdf';
        Storage::disk((string) config('lifecycle.documents_disk', 'local'))->put($key, $bytes);

        $status = $profile && $profile->signature_mode === 'DIGITAL' ? 'PENDING_SIGNATURE' : ($certificateLike ? 'VALID' : 'ISSUED');

        $gate = \App\Application\Documents\Security\IssuanceGate::finalize($gate, [
            'number' => $number['number'], 'content_hash' => $contentHash, 'token_hash' => VerificationCredentials::tokenHash($token), 'pdf_bytes' => $bytes, 'sha256' => $sha,
            'stored_bytes' => Storage::disk((string) config('lifecycle.documents_disk', 'local'))->get($key), 'in_transaction' => DB::transactionLevel() > 0, 'status' => $status,
        ]);
        if ($gate['failed'] !== []) {
            Storage::disk((string) config('lifecycle.documents_disk', 'local'))->delete($key);
            throw new \RuntimeException('Security issuance gate failed after rendering: '.implode('; ', \App\Application\Documents\Security\IssuanceGate::refusals($gate, false)));
        }
        $security['controls']['issuance_gate'] = $gate;

        $doc = new Document([
            'tenant_id' => $policy->tenant_id, 'party_id' => $policy->party_id, 'policy_id' => $policy->id,
            'category' => 'ENGINE_'.$code, 'storage_key' => $key, 'mime_type' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => $sha,
            'scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'ocr_data' => [],
            'document_type_code' => $code, 'document_type_id' => $type['id'], 'document_group' => $type['group_code'], 'pack_code' => $manifest?->pack_code ?? ('PROVIDER_'.$trigger), 'pack_manifest_id' => $manifest?->id,
            'document_template_id' => $template->id, 'template_version' => $template->version, 'template_hash' => $template->content_hash,
            'product_id' => $product?->id, 'product_version' => $product?->version, 'policy_version' => (int) $policy->version,
            'policy_transaction_id' => $transaction?->id, 'renewal_of_policy_id' => $trigger === 'RENEWAL_ISSUED' ? $policy->previous_policy_id : null, 'claim_id' => $claim?->id,
            'subject_type' => $subject ? $subjectType : null, 'subject_key' => $subject['key'] ?? null, 'subject_label' => $subject['label'] ?? null,
            'title' => $template->title_en, 'issuer_type' => $issuer, 'issuer_carrier_id' => $policy->carrier_id, 'issuer_tenant_id' => $policy->tenant_id,
            'language' => $lang, 'document_origin' => match ($issuer) { 'INSURER' => 'INSURER', 'BROKER' => 'BROKER', default => 'SYSTEM' },
            'document_stage' => $this->stage($type, $trigger), 'security_level' => $type['security_level'], 'status' => $status,
            'numbering_family' => $number['family'], 'document_number' => $number['number'], 'document_sequence' => $number['sequence'],
            'verification_code' => $verification, 'generation_trigger' => $trigger, 'issued_at' => $issuedAt,
            'valid_from' => $ctx['valid_from'] ?? ($certificateLike ? $policy->coverage_starts_at : null), 'valid_until' => $ctx['valid_until'] ?? ($certificateLike ? $policy->coverage_ends_at : null),
            'provenance' => ['rendered_by' => 'OPESINSURE', 'on_behalf_of' => $issuer, 'authorization_reference' => $profile?->authorization_reference, 'event' => $label,
                'demo_watermark' => \App\Application\Documents\DemoDocumentMark::active(), 'environment' => app()->environment(), 'letterhead' => $letterhead['snapshot']],
            'uploaded_by' => $actor?->id,
        ]);
        // Canonical security record (columns of migration 2026_10_12_900001; frozen by the immutability trigger).
        $doc->forceFill([
            'security_tier' => $security['tier'], 'security_controls' => json_encode($security['controls']),
            'confidentiality_class' => $security['confidentiality_class'], 'access_profiles' => json_encode($security['access_profiles']),
            'master_shell_code' => $security['master_shell_code'], 'issuance_snapshot' => $this->json->encode($snapshot), 'snapshot_hash' => $snapshotHash,
            'provider_profile_id' => $ctx['provider_id'] ?? null,
            'content_hash_sha256' => $contentHash, 'verification_token_hash' => VerificationCredentials::tokenHash($token),
            'signature' => json_encode($signature), 'field_validation' => json_encode(['required' => $requirements['required'], 'missing' => $missing,
                'groups' => $requirements['groups'], 'no_canonical_source' => $requirements['no_source'], 'unmapped_pending_verification' => $requirements['unmapped'],
                'spec_id' => $requirements['spec_id'], 'enforcement' => config('document_security.field_enforcement', 'block')]),
        ])->save();

        $this->supersedePrevious($policy, $doc, $actor);
        $this->audit->record('document.generated', 'document', $doc->id, ['number' => $doc->document_number, 'type' => $code, 'template_version' => $template->version, 'trigger' => $trigger,
            'security_tier' => $security['tier'], 'content_hash' => $contentHash, 'snapshot_hash' => $snapshotHash, 'sha256' => $sha, 'signature' => $signature['status']]);

        return $doc;
    }

    /** New current document of a kind/subject → the previous current one becomes SUPERSEDED (linked both ways). */
    public function supersedePrevious(Policy $policy, Document $new, ?User $actor, string $as = 'SUPERSEDED'): void
    {
        $olds = Document::where('policy_id', $policy->id)->whereIn('document_type_code', self::kindCodes((string) $new->document_type_code))
            ->where(fn ($q) => $new->subject_key === null ? $q->whereNull('subject_key') : $q->where('subject_key', $new->subject_key))
            ->whereIn('status', DocumentRegister::CURRENT_STATUSES)->whereKeyNot($new->id)->lockForUpdate()->get();
        foreach ($olds as $old) {
            $old->update(['status' => $as, 'superseded_by_document_id' => $new->id, 'status_changed_at' => now(), 'status_changed_by' => $actor?->id,
                'status_reason' => ($as === 'REPLACED' ? 'Replaced by ' : 'Superseded by ').($new->document_number ?? $new->provenance['carrier_document_number'] ?? $new->id)]);
            $new->forceFill(['supersedes_document_id' => $old->id])->save();
            $this->audit->record('document.'.strtolower($as), 'document', $old->id, ['by_document_id' => $new->id]);
        }
    }

    public function closeCurrentCertificates(Policy $policy, string $status, string $reason, ?User $actor): void
    {
        Document::where('policy_id', $policy->id)->whereIn('status', DocumentRegister::CURRENT_STATUSES)->whereNotNull('document_type_code')->get()
            ->filter(fn (Document $d) => $this->register->describe((string) $d->document_type_code)['display_group'] === 'CERTIFICATES')
            ->each(function (Document $d) use ($status, $reason, $actor): void {
                $d->update(['status' => $status, 'status_reason' => $reason, 'status_changed_at' => now(), 'status_changed_by' => $actor?->id]);
                $this->audit->record('document.'.strtolower($status), 'document', $d->id, ['reason' => $reason]);
            });
    }

    private function currentOf(Policy $policy, string $code, ?string $subjectKey): ?Document
    {
        return Document::where('policy_id', $policy->id)->whereIn('document_type_code', self::kindCodes($code))
            ->where(fn ($q) => $subjectKey === null ? $q->whereNull('subject_key') : $q->where('subject_key', $subjectKey))
            ->whereIn('status', DocumentRegister::CURRENT_STATUSES)->latest('created_at')->first();
    }

    private function stage(array $type, string $trigger): string
    {
        return match (true) {
            $type['group_code'] === 'FINANCE' => 'FINANCE',
            $type['group_code'] === 'PRE_CONTRACT' => 'PRE_CONTRACT',
            ($type['family'] ?? null) === 'DOC-219' => 'SETTLEMENT',
            default => match ($trigger) {
                'POLICY_ISSUED' => 'POLICY',
                'RENEWAL_ISSUED' => 'RENEWAL',
                'ENDORSEMENT_ISSUED', 'CANCELLATION_ISSUED', 'REINSTATEMENT_ISSUED' => 'SERVICING',
                'PAYMENT_RECONCILED' => 'FINANCE',
                'QUOTE_GENERATED' => 'PRE_CONTRACT',
                default => 'CLAIM',
            },
        };
    }

    /** @return array{0: string, 1: int} */
    private function label(string $trigger, Policy $policy, array $ctx): array
    {
        if ($trigger === 'ENDORSEMENT_ISSUED') {
            $n = DocumentPackManifest::where('policy_id', $policy->id)->where('trigger', 'ENDORSEMENT_ISSUED')->count() + 1;

            return ['AVENANT '.str_pad((string) $n, 3, '0', STR_PAD_LEFT).' / ENDORSEMENT '.str_pad((string) $n, 3, '0', STR_PAD_LEFT), $n];
        }

        return match ($trigger) {
            'POLICY_ISSUED' => ['ORIGINAL', 0],
            'RENEWAL_ISSUED' => ['RENEWAL / RENOUVELLEMENT'.($policy->previous_policy_id ? ' '.(Policy::whereKey($policy->previous_policy_id)->value('policy_number') ?? '') : ''), 0],
            'CANCELLATION_ISSUED' => ['CANCELLATION / RÉSILIATION '.($ctx['transaction']->transaction_number ?? ''), 0],
            'REINSTATEMENT_ISSUED' => ['REINSTATEMENT / REMISE EN VIGUEUR '.($ctx['transaction']->transaction_number ?? ''), 0],
            'PAYMENT_RECONCILED' => ['PAYMENT '.($ctx['payment']->provider_reference ?? ''), 0],
            'PREAUTH_APPROVED', 'PREAUTH_PARTIALLY_APPROVED', 'PREAUTH_DECLINED', 'PREAUTH_EXTENSION_APPROVED' => ['PREAUTHORIZATION / PRISE EN CHARGE '.($ctx['preauth']->preauth_number ?? ''), 0],
            default => [isset($ctx['claim']) ? 'CLAIM '.$ctx['claim']->claim_number : $trigger, 0],
        };
    }

    /** @return array{0: string, 1: array<string, mixed>} event reference + context additions */
    private function assertState(string $trigger, Policy $policy, array $ctx): array
    {
        $fail = fn (string $why) => throw ValidationException::withMessages(['trigger' => "Document trigger {$trigger} refused: {$why}."]);
        $inForce = ['ACTIVE', 'EXPIRING', 'ENDORSEMENT_PENDING', 'CANCELLATION_PENDING'];

        switch ($trigger) {
            case 'POLICY_ISSUED':
            case 'RENEWAL_ISSUED':
                if (! $policy->policy_number || ! in_array($policy->status, $inForce, true)) {
                    $fail('policy is not issued and in force');
                }
                if ($trigger === 'RENEWAL_ISSUED' && ! $policy->previous_policy_id) {
                    $fail('policy is not a renewal');
                }

                return [$trigger === 'POLICY_ISSUED' ? 'ISSUANCE' : 'RENEWAL', []];
            case 'ENDORSEMENT_ISSUED':
            case 'CANCELLATION_ISSUED':
            case 'REINSTATEMENT_ISSUED':
                $t = $ctx['transaction'] ?? null;
                $expected = ['ENDORSEMENT_ISSUED' => 'ENDORSEMENT', 'CANCELLATION_ISSUED' => 'CANCELLATION', 'REINSTATEMENT_ISSUED' => 'REINSTATEMENT'][$trigger];
                if (! $t instanceof PolicyTransaction || $t->policy_id !== $policy->id || $t->status !== 'APPROVED' || $t->type !== $expected) {
                    $fail('an APPROVED '.$expected.' transaction of this policy is required');
                }

                return [$t->id, []];
            case 'CLAIM_REGISTERED':
            case 'CLAIM_APPROVED':
            case 'CLAIM_PARTIALLY_APPROVED':
            case 'CLAIM_DECLINED':
                $c = $ctx['claim'] ?? null;
                $need = ['CLAIM_APPROVED' => ['APPROVED'], 'CLAIM_PARTIALLY_APPROVED' => ['PARTIALLY_APPROVED'], 'CLAIM_DECLINED' => ['DECLINED']][$trigger] ?? null;
                if (! $c instanceof Claim || $c->policy_id !== $policy->id || ($need && ! in_array($c->status, $need, true)) || (! $need && in_array($c->status, ['DRAFT'], true))) {
                    $fail('the claim is not in the required state');
                }

                return [$c->id, []];
            case 'PREAUTH_APPROVED':
            case 'PREAUTH_PARTIALLY_APPROVED':
            case 'PREAUTH_DECLINED':
            case 'PREAUTH_EXTENSION_APPROVED':
                // REQ-HLT-002: the preauthorization (and extension) must belong to the policy and carry the decided state.
                $pa = $ctx['preauth'] ?? null;
                $need = ['PREAUTH_APPROVED' => ['APPROVED'], 'PREAUTH_PARTIALLY_APPROVED' => ['PARTIALLY_APPROVED'], 'PREAUTH_DECLINED' => ['DECLINED'],
                    'PREAUTH_EXTENSION_APPROVED' => ['ADMITTED']][$trigger];
                if (! is_object($pa) || ($pa->policy_id ?? null) !== $policy->id || ! in_array($pa->status ?? null, $need, true)) {
                    $fail('the preauthorization is not in the required state');
                }
                $ext = $ctx['extension'] ?? null;
                if ($trigger === 'PREAUTH_EXTENSION_APPROVED' && (! is_object($ext) || ! in_array($ext->status ?? null, ['APPROVED', 'PARTIALLY_APPROVED'], true))) {
                    $fail('an approved stay extension is required');
                }

                return [$trigger === 'PREAUTH_EXTENSION_APPROVED' ? $ext->id : $pa->id, []];
            case 'PAYMENT_RECONCILED':
                $p = $ctx['payment'] ?? null;
                if (! $p instanceof PaymentIntentRecord || $p->status !== 'SUCCEEDED' || $policy->payment_intent_id !== $p->id) {
                    $fail('a succeeded payment of this policy is required');
                }

                return [$p->id, []];
            default:
                $fail('unsupported trigger');
        }

        return ['', []];
    }
}
