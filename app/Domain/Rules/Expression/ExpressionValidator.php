<?php

declare(strict_types=1);

namespace App\Domain\Rules\Expression;

/**
 * REQ-RUL-002 — structural validation of a rule condition before it can be saved (PREP §3.2 schema).
 * Only the §20 operators and the whitelisted functions are accepted; values are JSON scalars or lists of
 * scalars. There is no way to express code, regexes or external calls.
 */
final class ExpressionValidator
{
    public const MAX_DEPTH = 24;

    public const MAX_NODES = 500;

    private int $nodes = 0;

    /** @return list<string> errors (empty = valid) */
    public function validate(mixed $expression): array
    {
        $this->nodes = 0;
        $errors = [];
        $this->expr($expression, '$', 0, $errors);

        return $errors;
    }

    private function expr(mixed $e, string $path, int $depth, array &$errors): void
    {
        if (++$this->nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            $errors[] = "{$path}: expression too large or too deep.";

            return;
        }
        if ($e === true) {
            return;
        }
        if (! is_array($e) || ! isset($e['op']) || ! is_string($e['op'])) {
            $errors[] = "{$path}: expected true or an object with op.";

            return;
        }
        $op = ExpressionEvaluator::ALIASES[$e['op']] ?? $e['op'];
        if ($op === 'AND' || $op === 'OR') {
            if (! isset($e['args']) || ! is_array($e['args']) || $e['args'] === [] || ! array_is_list($e['args'])) {
                $errors[] = "{$path}: {$op} needs a non-empty args list.";

                return;
            }
            foreach ($e['args'] as $i => $a) {
                $this->expr($a, "{$path}.args[{$i}]", $depth + 1, $errors);
            }

            return;
        }
        if ($op === 'NOT') {
            $this->expr($e['arg'] ?? null, "{$path}.arg", $depth + 1, $errors);

            return;
        }
        if (! in_array($op, ExpressionEvaluator::COMPARISONS, true)) {
            $errors[] = "{$path}: unknown operator {$op}.";

            return;
        }
        $this->operand($e['left'] ?? null, "{$path}.left", $depth + 1, $errors);
        if (in_array($op, ['EXISTS', 'NOT_EXISTS'], true)) {
            return;
        }
        if (! array_key_exists('right', $e)) {
            $errors[] = "{$path}: {$op} needs right.";

            return;
        }
        $this->operand($e['right'], "{$path}.right", $depth + 1, $errors);
        $rv = is_array($e['right']) && array_key_exists('value', $e['right']) ? $e['right']['value'] : null;
        if (in_array($op, ['IN', 'NOT_IN'], true) && isset($e['right']['value']) && ! is_array($rv)) {
            $errors[] = "{$path}: {$op} needs a list value.";
        }
        if ($op === 'BETWEEN' && isset($e['right']['value']) && (! is_array($rv) || count($rv) !== 2 || ! array_is_list($rv))) {
            $errors[] = "{$path}: BETWEEN needs [low, high].";
        }
    }

    private function operand(mixed $o, string $path, int $depth, array &$errors): void
    {
        if (++$this->nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            $errors[] = "{$path}: expression too large or too deep.";

            return;
        }
        if (! is_array($o)) {
            $errors[] = "{$path}: operand must be {fact}, {value} or {fn}.";

            return;
        }
        if (array_key_exists('fact', $o)) {
            if (! is_string($o['fact']) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)*$/', $o['fact'])) {
                $errors[] = "{$path}: invalid fact path.";
            }

            return;
        }
        if (array_key_exists('value', $o)) {
            $v = $o['value'];
            $ok = $v === null || is_scalar($v) || (is_array($v) && array_is_list($v) && array_filter($v, fn ($x) => ! is_scalar($x)) === []);
            if (! $ok) {
                $errors[] = "{$path}: value must be a scalar or a list of scalars.";
            }

            return;
        }
        if (isset($o['fn'])) {
            if (! in_array($o['fn'], ExpressionEvaluator::FUNCTIONS, true)) {
                $errors[] = "{$path}: unknown function.";

                return;
            }
            foreach (($o['args'] ?? []) as $i => $a) {
                $this->operand($a, "{$path}.args[{$i}]", $depth + 1, $errors);
            }

            return;
        }
        $errors[] = "{$path}: operand must be {fact}, {value} or {fn}.";
    }
}
