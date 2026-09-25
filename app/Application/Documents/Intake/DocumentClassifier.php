<?php

declare(strict_types=1);

namespace App\Application\Documents\Intake;

use App\Application\Documents\Engine\DocumentRegister;
use Illuminate\Support\Str;

/**
 * REQ-DOC-010 classification against the owner's document register (DOC-001..DOC-220 + subtypes/evidence).
 * Deterministic, explainable: a declared code / type id / alias is an exact match (confidence 1.0);
 * otherwise the filename + extracted text are matched on the words of each type's EN/FR name.
 * A suggestion never classifies on its own: staff confirm it (or it goes to a DOC_INTAKE_EXCEPTION case).
 */
final class DocumentClassifier
{
    public const SUGGEST_THRESHOLD = 0.6;

    private const STOP = ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'of', 'the', 'and', 'for', 'a', 'd', 'l', 'pdf', 'jpg', 'jpeg', 'png', 'scan', 'doc'];

    public function __construct(private DocumentRegister $register) {}

    /** @return array<string, mixed>|null the register entry for a code, alias or type id */
    public function resolve(?string $codeOrId): ?array
    {
        if ($codeOrId === null || trim($codeOrId) === '') {
            return null;
        }
        $key = strtoupper(trim($codeOrId));
        if ($t = $this->register->type($key)) {
            return $t;
        }
        foreach ($this->register->types() as $t) {
            if (strtoupper((string) ($t['id'] ?? '')) === $key) {
                return $t;
            }
        }

        return null;
    }

    /** @return array{code: string, type_id: ?string, confidence: float, method: string}|null */
    public function classify(?string $declared, ?string $filename, ?string $text = null): ?array
    {
        if ($t = $this->resolve($declared)) {
            return ['code' => $t['code'], 'type_id' => $t['id'] ?? null, 'confidence' => 1.0, 'method' => 'DECLARED'];
        }
        $haystack = $this->words(pathinfo((string) $filename, PATHINFO_FILENAME).' '.(string) $text);
        if ($haystack === []) {
            return null;
        }
        $best = null;
        foreach ($this->register->types() as $t) {
            foreach ([$t['name_en'] ?? '', $t['name_fr'] ?? '', str_replace('_', ' ', $t['code'])] as $name) {
                $needle = $this->words((string) $name);
                if (count($needle) < 2) {
                    continue;
                }
                $score = count(array_intersect($needle, $haystack)) / count($needle);
                if ($best === null || $score > $best['confidence'] || ($score === $best['confidence'] && count($needle) > $best['len'])) {
                    $best = ['code' => $t['code'], 'type_id' => $t['id'] ?? null, 'confidence' => round($score, 3), 'method' => 'NAME_MATCH', 'len' => count($needle)];
                }
            }
        }
        if ($best === null || $best['confidence'] < self::SUGGEST_THRESHOLD) {
            return null;
        }
        unset($best['len']);

        return $best;
    }

    /** @return list<string> */
    private function words(string $s): array
    {
        $s = Str::lower(Str::ascii($s));
        $parts = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($parts, fn ($w) => strlen($w) > 1 && ! in_array($w, self::STOP, true))));
    }
}
