<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Rules;

use App\Application\Rules\RuleEngine;
use App\Application\Rules\RuleSetService;
use App\Models\InsuranceProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as V;

/**
 * PRE §85 runtime endpoints: POST /insurance/eligibility/check (REQ-RUL-003) and /insurance/completeness/check
 * (REQ-RUL-004). Each call returns the EngineResult envelope and is logged in engine_evaluations.
 */
final class RuleEvaluationController
{
    public function __construct(private readonly RuleEngine $engine) {}

    public function eligibility(Request $r): JsonResponse
    {
        $d = $r->validate([
            'insurance_product_id' => 'required_without:line_code|nullable|uuid|exists:insurance_products,id',
            'line_code' => 'nullable|string|max:32',
            'facts' => 'present|array',
            'reference_date' => 'nullable|date',
            'subject_type' => 'nullable|string|max:64', 'subject_id' => 'nullable|uuid',
        ]);
        $at = isset($d['reference_date']) ? new \DateTimeImmutable($d['reference_date']) : null;
        $products = ! empty($d['insurance_product_id'])
            ? collect([InsuranceProduct::findOrFail($d['insurance_product_id'])])
            : InsuranceProduct::where('line_code', strtoupper((string) $d['line_code']))->where('status', 'ACTIVE')->orderBy('code')->get();
        $subject = ['type' => $d['subject_type'] ?? 'eligibility_check', 'id' => $d['subject_id'] ?? null];

        $data = $products->map(function (InsuranceProduct $p) use ($d, $at, $subject) {
            $e = $this->engine->eligibility($p, $d['facts'], $at, $subject);

            return ['insurance_product_id' => $p->id, 'product_code' => $p->code, 'carrier_id' => $p->carrier_id, 'outcome' => $e['outcome']->value,
                'quotable' => $e['outcome']->quotable(), 'explanations' => $e['explanations'], 'conditions' => $e['conditions'],
                'evaluation_id' => $e['evaluation_id'], 'result' => $e['result']->toArray()];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function completeness(Request $r): JsonResponse
    {
        $d = $r->validate([
            'operation' => ['required', V::in(RuleSetService::OPERATIONS)],
            'line_code' => 'required_without:insurance_product_id|nullable|string|max:32',
            'insurance_product_id' => 'nullable|uuid|exists:insurance_products,id',
            'facts' => 'present|array',
            'reference_date' => 'nullable|date',
            'subject_type' => 'nullable|string|max:64', 'subject_id' => 'nullable|uuid',
        ]);
        $product = ! empty($d['insurance_product_id']) ? InsuranceProduct::findOrFail($d['insurance_product_id']) : null;
        $c = $this->engine->completeness($d['operation'], (string) ($product?->line_code ?? $d['line_code']), $product, $d['facts'],
            isset($d['reference_date']) ? new \DateTimeImmutable($d['reference_date']) : null, ['type' => $d['subject_type'] ?? 'completeness_check', 'id' => $d['subject_id'] ?? null]);

        return response()->json(['data' => ['outcome' => $c['result']->outcome, 'blocking' => $c['result']->blocking, 'explanations' => $c['explanations'],
            'evaluation_id' => $c['evaluation_id'], 'result' => $c['result']->toArray()]]);
    }
}
