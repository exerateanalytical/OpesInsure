<?php

declare(strict_types=1);

namespace App\Domain\Rules\Expression;

/**
 * REQ-RUL-002 — the one structured expression evaluator (PRE §19–22, PREP §3.2). Pure and deterministic:
 * no uploaded code, no clock, no I/O. Rules are data ({op,left,right} / {op:AND|OR,args} / {op:NOT,arg} / true);
 * operands are {fact:"a.b"}, {value:x} or {fn:NAME,args:[...]}. The caller supplies every fact, including
 * `context.today` (ISO date) when a rule needs "today".
 *
 * Three-valued logic: a comparison over a missing fact is UNKNOWN (null) — except EXISTS / NOT_EXISTS — so the
 * caller can say MORE_INFORMATION_REQUIRED instead of silently deciding. AND/OR/NOT follow Kleene logic.
 */
final class ExpressionEvaluator
{
    public const COMPARISONS = ['EQUAL', 'NOT_EQUAL', 'GT', 'GTE', 'LT', 'LTE', 'IN', 'NOT_IN', 'BETWEEN', 'CONTAINS', 'EXISTS', 'NOT_EXISTS'];

    public const LOGIC = ['AND', 'OR', 'NOT'];

    public const FUNCTIONS = ['AGE_AT', 'YEARS_BETWEEN', 'DAYS_BETWEEN', 'ADD', 'SUB', 'MUL', 'DIV_ROUND', 'MIN', 'MAX', 'COUNT', 'SUM', 'LOWER'];

    /** Legacy operator spellings (insurance_products.eligibility_rules v1) → canonical §20 operators. */
    public const ALIASES = ['EQUALS' => 'EQUAL', 'NOT_EQUALS' => 'NOT_EQUAL', 'EQ' => 'EQUAL', 'NE' => 'NOT_EQUAL'];

    /** @var array<string, mixed> facts referenced during the last evaluate() (path => value|null) */
    private array $referenced = [];

    /** @var list<string> */
    private array $missing = [];

    /**
     * @param  array<string, mixed>  $facts
     * @return array{result: bool|null, referenced: array<string, mixed>, missing: list<string>}
     */
    public function evaluate(mixed $expression, array $facts): array
    {
        $this->referenced = [];
        $this->missing = [];
        $result = $this->expr($expression, $facts);
        ksort($this->referenced);
        $missing = array_values(array_unique($this->missing));
        sort($missing);

        return ['result' => $result, 'referenced' => $this->referenced, 'missing' => $missing];
    }

