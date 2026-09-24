<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\DocumentCatalogue;

use App\Application\DocumentCatalogue\DocumentCatalogueService;
use App\Application\DocumentCatalogue\DocumentRequirementResolver;
use App\Models\DocumentCatalogue\DocumentPack;
use App\Models\DocumentCatalogue\DocumentPackItem;
use App\Models\DocumentCatalogue\DocumentProductType;
use App\Models\DocumentCatalogue\DocumentType;
use App\Models\InsuranceProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DocumentCatalogueController
{
    public function __construct(private readonly DocumentCatalogueService $catalogue) {}

    /** GET /api/v1/public/document-types?group=&stage=&class=&kind=&origin=&evidence= */
    public function types(Request $request): JsonResponse
    {
        $f = $request->only(['group', 'stage', 'class', 'kind', 'origin']);
        if ($request->has('evidence')) {
            $f['evidence'] = $request->boolean('evidence');
        }

        return response()->json(['data' => $this->catalogue->types($f)->map(fn (DocumentType $t) => self::present($t))->values()]);
    }

    /** GET /api/v1/public/document-types/{DOC-041 | MOTOR_INSURANCE_ATTESTATION | alias} */
    public function type(string $idOrCode): JsonResponse
    {
        $t = $this->catalogue->find($idOrCode);
        abort_if($t === null, 404);

        return response()->json(['data' => self::present($t) + ['subtypes' => $t->subtypes()->orderBy('type_id')->get()->map(fn ($s) => self::present($s))->values()]]);
    }

    /** GET /api/v1/public/document-packs?class=&stage= */
    public function packs(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->catalogue->packs($request->only(['class', 'stage']))->map(fn (DocumentPack $p) => [
            'code' => $p->code, 'label' => ['en' => $p->label_en, 'fr' => $p->label_fr], 'lifecycle_stage' => $p->lifecycle_stage, 'scope' => $p->scope,
            'is_universal' => $p->is_universal, 'class_codes' => $p->class_codes, 'catalogue_version' => $p->catalogue_version,
            'items' => $p->items->map(fn (DocumentPackItem $i) => [
                'document_type_id' => $i->document_type_id, 'canonical_code' => $i->documentType?->canonical_code,
                'label' => ['en' => $i->documentType?->name_en, 'fr' => $i->documentType?->name_fr],
                'requirement' => $i->requirement, 'condition_note' => $i->condition_note, 'sort_order' => $i->sort_order,
                'document_origin' => $i->documentType?->document_origin, 'scope' => $i->documentType?->scope, 'verifiable' => $i->documentType?->verifiable,
            ])->values(),
        ])->values()]);
    }

    /** GET /api/v1/public/document-requirements?product_type=MOTOR_TPL[&product_id=] — without product_type: list of product types. */
    public function requirements(Request $request, DocumentRequirementResolver $resolver): JsonResponse
    {
        $code = $request->query('product_type');
        $product = $request->query('product_id') ? InsuranceProduct::where('status', 'ACTIVE')->find($request->query('product_id')) : null;
        if (! $code && $product) {
            $code = $resolver->productTypeFor($product);
        }
        if (! $code) {
            return response()->json(['data' => DocumentProductType::query()->orderBy('spec_section')->get()->map(fn ($t) => [
                'code' => $t->code, 'label' => ['en' => $t->label_en, 'fr' => $t->label_fr], 'class_code' => $t->class_code, 'extends' => $t->extends_code, 'status' => $t->status,
            ])->values()]);
        }
        $type = DocumentProductType::where('code', strtoupper((string) $code))->first();
        abort_if($type === null, 404);
        $rows = $resolver->resolve($type->code, $product)->map(fn ($r) => array_merge($r, ['label' => ['en' => $r['label_en'], 'fr' => $r['label_fr']]]));

        return response()->json(['data' => [
            'product_type' => $type->code, 'label' => ['en' => $type->label_en, 'fr' => $type->label_fr], 'status' => $type->status,
            'chain' => $resolver->chain($type->code), 'requirements' => $rows->values(),
        ]]);
    }

    public static function present(DocumentType $t): array
    {
        return [
            'type_id' => $t->type_id, 'canonical_code' => $t->canonical_code, 'namespace' => $t->namespace, 'kind' => $t->kind, 'parent_type_id' => $t->parent_type_id,
            'label' => ['en' => $t->name_en, 'fr' => $t->name_fr], 'register_group' => $t->register_group, 'category' => $t->category,
            'document_origin' => $t->document_origin, 'issuer_authority' => $t->issuer_authority, 'recipient' => $t->recipient, 'stages' => $t->stages,
            'audience' => $t->audience, 'legal_reference' => $t->legal_reference, 'verifiable' => $t->verifiable, 'security_level' => $t->security_level,
            'scope' => $t->scope, 'is_evidence' => $t->is_evidence, 'generation_triggers' => $t->generation_triggers, 'numbering_family' => $t->numbering_family,
            'aliases' => $t->aliases, 'catalogue_version' => $t->catalogue_version, 'status' => $t->status,
        ];
    }
}
