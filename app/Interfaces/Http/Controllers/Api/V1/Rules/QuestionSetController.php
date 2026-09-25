<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Rules;

use App\Application\Rules\Models\QuestionSet;
use App\Application\Rules\QuestionSetCatalogue;
use App\Models\InsuranceProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as V;

/** REQ-RUL-001 / REQ-DUP-020 — question sets per product version (/api/v1/question-sets, /products/{id}/questionnaire). */
final class QuestionSetController
{
    public function __construct(private readonly QuestionSetCatalogue $catalogue) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['line_code' => 'nullable|string|max:32', 'insurance_product_id' => 'nullable|uuid', 'stage' => 'nullable|string|max:16', 'status' => 'nullable|string|max:24']);
        $q = QuestionSet::query()->withCount('questions')
            ->when($d['line_code'] ?? null, fn ($q, $v) => $q->where('line_code', strtoupper($v)))
            ->when($d['insurance_product_id'] ?? null, fn ($q, $v) => $q->where('insurance_product_id', $v))
            ->when($d['stage'] ?? null, fn ($q, $v) => $q->where('stage', strtoupper($v)))
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', strtoupper($v)))
            ->orderBy('line_code')->orderBy('stage')->orderByDesc('version');

        return response()->json($q->paginate(min(100, max(1, (int) $r->query('per_page', 50)))));
    }

    public function show(string $questionSet): JsonResponse
    {
        $set = QuestionSet::with('questions')->findOrFail($questionSet);

        return response()->json(['data' => $this->catalogue->meta($set) + ['questions' => $set->questions, 'schema' => $this->catalogue->schemaOf($set)]]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'line_code' => 'required_without:insurance_product_id|nullable|string|max:32|exists:insurance_lines,code',
            'insurance_product_id' => 'nullable|uuid|exists:insurance_products,id',
            'stage' => ['nullable', V::in(QuestionSetCatalogue::STAGES)],
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'schema' => 'required|array',
            'schema.fields' => 'required|array|min:1|max:500',
            'schema.steps' => 'nullable|array',
            'schema.required' => 'nullable|array',
        ]);
        $set = $this->catalogue->createDraft(['schema' => $r->input('schema')] + $d, $r->user());

        return response()->json(['data' => $this->catalogue->meta($set) + ['questions' => $set->questions()->get()]], 201);
    }

    public function submit(Request $r, string $questionSet): JsonResponse
    {
        return response()->json(['data' => $this->catalogue->meta($this->catalogue->submit(QuestionSet::findOrFail($questionSet), $r->user()))]);
    }

    public function approve(Request $r, string $questionSet): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->catalogue->meta($this->catalogue->decide(QuestionSet::findOrFail($questionSet), $r->user(), true, $d['note'] ?? null))]);
    }

    public function reject(Request $r, string $questionSet): JsonResponse
    {
        $d = $r->validate(['note' => 'required|string|min:3|max:500']);

        return response()->json(['data' => $this->catalogue->meta($this->catalogue->decide(QuestionSet::findOrFail($questionSet), $r->user(), false, $d['note']))]);
    }

    /** Resolved questionnaire for a product version: product set → line default → legacy disclosure schema (PROPOSAL). */
    public function questionnaire(Request $r, string $product): JsonResponse
    {
        $d = $r->validate(['stage' => ['nullable', V::in(QuestionSetCatalogue::STAGES)], 'reference_date' => 'nullable|date']);
        $p = InsuranceProduct::findOrFail($product);
        $q = $this->catalogue->questionnaire($p, $p->line_code, $d['stage'] ?? 'QUOTE', isset($d['reference_date']) ? new \DateTimeImmutable($d['reference_date']) : null);
        abort_if($q === null, 404, 'No question set for this product and stage.');

        return response()->json(['data' => ['insurance_product_id' => $p->id, 'line_code' => $p->line_code] + $q]);
    }
}
