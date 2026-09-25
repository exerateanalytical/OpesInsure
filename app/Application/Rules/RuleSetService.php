<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Application\Audit\AuditWriter;
use App\Application\Rules\Models\Rule;
use App\Application\Rules\Models\RuleSet;
use App\Domain\Rules\EligibilityOutcome;
use App\Domain\Rules\Expression\ExpressionValidator;
use App\Domain\Rules\RuleDefinition;
use App\Domain\Rules\RuleSetDefinition;
use App\Models\InsuranceProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-RUL-002 — rule set governance: DRAFT → IN_REVIEW (approval_requests rule_set.approve) → APPROVED | REJECTED,
 * APPROVED → RETIRED. Versions are immutable once submitted; a change is a new version of the same code. Every
 * condition is validated against the structured grammar before it is stored (no uploaded code).
 */
final class RuleSetService
{
    public const SCOPES = ['PRODUCT_VERSION', 'LINE', 'PLATFORM'];

    public const OPERATIONS = ['QUOTE', 'BIND', 'ISSUE', 'CLAIM'];

    public function __construct(private readonly AuditWriter $audit, private readonly ApprovalGateway $approvals) {}

    /**
     * @param  array{code: string, domain: string, insurance_product_id?: string|null, line_code?: string|null, operation?: string|null,
     *               effective_from: string, effective_until?: string|null, description?: string|null, rules: list<array<string, mixed>>}  $d
     */
    public function createDraft(array $d, User $actor): RuleSet
    {
        $domain = strtoupper((string) $d['domain']);
        if (! in_array($domain, RuleSetDefinition::DOMAINS, true)) {
            throw ValidationException::withMessages(['domain' => 'Unknown rule domain.']);
        }
        $product = ! empty($d['insurance_product_id']) ? InsuranceProduct::findOrFail($d['insurance_product_id']) : null;
        $line = $product ? null : (isset($d['line_code']) ? strtoupper((string) $d['line_code']) : null);
        $scope = $product ? 'PRODUCT_VERSION' : ($line ? 'LINE' : 'PLATFORM');
        $operation = isset($d['operation']) ? strtoupper((string) $d['operation']) : null;
        if ($domain === 'COMPLETENESS' && ! in_array($operation, self::OPERATIONS, true)) {
            throw ValidationException::withMessages(['operation' => 'A completeness rule set needs operation QUOTE, BIND, ISSUE or CLAIM.']);
        }
        $rules = $this->validateRules($d['rules'] ?? [], $domain);

        return DB::transaction(function () use ($d, $domain, $product, $line, $scope, $operation, $rules, $actor): RuleSet {
            $existing = RuleSet::where('code', $d['code'])->lockForUpdate()->orderByDesc('version')->first();
            if ($existing && ($existing->domain !== $domain || $existing->scope_type !== $scope || $existing->insurance_product_id !== $product?->id || $existing->line_code !== $line || $existing->operation !== $operation)) {
                throw ValidationException::withMessages(['code' => 'A rule set code keeps its domain, scope and operation across versions.']);
            }
            $set = RuleSet::create([
                'code' => $d['code'], 'domain' => $domain, 'scope_type' => $scope, 'insurance_product_id' => $product?->id, 'line_code' => $line,
                'operation' => $operation, 'version' => ($existing?->version ?? 0) + 1, 'status' => 'DRAFT',
                'effective_from' => $d['effective_from'], 'effective_until' => $d['effective_until'] ?? null, 'description' => $d['description'] ?? null,
                'created_by' => $actor->id,
            ]);
            foreach ($rules as $r) {
                Rule::create(['rule_set_id' => $set->id] + $r);
            }
            $set->update(['content_hash' => $this->contentHash($set->refresh())]);
            $this->audit->record('rule_set.created', 'rule_set', $set->id, ['code' => $set->code, 'version' => $set->version, 'domain' => $domain, 'scope_type' => $scope]);

            return $set->refresh();
        });
    }

    public function submit(RuleSet $set, User $actor): RuleSet
    {
        if ($set->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Only a DRAFT rule set can be submitted.']);
        }
        if ($set->rules()->count() === 0) {
            throw ValidationException::withMessages(['rules' => 'A rule set needs at least one rule.']);
        }

        return DB::transaction(function () use ($set, $actor): RuleSet {
            $req = $this->approvals->open($actor, 'rule_set.approve', 'rule_set', $set->id, 'rule_sets', $set->insurance_product_id,
                ['code' => $set->code, 'version' => $set->version, 'domain' => $set->domain, 'content_hash' => $set->content_hash]);
            $set->update(['status' => 'IN_REVIEW', 'submitted_by' => $actor->id, 'approval_request_id' => $req->id]);
            $this->audit->record('rule_set.submitted', 'rule_set', $set->id, ['approval_request_id' => $req->id]);
            if ($this->approvals->isApproved($req)) {
                $this->activate($set, null);
            }

            return $set->refresh();
        });
    }

