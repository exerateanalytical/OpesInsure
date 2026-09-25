<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Application\Audit\AuditWriter;
use App\Application\Catalogue\RiskSchemaCatalogue;
use App\Application\Rules\Models\ProductQuestion;
use App\Application\Rules\Models\QuestionSet;
use App\Domain\Rules\Expression\ExpressionValidator;
use App\Models\DisclosureSchemaVersion;
use App\Models\InsuranceProduct;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * REQ-RUL-001 / REQ-DUP-020 — THE risk-question source: question_sets + product_questions, versioned per product
 * version (insurance_products row) with a line default. The four legacy sources collapse here:
 *   RiskSchemaCatalogue (+ NonMotorRiskSchemas, MotorRiskSchema)  → seed data, synced as SEED_CATALOGUE line sets
 *                                                                    (new version whenever the seed content changes)
 *   insurance_lines.risk_schema                                     → still honoured by the mobile endpoint when a
 *                                                                    stored schema is newer (unchanged behaviour)
 *   disclosure_schema_versions                                      → read-through adapter for stage PROPOSAL until a
 *                                                                    PROPOSAL question set exists for the product/line
 * Rendering contract stays InputFieldContract (every served schema is annotate()d).
 */
final class QuestionSetCatalogue
{
    public const STAGES = ['QUOTE', 'PROPOSAL'];

    public const QUESTION_TYPES = ['TEXT', 'NUMBER', 'DATE', 'SELECT', 'MULTI_SELECT', 'BOOLEAN', 'CURRENCY', 'PERCENTAGE', 'ADDRESS', 'DOCUMENT', 'PHOTO', 'ENTITY_REFERENCE', 'REPEATING_GROUP'];

    /** First seed effective date (the seeded wizard schemas have been live since the 2026 catalogue). */
    private const SEED_EPOCH = '2026-01-01';

    /** @var array<string, bool> seed codes already checked in this process */
    private array $synced = [];

    public function __construct(private readonly AuditWriter $audit, private readonly ApprovalGateway $approvals) {}

    // ---------------------------------------------------------------- read side

    /**
     * Line default QUOTE schema in the legacy wizard shape {version, steps, fields, required, …} — what
     * GET /mobile/catalogue/lines/{code}/risk-schema served from RiskSchemaCatalogue before. Null = no set.
     */
    public function lineSchema(string $lineCode, ?\DateTimeInterface $at = null): ?array
    {
        $code = strtoupper($lineCode);
        if (! $this->tablesExist()) {
            return RiskSchemaCatalogue::for($code);
        }
        $this->syncSeedLine($code);
        $set = $this->resolve(null, $code, 'QUOTE', $at);

        return $set ? $this->schemaOf($set) : RiskSchemaCatalogue::for($code);
    }

    /**
     * Resolved questionnaire for a product version and stage: product set → line set → (PROPOSAL) legacy
     * disclosure_schema_versions adapter. Returns ['question_set' => meta|null, 'schema' => {...}] or null.
     */
    public function questionnaire(?InsuranceProduct $product, string $lineCode, string $stage = 'QUOTE', ?\DateTimeInterface $at = null): ?array
    {
        $code = strtoupper($lineCode);
        $stage = strtoupper($stage);
        if ($stage === 'QUOTE') {
            $this->syncSeedLine($code);
        }
        $set = ($product ? $this->resolve($product->id, $code, $stage, $at) : null) ?? $this->resolve(null, $code, $stage, $at);
        if ($set) {
            return ['question_set' => $this->meta($set), 'schema' => \App\Application\MasterData\InputFieldContract::annotate($this->schemaOf($set))];
        }
        if ($stage === 'PROPOSAL' && ($legacy = $this->disclosureAdapter($code, $at))) {
            return ['question_set' => $legacy['meta'], 'schema' => \App\Application\MasterData\InputFieldContract::annotate($legacy['schema'])];
        }

        return null;
    }

