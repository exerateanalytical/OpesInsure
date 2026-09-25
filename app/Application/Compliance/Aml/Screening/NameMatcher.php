<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening;

use Illuminate\Support\Str;

/**
 * Agent E8 — REQ-AML-001 fuzzy name matching with an explainable score (0..1).
 *
 * normalise: transliterate to ASCII (accents, Cyrillic, Greek, Arabic... via Str::ascii), lower-case, punctuation → space,
 * drop honorifics, collapse spaces. Tokens are compared order-independently: each token of the shorter name is paired
 * with its best token of the longer one (Levenshtein similarity; an equal metaphone key scores at least 0.85; an initial
 * matching a token's first letter scores 0.5). score = length-weighted mean of the pair scores × (0.8 + 0.2 × coverage),
 * coverage = shorter token count / longer token count. The explanation lists every pair and each factor.
 */
final class NameMatcher
{
    private const HONORIFICS = ['mr', 'mrs', 'ms', 'miss', 'dr', 'prof', 'sir', 'madam', 'mme', 'mlle', 'm', 'hon', 'rev', 'sheikh', 'chief'];

    public function normalize(string $name): string
    {
        $s = Str::lower(Str::ascii($name));
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
        $tokens = array_values(array_filter(explode(' ', $s), fn ($t) => $t !== '' && ! in_array($t, self::HONORIFICS, true)));

        return implode(' ', $tokens);
    }

    /** @return list<string> */
    public function tokens(string $name): array
    {
        $n = $this->normalize($name);

        return $n === '' ? [] : explode(' ', $n);
    }

    /** @return array{score: float, normalized_query: string, normalized_candidate: string, pairs: list<array{query: string, candidate: ?string, similarity: float, method: string}>, coverage: float, token_score: float} */
    public function compare(string $query, string $candidate): array
    {
        $q = $this->tokens($query);
        $c = $this->tokens($candidate);
        $base = ['normalized_query' => implode(' ', $q), 'normalized_candidate' => implode(' ', $c)];
        if ($q === [] || $c === []) {
            return ['score' => 0.0] + $base + ['pairs' => [], 'coverage' => 0.0, 'token_score' => 0.0];
        }
        if ($base['normalized_query'] === $base['normalized_candidate']) {
            return ['score' => 1.0] + $base + ['pairs' => array_map(fn ($t) => ['query' => $t, 'candidate' => $t, 'similarity' => 1.0, 'method' => 'EXACT'], $q), 'coverage' => 1.0, 'token_score' => 1.0];
        }
        [$short, $long, $swapped] = count($q) <= count($c) ? [$q, $c, false] : [$c, $q, true];
        $available = $long;
        $pairs = [];
        $weighted = 0.0;
        $weights = 0;
        foreach ($short as $tok) {
            $best = ['idx' => null, 'sim' => 0.0, 'method' => 'NONE'];
            foreach ($available as $i => $cand) {
                [$sim, $method] = $this->tokenSimilarity($tok, $cand);
                if ($sim > $best['sim']) {
                    $best = ['idx' => $i, 'sim' => $sim, 'method' => $method];
                }
            }
            $matched = $best['idx'] !== null ? $available[$best['idx']] : null;
            if ($best['idx'] !== null) {
                unset($available[$best['idx']]);
            }
            $w = max(strlen($tok), strlen((string) $matched));
            $weighted += $best['sim'] * $w;
            $weights += $w;
            $pairs[] = $swapped
                ? ['query' => (string) $matched, 'candidate' => $tok, 'similarity' => round($best['sim'], 4), 'method' => $best['method']]
                : ['query' => $tok, 'candidate' => $matched, 'similarity' => round($best['sim'], 4), 'method' => $best['method']];
        }
        $tokenScore = $weights > 0 ? $weighted / $weights : 0.0;
        $coverage = count($short) / count($long);
        $score = round(min(1.0, $tokenScore * (0.8 + 0.2 * $coverage)), 4);

        return ['score' => $score] + $base + ['pairs' => $pairs, 'coverage' => round($coverage, 4), 'token_score' => round($tokenScore, 4)];
    }

    /** @return array{0: float, 1: string} */
    private function tokenSimilarity(string $a, string $b): array
    {
        if ($a === $b) {
            return [1.0, 'EXACT'];
        }
        if (strlen($a) === 1 || strlen($b) === 1) {
            return $a[0] === $b[0] ? [0.5, 'INITIAL'] : [0.0, 'NONE'];
        }
        $lev = 1 - levenshtein($a, $b) / max(strlen($a), strlen($b));
        if (metaphone($a) !== '' && metaphone($a) === metaphone($b) && $lev < 0.85) {
            return [0.85, 'PHONETIC'];
        }

        return [max(0.0, $lev), 'EDIT_DISTANCE'];
    }
}
