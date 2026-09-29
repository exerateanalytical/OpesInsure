<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DEV-014 — API versions & changelog. `api:changelog` diffs the published OpenAPI document (docs/api/openapi.json,
 * produced by `api:openapi`) against the operations already recorded in api_changelog_entries: new operations are
 * ADDED, vanished ones REMOVED (breaking), operations whose definition fingerprint moved are CHANGED (breaking when
 * the partner scopes changed or a parameter disappeared). The very first run records a BASELINE only.
 */
final class ApiChangelogService
{
    /** @param array<string, mixed> $doc @return array{added:int, changed:int, removed:int, baseline:int} */
    public function sync(array $doc, ?string $at = null): array
    {
        $at ??= now()->toIso8601String();
        $current = $this->operations($doc);
        $known = $this->known();
        $baseline = $known === [];
        $version = (string) ($doc['info']['version'] ?? 'v1');
        $n = ['added' => 0, 'changed' => 0, 'removed' => 0, 'baseline' => 0];
        $rows = [];

        foreach ($current as $key => $op) {
            $prev = $known[$key] ?? null;
            if ($prev === null) {
                $type = $baseline ? 'BASELINE' : 'ADDED';
                $rows[] = $this->row($version, $key, $type, false, $op, $baseline ? null : 'New operation.', $at);
                $n[$baseline ? 'baseline' : 'added']++;
            } elseif ($prev->fingerprint !== $op['fingerprint']) {
                $breaking = $prev->scopes !== $op['scopes'] || array_diff($prev->params, $op['params']) !== [];
                $rows[] = $this->row($version, $key, 'CHANGED', $breaking, $op, $breaking ? 'Definition changed (scopes or parameters).' : 'Definition changed.', $at);
                $n['changed']++;
            }
        }
        foreach ($known as $key => $prev) {
            if (! isset($current[$key])) {
                $rows[] = $this->row($version, $key, 'REMOVED', true, ['fingerprint' => null, 'partner' => $prev->partner, 'scopes' => $prev->scopes, 'params' => []], 'Operation removed.', $at);
                $n['removed']++;
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('api_changelog_entries')->insert($chunk);
        }

        return $n;
    }

    /** Partner-visible changelog (BASELINE rows excluded). @return list<array<string, mixed>> */
    public function entries(bool $partnerOnly = true, int $limit = 300): array
    {
        return DB::table('api_changelog_entries')->where('change_type', '!=', 'BASELINE')->when($partnerOnly, fn ($q) => $q->where('partner_facing', true))
            ->orderByDesc('detected_at')->limit($limit)->get(['detected_at', 'api_version', 'change_type', 'operation', 'breaking', 'summary'])->map(fn ($r) => (array) $r)->all();
    }

    /** @return array<string, array{fingerprint:string, partner:bool, scopes:string, params:list<string>}> */
    private function operations(array $doc): array
    {
        $out = [];
        foreach ((array) ($doc['paths'] ?? []) as $path => $methods) {
            foreach ((array) $methods as $method => $op) {
                $op = (array) $op;
                unset($op['summary']); // controller class names are not part of the contract
                $scopes = isset($op['x-partner-scopes']) ? implode(',', (array) $op['x-partner-scopes']) : '';
                $params = array_values(array_map(fn ($p) => (string) ($p['name'] ?? ''), (array) ($op['parameters'] ?? [])));
                $params = array_merge($params, array_keys((array) ($op['requestBody']['content']['application/json']['schema']['properties'] ?? [])));
                $out[strtoupper((string) $method).' '.$path] = ['fingerprint' => hash('sha256', json_encode($this->sorted($op))), 'partner' => isset($op['x-partner-scopes']), 'scopes' => $scopes, 'params' => $params];
            }
        }

        return $out;
    }

    /** Latest recorded state per operation, excluding removed ones. @return array<string, object> */
    private function known(): array
    {
        $out = [];
        foreach (DB::table('api_changelog_entries')->orderBy('detected_at')->orderBy('created_at')->cursor() as $e) {
            if ($e->change_type === 'REMOVED') {
                unset($out[$e->operation]);

                continue;
            }
            $c = (array) json_decode((string) $e->contract, true);
            $out[$e->operation] = (object) ['fingerprint' => $e->fingerprint, 'partner' => (bool) $e->partner_facing,
                'scopes' => (string) ($c['scopes'] ?? ''), 'params' => array_values((array) ($c['params'] ?? []))];
        }

        return $out;
    }

    private function sorted(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map(fn ($x) => $this->sorted($x), $v);
    }

    /** @param array{fingerprint:?string, partner:bool, scopes:string, params:list<string>} $op */
    private function row(string $version, string $key, string $type, bool $breaking, array $op, ?string $summary, string $at): array
    {
        return ['id' => (string) Str::uuid(), 'api_version' => $version, 'operation' => Str::limit($key, 255, ''), 'change_type' => $type, 'breaking' => $breaking,
            'partner_facing' => $op['partner'], 'fingerprint' => $op['fingerprint'], 'contract' => json_encode(['scopes' => $op['scopes'], 'params' => $op['params']]), 'summary' => $summary, 'source' => 'OPENAPI_DIFF',
            'detected_at' => $at, 'created_at' => now(), 'updated_at' => now()];
    }
}
