<?php

declare(strict_types=1);

namespace App\Application\Configuration;

use App\Models\ConfigurationChangeSet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-SET-004 — configuration inheritance & precedence (SCF governing rules):
 * Platform → Insurer → Broker agreement → Broker internal → Branch → User, where a lower level may only be
 * more restrictive and only keys marked overridable at that level can be set (config/configuration_inheritance.php).
 *
 * Writes go through the existing ConfigurationGovernanceService (Draft → Review → Approved → Published, maker-checker);
 * this class validates on draft, re-validates on publish (applier for config_type `configuration.override`) and
 * resolves the effective value with a trace. The resolver also enforces the rule at read time, so an override that
 * became less restrictive after its parent tightened is ignored rather than applied.
 */
final class ConfigurationInheritance
{
    public const CONFIG_TYPE = 'configuration.override';

    public function __construct(private readonly ConfigurationGovernanceService $governance) {}

    /** @return list<string> */
    public function levels(): array
    {
        return config('configuration_inheritance.levels');
    }

    /** @return array<string, array{default:mixed, restriction:string, overridable_at:list<string>}> */
    public function keys(): array
    {
        return config('configuration_inheritance.keys');
    }

    /**
     * @param  array<string, string>  $scope  level => scope id for the levels that apply (PLATFORM needs none)
     * @return array{key:string, value:mixed, source_level:string, trace:list<array{level:string, scope_id:?string, value:mixed, outcome:string}>}
     */
    public function resolve(string $key, array $scope = [], ?string $on = null, ?string $untilLevel = null): array
    {
        $policy = $this->policy($key);
        $on ??= now()->toDateString();
        $value = $policy['default'];
        $source = 'DEFAULT';
        $trace = [['level' => 'DEFAULT', 'scope_id' => null, 'value' => $value, 'outcome' => 'APPLIED']];

        foreach ($this->levels() as $level) {
            if ($level === $untilLevel) {
                break;
            }
            $scopeId = $level === 'PLATFORM' ? null : ($scope[$level] ?? null);
            if ($level !== 'PLATFORM' && $scopeId === null) {
                continue;
            }
            $override = $this->activeOverride($key, $level, $scopeId, $on);
            if ($override === null) {
                continue;
            }
            $candidate = json_decode((string) $override->value, true)['value'] ?? null;
            $outcome = match (true) {
                ! in_array($level, $policy['overridable_at'], true) => 'IGNORED_NOT_OVERRIDABLE',
                $level !== 'PLATFORM' && ! $this->isAllowed($policy, $level, $value, $candidate) => 'IGNORED_LESS_RESTRICTIVE',
                default => 'APPLIED',
            };
            $trace[] = ['level' => $level, 'scope_id' => $scopeId, 'value' => $candidate, 'outcome' => $outcome];
            if ($outcome === 'APPLIED') {
                [$value, $source] = [$candidate, $level];
            }
        }

        return ['key' => $key, 'value' => $value, 'source_level' => $source, 'trace' => $trace];
    }

    /** Throws when $value may not be set for $key at $level given the inherited value from the ancestors in $scope. */
    public function assertAllowed(string $key, string $level, mixed $value, array $scope = [], ?string $on = null): void
    {
        $policy = $this->policy($key);
        if (! in_array($level, $this->levels(), true)) {
            throw ValidationException::withMessages(['scope_level' => 'Unknown configuration level.']);
        }
        if (! in_array($level, $policy['overridable_at'], true)) {
            throw ValidationException::withMessages(['config_key' => "{$key} cannot be overridden at {$level}."]);
        }
        $this->assertShape($policy, $value);
        if ($level === 'PLATFORM') {
            return;
        }
        $inherited = $this->resolve($key, $scope, $on, $level)['value'];
        if (! $this->isAllowed($policy, $level, $inherited, $value)) {
            throw ValidationException::withMessages(['value' => "{$key} at {$level} must be at least as restrictive as the inherited value."]);
        }
    }