    public function decide(RuleSet $set, User $actor, bool $approve, ?string $note = null): RuleSet
    {
        if ($set->status !== 'IN_REVIEW' || ! $set->approval_request_id) {
            throw ValidationException::withMessages(['status' => 'The rule set is not awaiting approval.']);
        }
        if ($this->contentHash($set) !== $set->content_hash) {
            throw ValidationException::withMessages(['rules' => 'Rule set content changed after submission (hash mismatch).']);
        }

        return DB::transaction(function () use ($set, $actor, $approve, $note): RuleSet {
            $req = $this->approvals->decide($set->approval_request_id, $actor, $approve, $note);
            if ($req->status === 'REJECTED') {
                $set->update(['status' => 'REJECTED']);
                $this->audit->record('rule_set.rejected', 'rule_set', $set->id, ['approval_request_id' => $req->id], $note);
            } elseif ($this->approvals->isApproved($req)) {
                $this->activate($set, $actor);
            }

            return $set->refresh();
        });
    }

    public function retire(RuleSet $set, User $actor, string $reason): RuleSet
    {
        if ($set->status !== 'APPROVED') {
            throw ValidationException::withMessages(['status' => 'Only an APPROVED rule set can be retired.']);
        }
        $set->update(['status' => 'RETIRED', 'retired_at' => now()]);
        $this->audit->record('rule_set.retired', 'rule_set', $set->id, ['code' => $set->code, 'version' => $set->version], $reason);

        return $set->refresh();
    }

    public function toDefinition(RuleSet $set): RuleSetDefinition
    {
        $rules = $set->relationLoaded('rules') ? $set->rules : $set->rules()->get();

        return new RuleSetDefinition($set->code, $set->domain, $set->version, $rules->map(fn (Rule $r) => new RuleDefinition(
            $r->code, $r->priority, $r->stop_processing, $r->condition, $r->outcome ?? [], $r->id, $r->explanation_en, $r->explanation_fr, $r->enabled,
        ))->all(), $set->id, $set->content_hash);
    }

    public function contentHash(RuleSet $set): string
    {
        return hash('sha256', AuditWriter::canonicalJson([
            'code' => $set->code, 'domain' => $set->domain, 'scope_type' => $set->scope_type, 'insurance_product_id' => $set->insurance_product_id,
            'line_code' => $set->line_code, 'operation' => $set->operation, 'version' => $set->version,
            'effective_from' => $set->effective_from?->toDateString(), 'effective_until' => $set->effective_until?->toDateString(),
            'rules' => $set->rules()->orderBy('code')->get()->map(fn (Rule $r) => ['code' => $r->code, 'priority' => $r->priority, 'stop_processing' => $r->stop_processing,
                'condition' => $r->condition, 'outcome' => $r->outcome, 'enabled' => $r->enabled, 'explanation_en' => $r->explanation_en, 'explanation_fr' => $r->explanation_fr])->all(),
        ]));
    }

    /** @return list<array<string, mixed>> */
    public function validateRules(array $rules, string $domain): array
    {
        if ($rules === [] || ! array_is_list($rules)) {
            throw ValidationException::withMessages(['rules' => 'A rule set needs at least one rule.']);
        }
        $validator = new ExpressionValidator;
        $out = [];
        $codes = [];
        foreach ($rules as $i => $r) {
            $code = (string) ($r['code'] ?? '');
            if (! preg_match('/^[A-Z][A-Z0-9_]{1,95}$/', $code) || isset($codes[$code])) {
                throw ValidationException::withMessages(["rules.{$i}.code" => 'Each rule needs a unique UPPER_SNAKE code.']);
            }
            $codes[$code] = true;
            if ($errors = $validator->validate($r['condition'] ?? null)) {
                throw ValidationException::withMessages(["rules.{$i}.condition" => $errors[0]]);
            }
            $outcome = (array) ($r['outcome'] ?? []);
            if ($domain === 'ELIGIBILITY') {
                try {
                    $outcome['result'] = EligibilityOutcome::parse((string) ($outcome['result'] ?? ''))->value;
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(["rules.{$i}.outcome.result" => 'Eligibility outcome must be ELIGIBLE, CONDITIONAL, MORE_INFORMATION_REQUIRED, REFER_TO_UNDERWRITING or INELIGIBLE.']);
                }
            } elseif ($domain === 'COMPLETENESS') {
                $outcome['result'] = strtoupper((string) ($outcome['result'] ?? 'BLOCK'));
                if (! in_array($outcome['result'], ['BLOCK', 'WARN'], true)) {
                    throw ValidationException::withMessages(["rules.{$i}.outcome.result" => 'Completeness outcome must be BLOCK or WARN.']);
                }
            }
            if (blank($outcome['reason_code'] ?? null)) {
                $outcome['reason_code'] = $code;
            }
            $out[] = [
                'code' => $code, 'name_en' => (string) ($r['name_en'] ?? $r['name'] ?? $code), 'name_fr' => $r['name_fr'] ?? null,
                'priority' => (int) ($r['priority'] ?? 100), 'stop_processing' => (bool) ($r['stop_processing'] ?? false),
                'condition' => $r['condition'], 'outcome' => $outcome, 'explanation_en' => $r['explanation_en'] ?? null, 'explanation_fr' => $r['explanation_fr'] ?? null,
                'enabled' => (bool) ($r['enabled'] ?? true),
            ];
        }

        return $out;
    }

    private function activate(RuleSet $set, ?User $actor): void
    {
        $set->update(['status' => 'APPROVED', 'approved_by' => $actor?->id, 'approved_at' => now()]);
        $this->audit->record('rule_set.approved', 'rule_set', $set->id, ['code' => $set->code, 'version' => $set->version, 'content_hash' => $set->content_hash]);
    }
}
