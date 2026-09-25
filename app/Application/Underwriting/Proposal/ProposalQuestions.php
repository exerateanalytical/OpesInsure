<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Proposal;

use App\Application\Rules\QuestionSetCatalogue;
use App\Models\InsuranceProduct;
use App\Models\Proposal;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PRP-002 / REQ-RUL-001 — PROPOSAL-stage questions for a proposal, resolved through the rules engine's question
 * sets (QuestionSetCatalogue::questionnaire(product, line, PROPOSAL): product set → line set → read-through adapter over
 * the legacy disclosure_schema_versions). The proposal no longer reads disclosure_schema_versions itself.
 *
 * The resolved questionnaire is frozen onto the proposal (question_snapshot + hash) so later catalogue changes never
 * alter what the customer is answering. Questions are served in the disclosure shape the live app already renders:
 * {code, label{en,fr}|string, type, required, referral_values, referral_code, options, source, …}.
 */
final class ProposalQuestions
{
    public function __construct(private readonly QuestionSetCatalogue $catalogue) {}

    /**
     * @return array{source: string, question_set_id: ?string, disclosure_schema_version_id: ?string, version: ?int, schema_hash: ?string, questions: list<array<string,mixed>>}|null
     */
    public function resolve(?InsuranceProduct $product, string $lineCode): ?array
    {
        $q = $this->catalogue->questionnaire($product, $lineCode, 'PROPOSAL');
        if ($q === null) {
            return null;
        }
        $meta = $q['question_set'] ?? [];
        $legacy = ($meta['source'] ?? null) === 'LEGACY_DISCLOSURE_SCHEMA';

        return [
            'source' => $legacy ? 'LEGACY_DISCLOSURE_SCHEMA' : 'QUESTION_SET',
            'question_set_id' => $legacy ? null : ($meta['id'] ?? null),
            'disclosure_schema_version_id' => $legacy ? ($meta['id'] ?? null) : null,
            'version' => isset($meta['version']) ? (int) $meta['version'] : null,
            'schema_hash' => $meta['schema_hash'] ?? null,
            'questions' => array_values(array_map(fn (array $f) => self::fromField($f), $q['schema']['fields'] ?? [])),
        ];
    }

    /** Questions this proposal is answering: frozen snapshot, else (pre-6D rows) the linked legacy disclosure schema. */
    public function of(Proposal $p): array
    {
        if (is_array($p->question_snapshot) && isset($p->question_snapshot['questions'])) {
            return $p->question_snapshot['questions'];
        }

        return array_values($p->disclosureSchema?->questions ?? []);
    }

    /** @return array<string,mixed> meta of the questionnaire (without the questions) for snapshots / APIs */
    public function metaOf(Proposal $p): array
    {
        $s = is_array($p->question_snapshot) ? $p->question_snapshot : [];
        unset($s['questions']);

        return $s + ['question_set_id' => $p->question_set_id, 'disclosure_schema_version_id' => $p->disclosure_schema_version_id, 'snapshot_hash' => $p->question_snapshot_hash];
    }

    /**
     * Required, visible questions must be answered.
     *
     * @throws ValidationException
     */
    public function assertAnswered(array $questions, array $answers): void
    {
        foreach ($questions as $q) {
            if (($q['required'] ?? false) && self::visible($q, $answers) && (! array_key_exists($q['code'], $answers) || $answers[$q['code']] === null || $answers[$q['code']] === '')) {
                throw ValidationException::withMessages(["answers.{$q['code']}" => __('wave3.answer_required')]);
            }
        }
    }

    /** @return list<string> referral codes raised by the answers (question referral_values). */
    public function referralFlags(array $questions, array $answers): array
    {
        $flags = [];
        foreach ($questions as $q) {
            if (in_array($answers[$q['code']] ?? null, $q['referral_values'] ?? [], true)) {
                $flags[] = $q['referral_code'] ?? $q['code'];
            }
        }

        return array_values(array_unique($flags));
    }

    /** Wizard field (question set rendered_field / legacy adapter field) → disclosure question shape. */
    public static function fromField(array $f): array
    {
        $en = $f['label_en'] ?? (is_array($f['label'] ?? null) ? ($f['label']['en'] ?? null) : ($f['label'] ?? null));
        $fr = $f['label_fr'] ?? (is_array($f['label'] ?? null) ? ($f['label']['fr'] ?? null) : null);

        return array_filter([
            'code' => (string) $f['key'],
            'label' => array_filter(['en' => $en ?? (string) $f['key'], 'fr' => $fr], fn ($v) => $v !== null),
            'type' => strtolower((string) ($f['type'] ?? 'text')),
            'required' => (bool) ($f['required'] ?? false),
            'referral_values' => $f['referral_values'] ?? [],
            'referral_code' => $f['referral_code'] ?? null,
            'options' => $f['options'] ?? null,
            'source' => $f['source'] ?? null,
            'parent_field' => $f['parent_field'] ?? null,
            'other_allowed' => $f['other_allowed'] ?? $f['allow_other'] ?? null,
            'min' => $f['min'] ?? null,
            'max' => $f['max'] ?? null,
            'free_text_reason' => $f['free_text_reason'] ?? null,
            'visible_if' => $f['visible_if'] ?? $f['visible_when'] ?? null,
            'step' => $f['step'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** visible_if {field: value | [values] | {not_empty:true} | {contains:x}} — all conditions must hold. */
    public static function visible(array $q, array $answers): bool
    {
        foreach ((array) ($q['visible_if'] ?? []) as $field => $cond) {
            $v = $answers[$field] ?? null;
            $ok = match (true) {
                is_array($cond) && array_is_list($cond) => in_array($v, $cond, true),
                is_array($cond) && isset($cond['not_empty']) => $v !== null && $v !== '' && $v !== [],
                is_array($cond) && isset($cond['contains']) => is_array($v) ? in_array($cond['contains'], $v, true) : str_contains((string) $v, (string) $cond['contains']),
                default => $v === $cond,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