    /** Looks a fact up by exact key first (flat legacy facts), then by dotted path. */
    public static function lookup(array $facts, string $path): mixed
    {
        if (array_key_exists($path, $facts)) {
            return $facts[$path];
        }
        $cur = $facts;
        foreach (explode('.', $path) as $seg) {
            if (! is_array($cur) || ! array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }

        return $cur;
    }

    private function expr(mixed $e, array $facts): ?bool
    {
        if ($e === true) {
            return true;
        }
        if ($e === false) {
            return false;
        }
        if (! is_array($e) || ! isset($e['op'])) {
            throw new InvalidExpression('Expression must be true or an object with op.');
        }
        $op = self::ALIASES[$e['op']] ?? $e['op'];

        return match ($op) {
            'AND' => $this->all($e['args'] ?? [], $facts),
            'OR' => $this->any($e['args'] ?? [], $facts),
            'NOT' => ($r = $this->expr($e['arg'] ?? null, $facts)) === null ? null : ! $r,
            default => $this->compare($op, $e, $facts),
        };
    }

    private function all(array $args, array $facts): ?bool
    {
        $unknown = false;
        foreach ($args as $a) {
            $r = $this->expr($a, $facts);
            if ($r === false) {
                return false;
            }
            $unknown = $unknown || $r === null;
        }

        return $unknown ? null : true;
    }

    private function any(array $args, array $facts): ?bool
    {
        $unknown = false;
        foreach ($args as $a) {
            $r = $this->expr($a, $facts);
            if ($r === true) {
                return true;
            }
            $unknown = $unknown || $r === null;
        }

        return $unknown ? null : false;
    }

    private function compare(string $op, array $e, array $facts): ?bool
    {
        if (! in_array($op, self::COMPARISONS, true)) {
            throw new InvalidExpression("Unknown operator [{$op}].");
        }
        $left = $this->operand($e['left'] ?? null, $facts);
        if ($op === 'EXISTS') {
            return $left !== null && $left !== '' && $left !== [];
        }
        if ($op === 'NOT_EXISTS') {
            return $left === null || $left === '' || $left === [];
        }
        $right = $this->operand($e['right'] ?? null, $facts);
        if ($left === null || $right === null) {
            return null;
        }

        return match ($op) {
            'EQUAL' => self::equals($left, $right),
            'NOT_EQUAL' => ($r = self::equals($left, $right)) === null ? null : ! $r,
            'GT' => ($c = self::cmp($left, $right)) === null ? null : $c > 0,
            'GTE' => ($c = self::cmp($left, $right)) === null ? null : $c >= 0,
            'LT' => ($c = self::cmp($left, $right)) === null ? null : $c < 0,
            'LTE' => ($c = self::cmp($left, $right)) === null ? null : $c <= 0,
            'IN' => $this->in($left, $right),
            'NOT_IN' => ($r = $this->in($left, $right)) === null ? null : ! $r,
            'BETWEEN' => $this->between($left, $right, (bool) ($e['inclusive'] ?? true)),
            'CONTAINS' => $this->contains($left, $right),
        };
    }

    private function in(mixed $left, mixed $right): ?bool
    {
        if (! is_array($right)) {
            throw new InvalidExpression('IN / NOT_IN need a list on the right.');
        }
        foreach ((array) $left as $l) { // a multi-select answer is IN when any selected value is listed
            foreach ($right as $r) {
                if (self::equals($l, $r) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    private function between(mixed $left, mixed $right, bool $inclusive): ?bool
    {
        if (! is_array($right) || count($right) !== 2 || ! array_is_list($right)) {
            throw new InvalidExpression('BETWEEN needs [low, high].');
        }
        $lo = self::cmp($left, $right[0]);
        $hi = self::cmp($left, $right[1]);
        if ($lo === null || $hi === null) {
            return null;
        }

        return $inclusive ? ($lo >= 0 && $hi <= 0) : ($lo > 0 && $hi < 0);
    }

    private function contains(mixed $left, mixed $right): ?bool
    {
        if (is_array($left)) {
            foreach ($left as $l) {
                if (self::equals($l, $right) === true) {
                    return true;
                }
            }

            return false;
        }
        if (is_string($left) && is_scalar($right)) {
            return str_contains($left, (string) $right);
        }

        return null;
    }

    private function operand(mixed $o, array $facts): mixed
    {
        if (! is_array($o)) {
            throw new InvalidExpression('Operand must be {fact}, {value} or {fn}.');
        }
        if (array_key_exists('fact', $o)) {
            $v = self::lookup($facts, (string) $o['fact']);
            $this->referenced[(string) $o['fact']] = $v;
            if ($v === null) {
                $this->missing[] = (string) $o['fact'];
            }

            return $v;
        }
        if (array_key_exists('value', $o)) {
            return $o['value'];
        }
        if (isset($o['fn'])) {
            return $this->fn((string) $o['fn'], array_map(fn ($a) => $this->operand($a, $facts), $o['args'] ?? []));
        }
        throw new InvalidExpression('Operand must be {fact}, {value} or {fn}.');
    }

    private function fn(string $fn, array $args): mixed
    {
        if (! in_array($fn, self::FUNCTIONS, true)) {
            throw new InvalidExpression("Unknown function [{$fn}].");
        }
        if (in_array(null, $args, true)) {
            return null;
        }

        return match ($fn) {
            'AGE_AT', 'YEARS_BETWEEN' => self::dates($args, fn (\DateTimeImmutable $a, \DateTimeImmutable $b) => (int) $a->diff($b)->format('%r%y')),
            'DAYS_BETWEEN' => self::dates($args, fn (\DateTimeImmutable $a, \DateTimeImmutable $b) => (int) $a->diff($b)->format('%r%a')),
            'ADD' => array_sum(self::numbers($args)),
            'SUB' => ($n = self::numbers($args)) ? array_shift($n) - array_sum($n) : 0,
            'MUL' => array_product(self::numbers($args)),
            'DIV_ROUND' => ($n = self::numbers($args)) && count($n) === 2 && (float) $n[1] !== 0.0 ? (int) round($n[0] / $n[1]) : null,
            'MIN' => ($n = self::numbers(self::flatten($args))) ? min($n) : null,
            'MAX' => ($n = self::numbers(self::flatten($args))) ? max($n) : null,
            'COUNT' => count(self::flatten($args)),
            'SUM' => array_sum(self::numbers(self::flatten($args))),
            'LOWER' => is_string($args[0] ?? null) ? mb_strtolower($args[0]) : null,
        };
    }

    private static function dates(array $args, \Closure $f): ?int
    {
        if (count($args) !== 2) {
            throw new InvalidExpression('Date functions take two dates.');
        }
        $a = self::date($args[0]);
        $b = self::date($args[1]);

        return $a && $b ? $f($a, $b) : null;
    }

    private static function date(mixed $v): ?\DateTimeImmutable
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', substr($v, 0, 10), new \DateTimeZone('UTC')) ?: null;
    }

    private static function flatten(array $args): array
    {
        $out = [];
        array_walk_recursive($args, function ($v) use (&$out) { $out[] = $v; });

        return $out;
    }

    /** @return list<int|float> */
    private static function numbers(array $args): array
    {
        foreach ($args as $a) {
            if (! is_numeric($a)) {
                throw new InvalidExpression('Arithmetic needs numbers.');
            }
        }

        return array_map(fn ($a) => is_string($a) ? $a + 0 : $a, array_values($args));
    }

    public static function equals(mixed $a, mixed $b): ?bool
    {
        if (is_bool($a) || is_bool($b)) {
            return is_bool($a) && is_bool($b) ? $a === $b : false;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        if (is_array($a) || is_array($b)) {
            return $a === $b;
        }

        return (string) $a === (string) $b;
    }

    /** -1/0/1, or null when the types are not comparable (never guesses an order). */
    public static function cmp(mixed $a, mixed $b): ?int
    {
        if (is_numeric($a) && is_numeric($b) && ! is_bool($a) && ! is_bool($b)) {
            return (float) $a <=> (float) $b;
        }
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0; // ISO dates order lexically
        }

        return null;
    }
}
