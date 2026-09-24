<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Application\Audit\AuditWriter;
use App\Application\Shared\CanonicalJson;
use App\Models\DocumentTemplate;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Template lifecycle DRAFT → REVIEW → APPROVED → PUBLISHED → RETIRED with the
 * platform's maker-checker rule (the creator can neither approve nor publish;
 * content hash re-checked at every step, as CertificateService does for
 * certificate templates). Content is editable only in DRAFT: a published
 * template is never edited — a change is a new version of the same lineage
 * code, and publishing it retires the previous version. Issued documents
 * keep template_id + template_version + template_hash and their own stored
 * bytes, so later template changes never alter them.
 *
 * Resolution order for a document: INSURER (carrier contractual) > BROKER >
 * REGULATORY > PLATFORM; inside one ownership a product-specific template
 * beats a generic one, and the requested language beats BILINGUAL beats any
 * other language. Life policies only accept LIFE-scoped templates (CIMA:
 * life documents need life-specific templates).
 */
final class DocumentTemplateService
{
    public const OWNERSHIPS = ['INSURER', 'BROKER', 'REGULATORY', 'PLATFORM'];

    public const LANGUAGES = ['FR', 'EN', 'BILINGUAL'];

    public function __construct(private CanonicalJson $json, private AuditWriter $audit, private DocumentRegister $register) {}

