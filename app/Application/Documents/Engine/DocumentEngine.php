<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Application\Audit\AuditWriter;
use App\Application\Notifications\CustomerNotifier;
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
    ) {}

    /**
     * @param array{transaction?: PolicyTransaction, claim?: Claim, payment?: PaymentIntentRecord, cover_note?: bool} $ctx
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
                $subjects = $item['per_subject'] ? $this->packs->subjects($policy, $item['per_subject']) : [];
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
            $origin === 'SYSTEM' || $code === 'POLICY_ISSUE_CONFIRMATION' => 'PLATFORM',
            $broker && ($origin === 'BROKER' || ($origin === null && $type['group_code'] === 'PRE_CONTRACT')) => 'BROKER',
            default => 'INSURER',
        };
    }

    public static function newVerificationCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
        do {
            $code = 'OV';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Document::where('verification_code', $code)->exists());

        return $code;
    }

    /** @return array<string, mixed> manifest item */
    private function produce(Policy $policy, DocumentPackManifest $manifest, array $pack, array $item, ?array $subject, string $trigger, array $ctx, string $label, ?User $actor): array
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

        $doc = $this->generate($policy, $manifest, $template, $type, $issuer, $profile, $subject, $item['per_subject'], $trigger, $ctx, $label, $actor);

        return ['state' => 'GENERATED', 'document_id' => $doc->id, 'template_id' => $template->id, 'template_version' => $template->version] + $base;
    }

    private function generate(Policy $policy, DocumentPackManifest $manifest, DocumentTemplate $template, array $type, string $issuer, ?DocumentIssuanceProfile $profile, ?array $subject, ?string $subjectType, string $trigger, array $ctx, string $label, ?User $actor): Document
    {
        $code = $type['code'];
        $number = $this->numbers->allocate($policy->tenant_id, $code);
        $verification = self::newVerificationCode();
        $verifyUrl = rtrim((string) config('lifecycle.verify_url'), '/').'?code='.$verification;
        $certificateLike = $type['display_group'] === 'CERTIFICATES';
        $product = $policy->proposal?->offer?->product;
        $lang = $template->language;
        $carrierName = $policy->carrier?->party?->display_name ?? 'Insurer';
        $broker = $policy->tenant?->type === 'BROKER' ? ['name' => $policy->tenant->legal_name, 'licence' => $policy->tenant->getAttributes()['licence_number'] ?? null] : null;

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

        $qr = null;
        if (! $profile || $profile->qr_enabled) {
            $qr = (new QRCode(new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true])))->render($verifyUrl);
        }
        $issuedAt = now();
        $bytes = Pdf::loadView('pdf.engine-document', [
            'lang' => $lang, 'titleEn' => $template->title_en, 'titleFr' => $template->title_fr, 'documentNumber' => $number['number'],
            'issuerName' => $issuer === 'BROKER' && $broker ? $broker['name'] : ($issuer === 'PLATFORM' ? 'OpesInsure' : $carrierName),
            'intermediary' => $broker, 'carrierName' => $carrierName, 'policyNumber' => $policy->policy_number, 'policyVersion' => (int) $policy->version,
            'insuredName' => $policy->party?->display_name ?? '', 'productName' => $product?->name, 'subjectLabel' => $subject['label'] ?? null,
            'validFrom' => $policy->coverage_starts_at?->format('d/m/Y'), 'validUntil' => $policy->coverage_ends_at?->format('d/m/Y'),
            'eventLabel' => $label, 'issuedAt' => $issuedAt->format('d/m/Y H:i'), 'verificationCode' => $verification, 'qr' => $qr, 'verifyUrl' => $verifyUrl,
            'sections' => $sections, 'coverages' => $template->content['show_coverages'] ?? false ? ($policy->terms_snapshot['coverage_snapshot']['coverages'] ?? []) : [],
            'signatory' => $profile && $profile->signature_mode !== 'NONE' && $profile->signatory_name ? ['name' => $profile->signatory_name, 'title' => (string) $profile->signatory_title] : null,
            'templateRef' => $template->ownership.' template v'.$template->version,
        ])->setPaper('a4')->output();

        $key = 'documents/'.$policy->tenant_id.'/'.$policy->id.'/'.$number['number'].'.pdf';
        Storage::disk((string) config('lifecycle.documents_disk', 'local'))->put($key, $bytes);

        $status = $profile && $profile->signature_mode === 'DIGITAL' ? 'PENDING_SIGNATURE' : ($certificateLike ? 'VALID' : 'ISSUED');
        $transaction = $ctx['transaction'] ?? null;
        $claim = $ctx['claim'] ?? null;

        $doc = Document::create([
            'tenant_id' => $policy->tenant_id, 'party_id' => $policy->party_id, 'policy_id' => $policy->id,
            'category' => 'ENGINE_'.$code, 'storage_key' => $key, 'mime_type' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'ocr_data' => [],
            'document_type_code' => $code, 'document_type_id' => $type['id'], 'document_group' => $type['group_code'], 'pack_code' => $manifest->pack_code, 'pack_manifest_id' => $manifest->id,
            'document_template_id' => $template->id, 'template_version' => $template->version, 'template_hash' => $template->content_hash,
            'product_id' => $product?->id, 'product_version' => $product?->version, 'policy_version' => (int) $policy->version,
            'policy_transaction_id' => $transaction?->id, 'renewal_of_policy_id' => $trigger === 'RENEWAL_ISSUED' ? $policy->previous_policy_id : null, 'claim_id' => $claim?->id,
            'subject_type' => $subject ? $subjectType : null, 'subject_key' => $subject['key'] ?? null, 'subject_label' => $subject['label'] ?? null,
            'title' => $template->title_en, 'issuer_type' => $issuer, 'issuer_carrier_id' => $policy->carrier_id, 'issuer_tenant_id' => $policy->tenant_id,
            'language' => $lang, 'document_origin' => match ($issuer) { 'INSURER' => 'INSURER', 'BROKER' => 'BROKER', default => 'SYSTEM' },
            'document_stage' => $this->stage($type, $trigger), 'security_level' => $type['security_level'], 'status' => $status,
            'numbering_family' => $number['family'], 'document_number' => $number['number'], 'document_sequence' => $number['sequence'],
            'verification_code' => $verification, 'generation_trigger' => $trigger, 'issued_at' => $issuedAt,
            'valid_from' => $certificateLike ? $policy->coverage_starts_at : null, 'valid_until' => $certificateLike ? $policy->coverage_ends_at : null,
            'provenance' => ['rendered_by' => 'OPESINSURE', 'on_behalf_of' => $issuer, 'authorization_reference' => $profile?->authorization_reference, 'event' => $label],
            'uploaded_by' => $actor?->id,
        ]);

        $this->supersedePrevious($policy, $doc, $actor);
        $this->audit->record('document.generated', 'document', $doc->id, ['number' => $doc->document_number, 'type' => $code, 'template_version' => $template->version, 'trigger' => $trigger]);

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
