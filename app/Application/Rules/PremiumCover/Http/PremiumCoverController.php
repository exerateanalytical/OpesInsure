<?php

declare(strict_types=1);

namespace App\Application\Rules\PremiumCover\Http;

use App\Application\Audit\AuditWriter;
use App\Application\Rules\PremiumCover\PremiumCoverEvaluator;
use App\Domain\Rules\Expression\ExpressionValidator;
use App\Interfaces\Http\Errors\ApiProblemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * REQ-POL-008 premium-to-cover rules (owner decision #17). DRAFT → ACTIVE by a different user (maker-checker),
 * ACTIVE → RETIRED. The activation rule is validated by the Rules ExpressionValidator.
 */
final class PremiumCoverController
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => 'nullable|in:DRAFT,ACTIVE,RETIRED', 'product_id' => 'nullable|uuid']);

        return response()->json(['data' => DB::table('premium_cover_rules')
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($d['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->orderBy('code')->get()->map(fn ($r) => $this->present($r))]);
    }

    public function store(Request $r, ExpressionValidator $validator): JsonResponse
    {
        $d = $r->validate([
            'code' => 'required|string|max:64|regex:/^[A-Z][A-Z0-9_]*$/', 'name' => 'required|string|max:160',
            'carrier_id' => 'nullable|uuid', 'product_id' => 'nullable|uuid', 'class_code' => 'nullable|string|max:40', 'jurisdiction' => 'nullable|string|size:2',
            'premium_statuses' => 'nullable|array', 'premium_statuses.*' => ['string', Rule::in(PremiumCoverEvaluator::PREMIUM_STATUSES)],
            'is_exception' => 'nullable|boolean', 'activation_rule' => 'present',
            'outcome' => ['required', Rule::in(PremiumCoverEvaluator::OUTCOMES)], 'grace_days' => 'nullable|integer|min:1|max:366|required_if:outcome,GRACE', 'lapse_after_days' => 'nullable|integer|min:1|max:366',
            'priority' => 'nullable|integer|between:-1000,1000', 'legal_basis' => 'nullable|string|max:2000',
            'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);
        $errors = $validator->validate($d['activation_rule']);
        if ($errors !== []) {
            throw new ApiProblemException('ACTIVATION_RULE_INVALID', 422, 'The activation rule is not a valid rules expression.', [], ['errors' => $errors]);
        }
        $id = (string) Str::uuid();
        DB::table('premium_cover_rules')->insert([
            'id' => $id, 'code' => $d['code'], 'name' => $d['name'], 'carrier_id' => $d['carrier_id'] ?? null, 'product_id' => $d['product_id'] ?? null,
            'class_code' => $d['class_code'] ?? null, 'jurisdiction' => $d['jurisdiction'] ?? null,
            'premium_statuses' => json_encode(array_values($d['premium_statuses'] ?? [])), 'is_exception' => (bool) ($d['is_exception'] ?? false),
            'activation_rule' => json_encode($d['activation_rule']), 'outcome' => $d['outcome'], 'grace_days' => $d['grace_days'] ?? null, 'lapse_after_days' => $d['lapse_after_days'] ?? null,
            'priority' => $d['priority'] ?? 100, 'legal_basis' => $d['legal_basis'] ?? null, 'verification_status' => 'UNVERIFIED',
            'effective_from' => $d['effective_from'], 'effective_until' => $d['effective_until'] ?? null, 'status' => 'DRAFT',
            'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('premium_cover_rule.drafted', 'premium_cover_rule', $id, ['code' => $d['code'], 'outcome' => $d['outcome']]);

        return response()->json(['data' => $this->present(DB::table('premium_cover_rules')->find($id))], 201);
    }

    public function approve(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['verification_status' => 'nullable|in:UNVERIFIED,VERIFIED', 'reason' => 'nullable|string|max:2000']);
        $row = DB::table('premium_cover_rules')->where('id', $id)->first() ?? abort(404);
        if ($row->status !== 'DRAFT') {
            throw new ApiProblemException('RULE_NOT_DRAFT', 409, 'Only a DRAFT rule can be approved.');
        }
        if ($row->created_by === $r->user()->id) {
            throw new ApiProblemException('MAKER_CHECKER_REQUIRED', 403, 'The maker of a premium-to-cover rule cannot approve it.');
        }
        $verification = $d['verification_status'] ?? 'UNVERIFIED';
        if ($verification === 'VERIFIED' && trim((string) $row->legal_basis) === '') {
            throw new ApiProblemException('LEGAL_BASIS_REQUIRED', 422, 'A rule can only be marked VERIFIED with a legal basis.');
        }
        DB::table('premium_cover_rules')->where('id', $id)->update(['status' => 'ACTIVE', 'verification_status' => $verification, 'approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
        $this->audit->record('premium_cover_rule.approved', 'premium_cover_rule', $id, ['code' => $row->code, 'verification_status' => $verification], $d['reason'] ?? null);

        return response()->json(['data' => $this->present(DB::table('premium_cover_rules')->find($id))]);
    }

    public function retire(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);
        if (DB::table('premium_cover_rules')->where('id', $id)->whereIn('status', ['DRAFT', 'ACTIVE'])->update(['status' => 'RETIRED', 'updated_at' => now()]) !== 1) {
            throw new ApiProblemException('RULE_NOT_RETIRABLE', 409, 'Rule not found or already retired.');
        }
        $this->audit->record('premium_cover_rule.retired', 'premium_cover_rule', $id, [], $d['reason']);

        return response()->json(['data' => $this->present(DB::table('premium_cover_rules')->find($id))]);
    }

    public function evaluate(Request $r, PremiumCoverEvaluator $evaluator): JsonResponse
    {
        $d = $r->validate([
            'product_id' => 'nullable|uuid', 'carrier_id' => 'nullable|uuid', 'class_code' => 'nullable|string|max:40', 'jurisdiction' => 'nullable|string|size:2',
            'premium_status' => ['required', Rule::in(PremiumCoverEvaluator::PREMIUM_STATUSES)], 'effective_date' => 'required|date_format:Y-m-d',
            'facts' => 'nullable|array',
        ]);

        return response()->json(['data' => $evaluator->evaluate($d)]);
    }

    private function present(object $r): array
    {
        $a = (array) $r;
        $a['premium_statuses'] = json_decode((string) $r->premium_statuses, true);
        $a['activation_rule'] = json_decode((string) $r->activation_rule, true);
        $a['is_exception'] = (bool) $r->is_exception;

        return $a;
    }
}
