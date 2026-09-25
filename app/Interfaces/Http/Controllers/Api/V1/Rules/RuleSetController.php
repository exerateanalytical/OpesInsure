<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Rules;

use App\Application\Rules\Models\RuleSet;
use App\Application\Rules\RuleEngine;
use App\Application\Rules\RuleSetService;
use App\Domain\Rules\Expression\ExpressionValidator;
use App\Domain\Rules\RuleSetDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as V;

/** REQ-RUL-002 — rule set authoring and governance (/api/v1/rule-sets). Transitions live in RuleSetService. */
final class RuleSetController
{
    public function __construct(private readonly RuleSetService $sets, private readonly RuleEngine $engine) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['domain' => 'nullable|string|max:32', 'status' => 'nullable|string|max:24', 'insurance_product_id' => 'nullable|uuid', 'line_code' => 'nullable|string|max:32', 'code' => 'nullable|string|max:96']);
        $q = RuleSet::query()->withCount('rules')
            ->when($d['domain'] ?? null, fn ($q, $v) => $q->where('domain', strtoupper($v)))
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', strtoupper($v)))
            ->when($d['insurance_product_id'] ?? null, fn ($q, $v) => $q->where('insurance_product_id', $v))
            ->when($d['line_code'] ?? null, fn ($q, $v) => $q->where('line_code', strtoupper($v)))
            ->when($d['code'] ?? null, fn ($q, $v) => $q->where('code', $v))
            ->orderBy('code')->orderByDesc('version');

        return response()->json($q->paginate(min(100, max(1, (int) $r->query('per_page', 50)))));
    }

    public function show(string $ruleSet): JsonResponse
    {
        return response()->json(['data' => RuleSet::with(['rules' => fn ($q) => $q->orderBy('priority')->orderBy('code')])->findOrFail($ruleSet)]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'code' => 'required|string|regex:/^[A-Z][A-Z0-9_.-]{1,95}$/',
            'domain' => ['required', 'string', V::in(RuleSetDefinition::DOMAINS)],
            'insurance_product_id' => 'nullable|uuid|exists:insurance_products,id',
            'line_code' => 'nullable|string|max:32',
            'operation' => ['nullable', 'string', V::in(RuleSetService::OPERATIONS)],
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'description' => 'nullable|string|max:2000',
            'rules' => 'required|array|min:1|max:200',
            'rules.*.code' => 'required|string|max:96',
            'rules.*.condition' => 'present',
            'rules.*.outcome' => 'required|array',
        ]);

        return response()->json(['data' => $this->sets->createDraft(['rules' => $r->input('rules')] + $d, $r->user())->load('rules')], 201);
    }

    public function submit(Request $r, string $ruleSet): JsonResponse
    {
        return response()->json(['data' => $this->sets->submit(RuleSet::findOrFail($ruleSet), $r->user())]);
    }

    public function approve(Request $r, string $ruleSet): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->sets->decide(RuleSet::findOrFail($ruleSet), $r->user(), true, $d['note'] ?? null)]);
    }

    public function reject(Request $r, string $ruleSet): JsonResponse
    {
        $d = $r->validate(['note' => 'required|string|min:3|max:500']);

        return response()->json(['data' => $this->sets->decide(RuleSet::findOrFail($ruleSet), $r->user(), false, $d['note'])]);
    }

    public function retire(Request $r, string $ruleSet): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);

        return response()->json(['data' => $this->sets->retire(RuleSet::findOrFail($ruleSet), $r->user(), $d['reason'])]);
    }

    /** PRE §76 sandbox: evaluate this version (any status) against sample facts, full trace, nothing persisted. */
    public function simulate(Request $r, string $ruleSet): JsonResponse
    {
        $d = $r->validate(['facts' => 'present|array', 'reference_date' => 'nullable|date']);

        return response()->json(['data' => $this->engine->simulate(RuleSet::with('rules')->findOrFail($ruleSet), $d['facts'], isset($d['reference_date']) ? new \DateTimeImmutable($d['reference_date']) : null)]);
    }

    public function validateExpression(Request $r): JsonResponse
    {
        $r->validate(['condition' => 'present']);
        $errors = (new ExpressionValidator)->validate($r->input('condition'));

        return response()->json(['data' => ['valid' => $errors === [], 'errors' => $errors]]);
    }
}
