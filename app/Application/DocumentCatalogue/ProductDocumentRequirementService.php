<?php

declare(strict_types=1);

namespace App\Application\DocumentCatalogue;

use App\Application\Audit\AuditWriter;
use App\Models\DocumentCatalogue\DocumentPack;
use App\Models\DocumentCatalogue\DocumentProductType;
use App\Models\DocumentCatalogue\DocumentRequirementMatrixEntry;
use App\Models\DocumentCatalogue\DocumentType;
use App\Models\DocumentCatalogue\ProductDocumentRequirement;
use App\Models\InsuranceProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Insurer adaptations of the requirement matrix, per product version, with
 * maker-checker: propose → (different user) approve | reject; active rows are
 * retired, never deleted. Platform rows are never changed. Kinds:
 *  - PRODUCT_TYPE: selects the matrix product type (MOTOR_TPL, HEALTH_GROUP…)
 *  - MATRIX_OVERRIDE: insurer level for a (stage, document, variant)
 * Pack contents come from document_packs / document_pack_items (the same
 * catalogue); the engine reads both through CatalogueSource (REQ-DUP-004).
 */
final class ProductDocumentRequirementService
{
    public const LEVELS = ['M', 'C', 'O', 'I', 'T'];

    public const REQUIREMENTS = ['REQUIRED', 'CONDITIONAL', 'PRODUCT_DEPENDENT', 'WHERE_APPLICABLE', 'OPTIONAL', 'INTERNAL', 'THIRD_PARTY'];

    /**
     * Workflow Data Master v1 document_requirement_rules.lifecycle_stages => catalogue lifecycle_stage codes (document_packs).
     * [] = no catalogue stage yet (CONFIG_REQUIRED; nothing invented).
     */
    public const LIFECYCLE_STAGE_MAP = [
        'NEW_BUSINESS' => ['NEW_BUSINESS'], 'RENEWAL' => ['RENEWAL'], 'ENDORSEMENT' => ['ENDORSEMENT'], 'CANCELLATION' => [],
        'CLAIM' => ['CLAIM', 'TREATMENT'], 'FINANCE' => ['FINANCIAL'], 'REINSURANCE' => [],
    ];

    public function __construct(private readonly AuditWriter $audit, private readonly DocumentRequirementResolver $resolver) {}

    /** @param array<string,mixed> $data */
    public function propose(InsuranceProduct $product, array $data, User $maker): ProductDocumentRequirement
    {
        $kind = strtoupper((string) ($data['kind'] ?? ''));
        if (! in_array($kind, ProductDocumentRequirement::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown requirement kind.']);
        }
        $row = ['insurance_product_id' => $product->id, 'kind' => $kind, 'status' => 'PENDING_APPROVAL', 'created_by' => $maker->id,
            'reason' => $data['reason'] ?? null, 'condition_note' => $data['condition_note'] ?? null, 'variant_code' => $data['variant_code'] ?? ''];

        if ($kind === 'PRODUCT_TYPE') {
            $type = DocumentProductType::where('code', strtoupper((string) ($data['product_type_code'] ?? '')))->first();
            if (! $type) {
                throw ValidationException::withMessages(['product_type_code' => 'Unknown document product type.']);
            }
            $row['product_type_code'] = $type->code;
        } else { // MATRIX_OVERRIDE
            $row['document_type_id'] = $this->type($data)->type_id;
            $row['stage'] = strtoupper((string) ($data['stage'] ?? ''));
            $row['level'] = strtoupper((string) ($data['level'] ?? ''));
            if (! in_array($row['level'], self::LEVELS, true)) {
                throw ValidationException::withMessages(['level' => 'Level must be one of M, C, O, I, T.']);
            }
            $this->assertOverrideAllowed($product, $row);
        }

        return DB::transaction(function () use ($row, $product) {
            $r = ProductDocumentRequirement::create($row);
            $this->audit->record('document_catalogue.product_requirement.proposed', 'product_document_requirement', $r->id, ['product_id' => $product->id, 'kind' => $r->kind]);

            return $r;
        });
    }

    public function approve(ProductDocumentRequirement $r, User $checker): ProductDocumentRequirement
    {
        if ($r->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['status' => 'Only pending requirements can be approved.']);
        }
        if ($r->created_by === $checker->id) {
            throw ValidationException::withMessages(['actor' => 'Maker-checker: a different user must approve.']);
        }

        return DB::transaction(function () use ($r, $checker) {
            // One active selection per product / key: supersede the previous one.
            ProductDocumentRequirement::where('insurance_product_id', $r->insurance_product_id)->where('kind', $r->kind)->where('status', 'ACTIVE')
                ->where('id', '!=', $r->id)
                ->when($r->kind === 'MATRIX_OVERRIDE', fn ($q) => $q->where(['stage' => $r->stage, 'document_type_id' => $r->document_type_id, 'variant_code' => $r->variant_code]))
                ->update(['status' => 'RETIRED', 'retired_by' => $checker->id, 'retired_at' => now()]);
            $r->update(['status' => 'ACTIVE', 'approved_by' => $checker->id, 'approved_at' => now()]);
            $this->audit->record('document_catalogue.product_requirement.approved', 'product_document_requirement', $r->id, ['kind' => $r->kind]);

            return $r->refresh();
        });
    }

