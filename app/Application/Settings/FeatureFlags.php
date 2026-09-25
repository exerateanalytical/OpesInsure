<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Application\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-SEC-004 — feature flags scoped by environment / country / tenant /
 * branch / product. A row with a NULL dimension applies to every value of
 * that dimension. The most specific matching row wins; specificity weights
 * are branch 16 > tenant 8 > product 4 > country 2 > environment 1, so a
 * branch rule always beats a tenant rule, which beats a product-wide rule.
 * No matching row → $default (off unless the caller says otherwise).
 */
final class FeatureFlags
{
    private const WEIGHTS = ['branch_id' => 16, 'tenant_id' => 8, 'product_code' => 4, 'country_code' => 2, 'environment' => 1];

    public const DIMENSIONS = ['environment', 'country_code', 'tenant_id', 'branch_id', 'product_code'];

    public function __construct(private readonly AuditWriter $audit)
    {
    }

    /** @param array{environment?: ?string, country_code?: ?string, tenant_id?: ?string, branch_id?: ?string, product_code?: ?string} $scope */
    public function enabled(string $key, array $scope = [], bool $default = false): bool
    {
        $row = $this->match($key, $scope);

        return $row === null ? $default : (bool) $row->enabled;
    }

    /** @return array{key: string, enabled: bool, matched: ?array<string, mixed>} */
    public function explain(string $key, array $scope = [], bool $default = false): array
    {
        $row = $this->match($key, $scope);

        return ['key' => $key, 'enabled' => $row === null ? $default : (bool) $row->enabled, 'matched' => $row === null ? null : (array) $row];
    }

    /** @param array<string, mixed> $data */
    public function set(array $data, ?string $actorId): object
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{1,95}$/', (string) ($data['key'] ?? ''))) {
            throw ValidationException::withMessages(['key' => 'Flag key must be lower-case dotted (e.g. motor.quick_quote).']);
        }
        if (! empty($data['branch_id'])) {
            $branchTenant = DB::table('tenant_branches')->where('id', $data['branch_id'])->value('tenant_id');
            if ($branchTenant === null || (! empty($data['tenant_id']) && $branchTenant !== $data['tenant_id'])) {
                throw ValidationException::withMessages(['branch_id' => 'Branch does not belong to the tenant.']);
            }
            $data['tenant_id'] ??= $branchTenant;
        }
        $scope = [];
        foreach (self::DIMENSIONS as $d) {
            $scope[$d] = ($data[$d] ?? null) === '' ? null : ($data[$d] ?? null);
        }
        $existing = $this->exact($data['key'], $scope);
        $values = ['enabled' => (bool) $data['enabled'], 'description' => $data['description'] ?? $existing?->description, 'updated_by' => $actorId, 'updated_at' => now()];

        return DB::transaction(function () use ($data, $scope, $existing, $values) {
            if ($existing) {
                DB::table('feature_flags')->where('id', $existing->id)->update($values);
                $id = $existing->id;
            } else {
                $id = (string) Str::uuid();
                DB::table('feature_flags')->insert(['id' => $id, 'key' => $data['key'], ...$scope, ...$values, 'created_at' => now()]);
            }
            $this->audit->record('feature_flag.set', 'feature_flag', $id, ['key' => $data['key'], 'scope' => $scope, 'enabled' => $values['enabled'], 'previous' => $existing?->enabled]);

            return DB::table('feature_flags')->where('id', $id)->first();
        });
    }

    private function match(string $key, array $scope): ?object
    {
        $scope['environment'] ??= app()->environment();
        $q = DB::table('feature_flags')->where('key', $key);
        foreach (self::DIMENSIONS as $d) {
            $v = $scope[$d] ?? null;
            $q->where(fn ($w) => $v === null ? $w->whereNull($d) : $w->whereNull($d)->orWhere($d, $v));
        }
        $best = null;
        $bestScore = -1;
        foreach ($q->get() as $row) {
            $score = 0;
            foreach (self::WEIGHTS as $d => $w) {
                $score += $row->{$d} !== null ? $w : 0;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$row, $score];
            }
        }

        return $best;
    }

    private function exact(string $key, array $scope): ?object
    {
        $q = DB::table('feature_flags')->where('key', $key);
        foreach ($scope as $d => $v) {
            $v === null ? $q->whereNull($d) : $q->where($d, $v);
        }

        return $q->first();
    }
}
