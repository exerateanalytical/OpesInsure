<?php

declare(strict_types=1);

namespace App\Application\Claims\Evidence;

use App\Application\DocumentCatalogue\DocumentCatalogueService;
use App\Application\DocumentCatalogue\DocumentRequirementResolver;
use App\Application\Documents\DocumentOrigin;
use App\Application\Rules\RuleEngine;
use App\Models\Claim;
use App\Models\DocumentCatalogue\DocumentType;
use App\Models\InsuranceProduct;

/**
 * REQ-CLM-005: evidence rules per claim type. No second rule store — the rules are read from:
 *  1. the Document Requirement Matrix (stage CLAIM) when the claim's product has a document product type
 *     selected (incl. approved insurer overrides), else
 *  2. the catalogue CLAIM packs of the policy's class (UNIVERSAL_CLAIM when the class has none), then
 *  3. the rules engine DOCUMENTS domain (REQ-RUL-002) on top: REQUIRE makes a type mandatory, WAIVE waives it.
 * Mandatory = requirement REQUIRED (matrix level M) or required by a rule, and not waived.
 */
final class ClaimEvidenceRules
{
    /** Strongest first — when two packs list the same type the stronger requirement wins. */
    public const STRENGTH = ['REQUIRED', 'THIRD_PARTY', 'CONDITIONAL', 'PRODUCT_DEPENDENT', 'WHERE_APPLICABLE', 'OPTIONAL', 'INTERNAL'];

    private const MATRIX_LEVELS = ['M' => 'REQUIRED', 'C' => 'CONDITIONAL', 'O' => 'OPTIONAL', 'I' => 'INTERNAL', 'T' => 'THIRD_PARTY'];

    /** Commercial line codes whose catalogue class code differs. */
    private const CLASS_ALIASES = ['AUTO' => 'MOTOR', 'AUTOMOBILE' => 'MOTOR', 'FLEET' => 'MOTOR_FLEET', 'HOME' => 'HOME_MULTIRISK', 'MRH' => 'HOME_MULTIRISK', 'SANTE' => 'HEALTH', 'VIE' => 'LIFE'];

    public function __construct(private DocumentCatalogueService $catalogue, private DocumentRequirementResolver $resolver, private RuleEngine $rules) {}

    /**
     * @return array{line_code: ?string, class_code: ?string, source: string, rule_versions: array<string,string>, rules: list<array<string,mixed>>}
     */
    public function forClaim(Claim $claim): array
    {
        $policy = $claim->policy;
        $product = $this->product($claim);
        $line = strtoupper((string) ($policy?->terms_snapshot['line_code'] ?? $product?->line_code ?? $policy?->proposal?->offer?->quote?->line_code ?? ''));
        $class = $line === '' ? null : (self::CLASS_ALIASES[$line] ?? $line);

        $rules = [];
        $source = 'NONE';
        if ($product && $this->resolver->productTypeFor($product)) {
            foreach ($this->catalogue->requirementsFor($product, 'CLAIM') as $r) {
                $this->merge($rules, (string) $r['document_type_id'], self::MATRIX_LEVELS[$r['level']] ?? 'OPTIONAL', 'MATRIX:'.$r['source']);
            }
            $source = 'MATRIX';
        }
        if ($rules === []) {
            $packs = $this->catalogue->packs(['class' => $class, 'stage' => 'CLAIM']);
            $own = $class ? $packs->reject(fn ($p) => $p->is_universal) : collect();
            foreach (($own->isNotEmpty() ? $own : $packs->filter(fn ($p) => $p->is_universal)) as $pack) {
                foreach ($pack->items as $item) {
                    $this->merge($rules, (string) $item->document_type_id, (string) $item->requirement, 'PACK:'.$pack->code);
                }
            }
            $source = $rules === [] ? 'NONE' : 'PACK';
        }

        $versions = [];
        if ($line !== '') {
            $facts = ['claim' => ['estimated_loss_minor' => $claim->estimated_loss_minor, 'priority' => $claim->priority, 'loss_details' => $claim->loss_details ?? []]] + (array) ($claim->loss_details ?? []);
            $doc = $this->rules->documents($line, $product, $facts, $claim->loss_occurred_at);
            $versions = $doc['versions'];
            foreach ($doc['require'] as $code) {
                $id = $this->typeId($code);
                $this->merge($rules, $id, 'REQUIRED', 'RULE');
                $rules[$id]['required_by_rule'] = true;
            }
            foreach ($doc['waive'] as $code) {
                $id = $this->typeId($code);
                if (isset($rules[$id])) {
                    $rules[$id]['waived'] = true;
                }
            }
        }

        $types = DocumentType::whereIn('type_id', array_keys($rules))->get()->keyBy('type_id');
        $out = [];
        foreach ($rules as $id => $r) {
            $t = $types[$id] ?? null;
            $origin = $t?->document_origin;
            $out[] = $r + [
                'canonical_code' => $t?->canonical_code, 'name_en' => $t?->name_en, 'name_fr' => $t?->name_fr, 'expected_origin' => $origin,
                'third_party' => $r['requirement'] === 'THIRD_PARTY' || ($origin !== null && DocumentOrigin::isThirdPartyEvidence($origin)),
                'mandatory' => ! $r['waived'] && ($r['requirement'] === 'REQUIRED' || $r['required_by_rule']),
            ];
        }

        return ['line_code' => $line ?: null, 'class_code' => $class, 'source' => $source, 'rule_versions' => $versions, 'rules' => $out];
    }

    /** Catalogue type id for a code, type id or alias (unknown codes are kept verbatim). */
    public function typeId(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return $this->catalogue->find($code)?->type_id ?? strtoupper(trim($code));
    }

    private function merge(array &$rules, string $typeId, string $requirement, string $source): void
    {
        $requirement = strtoupper($requirement);
        $cur = $rules[$typeId] ?? null;
        if ($cur === null) {
            $rules[$typeId] = ['document_type_id' => $typeId, 'requirement' => $requirement, 'sources' => [$source], 'required_by_rule' => false, 'waived' => false];

            return;
        }
        $rules[$typeId]['sources'][] = $source;
        $rank = fn (string $r) => array_search($r, self::STRENGTH, true) === false ? 99 : array_search($r, self::STRENGTH, true);
        if ($rank($requirement) < $rank($cur['requirement'])) {
            $rules[$typeId]['requirement'] = $requirement;
        }
    }

    private function product(Claim $claim): ?InsuranceProduct
    {
        $offer = $claim->policy?->proposal?->offer;

        return $offer?->product;
    }
}