    public function reject(ProductDocumentRequirement $r, User $checker, string $reason): ProductDocumentRequirement
    {
        if ($r->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['status' => 'Only pending requirements can be rejected.']);
        }
        $r->update(['status' => 'REJECTED', 'approved_by' => $checker->id, 'approved_at' => now(), 'reason' => $reason]);
        $this->audit->record('document_catalogue.product_requirement.rejected', 'product_document_requirement', $r->id, [], $reason);

        return $r;
    }

    public function retire(ProductDocumentRequirement $r, User $actor, string $reason): ProductDocumentRequirement
    {
        if ($r->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['status' => 'Only active requirements can be retired.']);
        }
        $r->update(['status' => 'RETIRED', 'retired_by' => $actor->id, 'retired_at' => now(), 'reason' => $reason]);
        $this->audit->record('document_catalogue.product_requirement.retired', 'product_document_requirement', $r->id, [], $reason);

        return $r;
    }

    /**
     * A mandatory matrix document that is not insurer-overridable can never be
     * downgraded by a product override.
     *
     * @param  array<string,mixed>  $row
     */
    public function assertOverrideAllowed(InsuranceProduct $product, array $row): void
    {
        $productType = $this->resolver->productTypeFor($product);
        if (! $productType) {
            throw ValidationException::withMessages(['product_type_code' => 'Select the product\'s document product type before overriding matrix levels.']);
        }
        $current = $this->resolver->resolve($productType)
            ->first(fn ($e) => $e['stage'] === $row['stage'] && $e['document_type_id'] === $row['document_type_id'] && (string) $e['variant_code'] === (string) $row['variant_code']);
        if ($current && $current['level'] === 'M' && ! $current['insurer_overridable'] && $row['level'] !== 'M') {
            throw ValidationException::withMessages(['level' => "{$current['matrix_label']} is mandatory for {$productType} and cannot be downgraded by an insurer."]);
        }
    }

    /** @param array<string,mixed> $data */
    private function type(array $data): DocumentType
    {
        $t = app(DocumentCatalogueService::class)->find((string) ($data['document_type_id'] ?? $data['document_code'] ?? ''));
        if (! $t) {
            throw ValidationException::withMessages(['document_type_id' => 'Unknown document type.']);
        }

        return $t;
    }

    /** Levels allowed for MATRIX_OVERRIDE entries in matrix natural-key form. */
    public static function stages(): array
    {
        return ['PRE_CONTRACT', 'ISSUANCE', 'SERVICING', 'RENEWAL', 'TREATMENT', 'MOVEMENT', 'LIFECYCLE', 'CLAIM'];
    }

    public static function baselineCode(): string
    {
        return DocumentRequirementMatrixEntry::BASELINE;
    }
}