    /** Draft an override through configuration governance after validating it. */
    public function draftOverride(User $maker, string $key, string $level, ?string $scopeId, mixed $value, string $reason, array $ancestors = [], ?string $effectiveFrom = null): ConfigurationChangeSet
    {
        if (($level === 'PLATFORM') !== ($scopeId === null)) {
            throw ValidationException::withMessages(['scope_id' => 'PLATFORM overrides have no scope id; every other level needs one.']);
        }
        $this->assertAllowed($key, $level, $value, $ancestors, $effectiveFrom);

        return $this->governance->draft($maker, self::CONFIG_TYPE, $this->changeKey($key, $level, $scopeId),
            ['config_key' => $key, 'scope_level' => $level, 'scope_id' => $scopeId, 'value' => $value, 'ancestors' => $ancestors], $reason, $effectiveFrom);
    }

    /** Applier for published change sets (registered on ConfigurationGovernanceService). */
    public function apply(ConfigurationChangeSet $cs): void
    {
        $p = (array) $cs->proposed_value;
        $effective = (string) ($cs->effective_from?->toDateString() ?? now()->toDateString());
        $this->assertAllowed($p['config_key'], $p['scope_level'], $p['value'], (array) ($p['ancestors'] ?? []), $effective);

        DB::table('configuration_overrides')->where(['config_key' => $p['config_key'], 'scope_level' => $p['scope_level'], 'status' => 'ACTIVE'])
            ->where(fn ($q) => $p['scope_id'] === null ? $q->whereNull('scope_id') : $q->where('scope_id', $p['scope_id']))
            ->where('effective_from', '<=', $effective)
            ->update(['status' => 'SUPERSEDED', 'effective_until' => $effective, 'updated_at' => now()]);
        DB::table('configuration_overrides')->insert([
            'id' => (string) Str::uuid(), 'scope_level' => $p['scope_level'], 'scope_id' => $p['scope_id'], 'config_key' => $p['config_key'],
            'value' => json_encode(['value' => $p['value']]), 'effective_from' => $effective, 'status' => 'ACTIVE', 'change_set_id' => $cs->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function changeKey(string $key, string $level, ?string $scopeId): string
    {
        return $level.':'.($scopeId ?? '*').':'.$key;
    }

    private function policy(string $key): array
    {
        return $this->keys()[$key] ?? throw ValidationException::withMessages(['config_key' => "{$key} is not an inheritable configuration key."]);
    }

    private function activeOverride(string $key, string $level, ?string $scopeId, string $on): ?object
    {
        return DB::table('configuration_overrides')->where(['config_key' => $key, 'scope_level' => $level])
            ->where(fn ($q) => $scopeId === null ? $q->whereNull('scope_id') : $q->where('scope_id', $scopeId))
            ->where('effective_from', '<=', $on)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $on))
            ->orderByDesc('effective_from')->orderByDesc('created_at')->first();
    }

    private function isAllowed(array $policy, string $level, mixed $inherited, mixed $candidate): bool
    {
        if ($inherited === null && $policy['restriction'] !== 'fixed') {
            return true;
        }

        return match ($policy['restriction']) {
            'max' => is_numeric($candidate) && $candidate <= $inherited,
            'min' => is_numeric($candidate) && $candidate >= $inherited,
            'subset' => is_array($candidate) && array_diff($candidate, (array) $inherited) === [],
            'flag' => is_bool($candidate) && ($inherited === true || $candidate === false),
            'fixed' => in_array($level, $policy['overridable_at'], true),
            default => false,
        };
    }

    private function assertShape(array $policy, mixed $value): void
    {
        $ok = match ($policy['restriction']) {
            'max', 'min' => is_int($value) || is_float($value),
            'subset' => is_array($value) && array_is_list($value),
            'flag' => is_bool($value),
            default => true,
        };
        if (! $ok) {
            throw ValidationException::withMessages(['value' => 'Value has the wrong type for this key.']);
        }
    }
}