    /** @param array<string, mixed> $d */
    public function createDraft(array $d, User $actor): DocumentTemplate
    {
        $this->validateScope($d);

        return DB::transaction(function () use ($d, $actor): DocumentTemplate {
            $code = self::lineage($d);
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['document-template:'.$code]);
            $version = ((int) DocumentTemplate::where('code', $code)->max('version')) + 1;
            $type = $this->register->describe($d['document_type_code']);
            $content = $d['content'] ?? ['sections' => []];
            $template = DocumentTemplate::create([
                'code' => $code, 'document_type_code' => $d['document_type_code'], 'ownership' => $d['ownership'],
                'carrier_id' => $d['carrier_id'] ?? null, 'broker_tenant_id' => $d['broker_tenant_id'] ?? null, 'product_id' => $d['product_id'] ?? null,
                'insurance_class' => $d['insurance_class'] ?? null, 'language' => $d['language'], 'version' => $version, 'status' => 'DRAFT',
                'title_en' => $d['title_en'] ?? $type['name_en'], 'title_fr' => $d['title_fr'] ?? $type['name_fr'],
                'content' => $content, 'content_hash' => '', 'effective_from' => $d['effective_from'] ?? now()->toDateString(),
                'effective_until' => $d['effective_until'] ?? null, 'created_by' => $actor->id,
            ]);
            $template->update(['content_hash' => $this->hash($template)]);
            $this->audit->record('document.template.created', 'document_template', $template->id, ['code' => $code, 'version' => $version]);

            return $template->refresh();
        });
    }

    /** @param array<string, mixed> $d content / titles / effective dates only */
    public function updateDraft(DocumentTemplate $t, array $d, User $actor): DocumentTemplate
    {
        return DB::transaction(function () use ($t, $d, $actor): DocumentTemplate {
            $t = DocumentTemplate::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Only a DRAFT template can be edited; create a new version instead.']);
            }
            $t->fill(array_intersect_key($d, array_flip(['content', 'title_en', 'title_fr', 'effective_from', 'effective_until'])));
            $t->content_hash = $this->hash($t);
            $t->save();
            $this->audit->record('document.template.edited', 'document_template', $t->id, ['version' => $t->version, 'by' => $actor->id]);

            return $t->refresh();
        });
    }

    public function submit(DocumentTemplate $t, User $actor): DocumentTemplate
    {
        return $this->move($t, 'DRAFT', 'REVIEW', $actor, false, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
    }

    public function approve(DocumentTemplate $t, User $actor): DocumentTemplate
    {
        return $this->move($t, 'REVIEW', 'APPROVED', $actor, true, ['approved_by' => $actor->id, 'approved_at' => now()]);
    }

    public function publish(DocumentTemplate $t, User $actor, ?string $effectiveFrom = null): DocumentTemplate
    {
        return DB::transaction(function () use ($t, $actor, $effectiveFrom): DocumentTemplate {
            $published = $this->move($t, 'APPROVED', 'PUBLISHED', $actor, true, ['published_by' => $actor->id, 'published_at' => now()] + ($effectiveFrom ? ['effective_from' => $effectiveFrom] : []));
            DocumentTemplate::where('code', $published->code)->where('status', 'PUBLISHED')->whereKeyNot($published->id)->lockForUpdate()->get()
                ->each(function (DocumentTemplate $old) use ($published, $actor): void {
                    $until = $published->effective_from->copy()->subDay();
                    $old->update(['status' => 'RETIRED', 'retired_at' => now(), 'retire_reason' => 'Superseded by version '.$published->version,
                        'effective_until' => $old->effective_from->greaterThan($until) ? $old->effective_from : $until]);
                    $this->audit->record('document.template.retired', 'document_template', $old->id, ['superseded_by' => $published->id, 'by' => $actor->id]);
                });

            return $published;
        });
    }

    public function retire(DocumentTemplate $t, string $reason, User $actor): DocumentTemplate
    {
        return DB::transaction(function () use ($t, $reason, $actor): DocumentTemplate {
            $t = DocumentTemplate::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if (! in_array($t->status, ['PUBLISHED', 'APPROVED', 'REVIEW', 'DRAFT'], true)) {
                throw ValidationException::withMessages(['status' => 'Template already retired.']);
            }
            $t->update(['status' => 'RETIRED', 'retired_at' => now(), 'retire_reason' => $reason]);
            $this->audit->record('document.template.retired', 'document_template', $t->id, ['reason' => $reason, 'by' => $actor->id]);

            return $t->refresh();
        });
    }

    /**
     * @param array{carrier_id: ?string, tenant_id: ?string, broker: bool, product_id: ?string, insurance_class: ?string} $ctx
     */
    public function resolve(string $documentTypeCode, array $ctx, string $language = 'BILINGUAL', ?CarbonInterface $at = null): ?DocumentTemplate
    {
        return $this->candidates($documentTypeCode, $ctx, $at)
            ->sortBy(fn (DocumentTemplate $t) => sprintf('%d-%d-%d-%010d',
                array_search($t->ownership, self::OWNERSHIPS, true),
                $t->product_id ? 0 : 1,
                $t->language === $language ? 0 : ($t->language === 'BILINGUAL' ? 1 : 2),
                9999999999 - $t->version))
            ->first();
    }

    /** @return Collection<int, DocumentTemplate> */
    public function candidates(string $documentTypeCode, array $ctx, ?CarbonInterface $at = null): Collection
    {
        $date = ($at ?? now())->toDateString();
        $isLife = ($ctx['insurance_class'] ?? null) === 'LIFE';

        return DocumentTemplate::where('document_type_code', $documentTypeCode)->where('status', 'PUBLISHED')
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $ctx['product_id'] ?? null))
            ->where(fn ($q) => $isLife ? $q->where('insurance_class', 'LIFE') : $q->whereNull('insurance_class')->orWhere('insurance_class', $ctx['insurance_class'] ?? null))
            ->where(function ($q) use ($ctx): void {
                $q->where(fn ($i) => $i->where('ownership', 'INSURER')->where('carrier_id', $ctx['carrier_id'] ?? null))
                    ->orWhere(fn ($b) => $b->where('ownership', 'BROKER')->where('broker_tenant_id', ($ctx['broker'] ?? false) ? ($ctx['tenant_id'] ?? null) : null))
                    ->orWhere('ownership', 'REGULATORY')
                    ->orWhere('ownership', 'PLATFORM');
            })->get();
    }

    /** @param array<string, mixed> $d */
    public static function lineage(array $d): string
    {
        return implode('|', [$d['document_type_code'], $d['ownership'], $d['carrier_id'] ?? '-', $d['broker_tenant_id'] ?? '-', $d['product_id'] ?? '-', $d['insurance_class'] ?? '-', $d['language']]);
    }

    public function hash(DocumentTemplate $t): string
    {
        return $this->json->hash(['title_en' => $t->title_en, 'title_fr' => $t->title_fr, 'content' => $t->content, 'language' => $t->language, 'document_type_code' => $t->document_type_code]);
    }

    private function move(DocumentTemplate $t, string $from, string $to, User $actor, bool $checker, array $extra): DocumentTemplate
    {
        return DB::transaction(function () use ($t, $from, $to, $actor, $checker, $extra): DocumentTemplate {
            $t = DocumentTemplate::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status !== $from) {
                throw ValidationException::withMessages(['status' => "Template must be {$from} to move to {$to}."]);
            }
            if ($checker && $t->created_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
            }
            if (! hash_equals($t->content_hash, $this->hash($t))) {
                throw ValidationException::withMessages(['content' => __('wave5.template_changed')]);
            }
            $t->update(['status' => $to] + $extra);
            $this->audit->record('document.template.'.strtolower($to), 'document_template', $t->id, ['version' => $t->version, 'code' => $t->code]);

            return $t->refresh();
        });
    }

    /** @param array<string, mixed> $d */
    private function validateScope(array $d): void
    {
        $errors = [];
        if (! in_array($d['ownership'] ?? null, self::OWNERSHIPS, true)) {
            $errors['ownership'] = 'Ownership must be PLATFORM, INSURER, BROKER or REGULATORY.';
        }
        if (! in_array($d['language'] ?? null, self::LANGUAGES, true)) {
            $errors['language'] = 'Language must be FR, EN or BILINGUAL.';
        }
        if (($d['ownership'] ?? null) === 'INSURER' && empty($d['carrier_id'])) {
            $errors['carrier_id'] = 'An insurer template needs its insurer.';
        }
        if (($d['ownership'] ?? null) === 'BROKER' && empty($d['broker_tenant_id'])) {
            $errors['broker_tenant_id'] = 'A broker template needs its broker.';
        }
        if (empty($d['document_type_code']) || $this->register->type((string) $d['document_type_code']) === null) {
            $errors['document_type_code'] = 'Unknown document type (not in the Canonical Document Register).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
