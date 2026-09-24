<?php

declare(strict_types=1);

namespace App\Application\DocumentCatalogue;

use App\Models\DocumentCatalogue\DocumentProductType;
use App\Models\DocumentCatalogue\ProductDocumentRequirement;
use App\Models\InsuranceProduct;
use Illuminate\Validation\ValidationException;

/**
 * Product publication document gate (Document Requirement Matrix v1.0):
 *  - blocking: a selected product type that is unknown, inactive or a PLACEHOLDER;
 *    pending (unapproved) document requirement changes; an active override that
 *    downgrades a mandatory, non-overridable matrix document.
 *  - warning (non-blocking, legacy products): no document product type selected.
 */
final class DocumentPublicationGate
{
    public function __construct(private readonly DocumentRequirementResolver $resolver) {}

    /** @return array{blocking:list<string>,warnings:list<string>,product_type:?string} */
    public function check(InsuranceProduct $product): array
    {
        $blocking = [];
        $warnings = [];
        $code = $this->resolver->productTypeFor($product);
        if ($code === null) {
            $warnings[] = 'No document product type selected; documents fall back to the class packs.';
        } else {
            $type = DocumentProductType::where('code', $code)->first();
            if (! $type || $type->status !== 'ACTIVE') {
                $blocking[] = "Document product type {$code} is ".($type ? strtolower($type->status) : 'unknown').' and cannot be used for publication.';
            } else {
                $platform = $this->resolver->resolve($code)->keyBy(fn ($e) => $e['stage'].'|'.$e['document_type_id'].'|'.$e['variant_code']);
                ProductDocumentRequirement::where('insurance_product_id', $product->id)->where('kind', 'MATRIX_OVERRIDE')->where('status', 'ACTIVE')->get()
                    ->each(function ($o) use ($platform, &$blocking) {
                        $e = $platform[$o->stage.'|'.$o->document_type_id.'|'.($o->variant_code ?: null)] ?? null;
                        if ($e && $e['level'] === 'M' && ! $e['insurer_overridable'] && $o->level !== 'M') {
                            $blocking[] = "Override downgrades mandatory document {$e['matrix_label']} ({$o->document_type_id}).";
                        }
                    });
            }
        }
        if (ProductDocumentRequirement::where('insurance_product_id', $product->id)->where('status', 'PENDING_APPROVAL')->exists()) {
            $blocking[] = 'Document requirement changes are pending approval.';
        }

        return ['blocking' => $blocking, 'warnings' => $warnings, 'product_type' => $code];
    }

    public function assertPublishable(InsuranceProduct $product): void
    {
        $r = $this->check($product);
        if ($r['blocking'] !== []) {
            throw ValidationException::withMessages(['documents' => implode(' ', $r['blocking'])]);
        }
    }
}