    public function resolve(?string $productId, string $lineCode, string $stage, ?\DateTimeInterface $at = null): ?QuestionSet
    {
        $day = ($at ? \Carbon\CarbonImmutable::instance($at) : now())->toDateString();

        return QuestionSet::query()
            ->when($productId, fn ($q) => $q->where('insurance_product_id', $productId), fn ($q) => $q->whereNull('insurance_product_id')->where('line_code', strtoupper($lineCode)))
            ->where('stage', $stage)->where('status', 'APPROVED')
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day))
            ->orderByDesc('version')->first();
    }

    /** Rebuilds the wizard schema from the stored set (presentation keys + ordered rendered fields). */
    public function schemaOf(QuestionSet $set): array
    {
        $schema = $set->presentation ?? [];
        $schema['fields'] = $set->questions()->get()->map(fn (ProductQuestion $q) => $q->rendered_field)->all();

        return $schema;
    }

    public function meta(QuestionSet $set): array
    {
        return ['id' => $set->id, 'scope_type' => $set->scope_type, 'insurance_product_id' => $set->insurance_product_id, 'line_code' => $set->line_code,
            'stage' => $set->stage, 'version' => $set->version, 'status' => $set->status, 'source' => $set->source, 'schema_hash' => $set->schema_hash,
            'effective_from' => $set->effective_from?->toDateString(), 'effective_until' => $set->effective_until?->toDateString()];
    }

    // ---------------------------------------------------------------- seed sync (PHP schemas are seed data)

    /** Idempotent: one SEED_CATALOGUE line set per wizard schema, a new version only when the seed content changed. */
    public function syncSeedCatalogue(): int
    {
        $created = 0;
        foreach (array_keys(RiskSchemaCatalogue::all()) as $code) {
            $created += $this->syncSeedLine($code, true) ? 1 : 0;
        }

        return $created;
    }

    private function syncSeedLine(string $code, bool $force = false): bool
    {
        if ((! $force && isset($this->synced[$code])) || ! $this->tablesExist()) {
            return false;
        }
        $this->synced[$code] = true;
        $schema = RiskSchemaCatalogue::for($code);
        if (! $schema) {
            return false;
        }
        $hash = self::hash($schema);
        $scope = QuestionSet::whereNull('insurance_product_id')->where('line_code', $code)->where('stage', 'QUOTE');
        $latestSeed = (clone $scope)->where('source', 'SEED_CATALOGUE')->orderByDesc('version')->first();
        if ($latestSeed && $latestSeed->schema_hash === $hash) {
            return false;
        }
        // An administrator-approved line set (MANUAL) newer than the seed supersedes it: never overwrite it automatically.
        if ((clone $scope)->where('source', '!=', 'SEED_CATALOGUE')->where('status', 'APPROVED')->where('version', '>', $latestSeed?->version ?? 0)->exists()) {
            return false;
        }
        $latest = (clone $scope)->orderByDesc('version')->first();
        try {
            DB::transaction(function () use ($code, $schema, $hash, $latest, $latestSeed): void {
                $set = QuestionSet::create([
                    'scope_type' => 'LINE', 'insurance_product_id' => null, 'line_code' => $code, 'stage' => 'QUOTE',
                    'version' => ($latest?->version ?? 0) + 1, 'status' => 'APPROVED', 'source' => 'SEED_CATALOGUE',
                    'schema_version' => (int) ($schema['version'] ?? 1), 'presentation' => array_diff_key($schema, ['fields' => 1]),
                    'schema_hash' => $hash, 'effective_from' => $latestSeed ? now()->toDateString() : self::SEED_EPOCH, 'approved_at' => now(),
                ]);
                $this->storeQuestions($set, $schema);
                $this->audit->record('question_set.seeded', 'question_set', $set->id, ['line_code' => $code, 'version' => $set->version, 'schema_hash' => $hash]);
            });
        } catch (UniqueConstraintViolationException) {
            return false; // a concurrent request seeded the same version
        }

        return true;
    }

    // ---------------------------------------------------------------- write side (maker-checker)

    /**
     * Draft a new question set version. $d: line_code | insurance_product_id, stage, effective_from, effective_until?,
     * schema {steps, fields, required?, version?}.
     */
    public function createDraft(array $d, User $actor): QuestionSet
    {
        $product = isset($d['insurance_product_id']) ? InsuranceProduct::findOrFail($d['insurance_product_id']) : null;
        $line = strtoupper((string) ($product?->line_code ?? $d['line_code'] ?? ''));
        if ($line === '') {
            throw ValidationException::withMessages(['line_code' => 'line_code or insurance_product_id is required.']);
        }
        $stage = strtoupper((string) ($d['stage'] ?? 'QUOTE'));
        $schema = $d['schema'] ?? [];
        $this->validateSchema($schema);

        return DB::transaction(function () use ($product, $line, $stage, $schema, $d, $actor): QuestionSet {
            $scope = QuestionSet::query()->where('stage', $stage)
                ->when($product, fn ($q) => $q->where('insurance_product_id', $product->id), fn ($q) => $q->whereNull('insurance_product_id')->where('line_code', $line));
            $version = ((clone $scope)->orderByDesc('version')->lockForUpdate()->first()?->version ?? 0) + 1;
            $schema['version'] = (int) ($schema['version'] ?? $version);
            $set = QuestionSet::create([
                'scope_type' => $product ? 'PRODUCT_VERSION' : 'LINE', 'insurance_product_id' => $product?->id, 'line_code' => $line, 'stage' => $stage,
                'version' => $version, 'status' => 'DRAFT', 'source' => 'MANUAL', 'schema_version' => $schema['version'],
                'presentation' => array_diff_key($schema, ['fields' => 1]),
                'schema_hash' => self::hash($schema), 'effective_from' => $d['effective_from'] ?? now()->toDateString(), 'effective_until' => $d['effective_until'] ?? null,
                'created_by' => $actor->id,
            ]);
            $this->storeQuestions($set, $schema);
            $this->audit->record('question_set.created', 'question_set', $set->id, ['line_code' => $line, 'insurance_product_id' => $product?->id, 'stage' => $stage, 'version' => $version]);

            return $set;
        });
    }

    public function submit(QuestionSet $set, User $actor): QuestionSet
    {
        if ($set->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Only a DRAFT question set can be submitted.']);
        }

        return DB::transaction(function () use ($set, $actor): QuestionSet {
            $req = $this->approvals->open($actor, 'question_set.approve', 'question_set', $set->id, 'question_sets', $set->insurance_product_id,
                ['line_code' => $set->line_code, 'stage' => $set->stage, 'version' => $set->version, 'schema_hash' => $set->schema_hash]);
            $set->update(['status' => 'IN_REVIEW', 'submitted_by' => $actor->id, 'approval_request_id' => $req->id]);
            $this->audit->record('question_set.submitted', 'question_set', $set->id, ['approval_request_id' => $req->id]);
            if ($this->approvals->isApproved($req)) {
                $this->activate($set, null);
            }

            return $set->refresh();
        });
    }

    public function decide(QuestionSet $set, User $actor, bool $approve, ?string $note = null): QuestionSet
    {
        if ($set->status !== 'IN_REVIEW' || ! $set->approval_request_id) {
            throw ValidationException::withMessages(['status' => 'The question set is not awaiting approval.']);
        }
        if (self::hash($this->schemaOf($set)) !== $set->schema_hash) {
            throw ValidationException::withMessages(['schema' => 'Question set content changed after submission (hash mismatch).']);
        }

        return DB::transaction(function () use ($set, $actor, $approve, $note): QuestionSet {
            $req = $this->approvals->decide($set->approval_request_id, $actor, $approve, $note);
            if ($req->status === 'REJECTED') {
                $set->update(['status' => 'REJECTED']);
                $this->audit->record('question_set.rejected', 'question_set', $set->id, ['approval_request_id' => $req->id], $note);
            } elseif ($this->approvals->isApproved($req)) {
                $this->activate($set, $actor);
            }

            return $set->refresh();
        });
    }

    private function activate(QuestionSet $set, ?User $actor): void
    {
        $set->update(['status' => 'APPROVED', 'approved_by' => $actor?->id, 'approved_at' => now()]);
        $this->audit->record('question_set.approved', 'question_set', $set->id, ['version' => $set->version, 'line_code' => $set->line_code, 'stage' => $set->stage]);
    }

    // ---------------------------------------------------------------- mapping

    private function storeQuestions(QuestionSet $set, array $schema): void
    {
        $rating = array_flip(array_map('strval', $schema['required'] ?? []));
        $now = now();
        $rows = [];
        foreach (array_values($schema['fields'] ?? []) as $i => $f) {
            $rows[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(), 'question_set_id' => $set->id, 'code' => (string) $f['key'],
                'question_type' => self::questionType($f), 'input_type' => strtolower((string) ($f['type'] ?? 'text')),
                'label_en' => mb_substr((string) ($f['label_en'] ?? $f['label'] ?? $f['key']), 0, 255), 'label_fr' => isset($f['label_fr']) ? mb_substr((string) $f['label_fr'], 0, 255) : null,
                'step_code' => $f['step'] ?? null, 'display_order' => $i + 1, 'required' => (bool) ($f['required'] ?? false),
                'options' => isset($f['options']) ? json_encode($f['options'], JSON_THROW_ON_ERROR) : null,
                'validation' => json_encode((object) array_intersect_key($f, array_flip(['min', 'max', 'pattern', 'allocation', 'currency'])), JSON_THROW_ON_ERROR),
                'visibility' => ($v = self::visibility($f)) === null ? null : json_encode($v, JSON_THROW_ON_ERROR),
                'fact_key' => (string) $f['key'], 'risk_factor' => isset($rating[$f['key']]) || array_intersect_key($rating, array_flip(array_filter((array) ($f['maps_to'] ?? []), 'is_string'))) !== [],
                'rendered_field' => json_encode($f, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('product_questions')->insert($chunk);
        }
    }

    /** PRE §17 question type from a wizard field (explicit `question_type` wins). */
    public static function questionType(array $f): string
    {
        if (isset($f['question_type']) && in_array(strtoupper((string) $f['question_type']), self::QUESTION_TYPES, true)) {
            return strtoupper((string) $f['question_type']);
        }
        $type = strtolower((string) ($f['type'] ?? 'text'));
        $key = strtolower((string) ($f['key'] ?? ''));

        return match (true) {
            str_starts_with($type, 'vehicle_') => 'ENTITY_REFERENCE',
            in_array($type, ['multi_select', 'multi_select_master'], true) => 'MULTI_SELECT',
            in_array($type, ['select', 'select_master'], true) => 'SELECT',
            $type === 'boolean' => 'BOOLEAN',
            $type === 'date' => 'DATE',
            $type === 'money' => 'CURRENCY',
            $type === 'number' => str_ends_with($key, '_pct') || str_contains($key, 'percent') ? 'PERCENTAGE' : 'NUMBER',
            $type === 'file' => str_contains($key, 'photo') ? 'PHOTO' : 'DOCUMENT',
            $type === 'repeater' => 'REPEATING_GROUP',
            str_contains($key, 'address') || str_contains($key, 'location') => 'ADDRESS',
            default => 'TEXT',
        };
    }

    /** visible_if / visible_when {field: value | [values] | {not_empty:true} | {contains:x}} → structured expression. */
    public static function visibility(array $f): ?array
    {
        $cond = $f['visible_if'] ?? $f['visible_when'] ?? null;
        if (! is_array($cond) || $cond === []) {
            return null;
        }
        $args = [];
        foreach ($cond as $field => $v) {
            $left = ['fact' => (string) $field];
            $args[] = match (true) {
                is_array($v) && array_is_list($v) => ['op' => 'IN', 'left' => $left, 'right' => ['value' => $v]],
                is_array($v) && isset($v['not_empty']) => ['op' => 'EXISTS', 'left' => $left],
                is_array($v) && isset($v['contains']) => ['op' => 'CONTAINS', 'left' => $left, 'right' => ['value' => $v['contains']]],
                default => ['op' => 'EQUAL', 'left' => $left, 'right' => ['value' => $v]],
            };
        }

        return count($args) === 1 ? $args[0] : ['op' => 'AND', 'args' => $args];
    }

    private function validateSchema(array $schema): void
    {
        $fields = $schema['fields'] ?? null;
        if (! is_array($fields) || $fields === [] || ! array_is_list($fields)) {
            throw ValidationException::withMessages(['schema.fields' => 'A question set needs at least one question.']);
        }
        $seen = [];
        $validator = new ExpressionValidator;
        foreach ($fields as $i => $f) {
            if (! is_array($f) || ! is_string($f['key'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]{0,95}$/', $f['key'])) {
                throw ValidationException::withMessages(["schema.fields.{$i}.key" => 'Each question needs a snake_case key.']);
            }
            if (isset($seen[$f['key']])) {
                throw ValidationException::withMessages(["schema.fields.{$i}.key" => "Duplicate question key {$f['key']}."]);
            }
            $seen[$f['key']] = true;
            if (blank($f['label'] ?? $f['label_en'] ?? null) || blank($f['type'] ?? null)) {
                throw ValidationException::withMessages(["schema.fields.{$i}" => 'Each question needs a label and a type.']);
            }
            if (($v = self::visibility($f)) !== null && ($errors = $validator->validate($v))) {
                throw ValidationException::withMessages(["schema.fields.{$i}.visible_if" => $errors[0]]);
            }
        }
    }

    /** Read-through adapter over disclosure_schema_versions (legacy PROPOSAL questions). */
    private function disclosureAdapter(string $lineCode, ?\DateTimeInterface $at): ?array
    {
        $day = ($at ? \Carbon\CarbonImmutable::instance($at) : now())->toDateString();
        $v = DisclosureSchemaVersion::whereHas('line', fn ($q) => $q->where('code', $lineCode))->where('status', 'APPROVED')
            ->whereDate('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day))
            ->orderByDesc('version')->first();
        if (! $v) {
            return null;
        }
        $fields = array_map(fn (array $q) => [
            'key' => $q['code'], 'label' => $q['label']['en'] ?? $q['label'] ?? $q['code'], 'label_en' => $q['label']['en'] ?? null, 'label_fr' => $q['label']['fr'] ?? null,
            'type' => $q['type'] ?? 'boolean', 'step' => 'disclosures', 'required' => (bool) ($q['required'] ?? false),
            'referral_values' => $q['referral_values'] ?? [], 'referral_code' => $q['referral_code'] ?? null,
        ], $v->questions ?? []);

        return ['meta' => ['id' => $v->id, 'scope_type' => 'LINE', 'insurance_product_id' => null, 'line_code' => $lineCode, 'stage' => 'PROPOSAL', 'version' => $v->version,
            'status' => $v->status, 'source' => 'LEGACY_DISCLOSURE_SCHEMA', 'schema_hash' => $v->schema_hash,
            'effective_from' => $v->effective_from?->toDateString(), 'effective_until' => $v->effective_until?->toDateString()],
            'schema' => ['version' => $v->version, 'steps' => [['key' => 'disclosures', 'label' => 'Disclosures']], 'fields' => $fields, 'required' => []]];
    }

    public static function hash(array $schema): string
    {
        return hash('sha256', AuditWriter::canonicalJson($schema));
    }

    private ?bool $tables = null;

    private function tablesExist(): bool
    {
        return $this->tables ??= Schema::hasTable('question_sets');
    }
}
