<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Application\DocumentCatalogue\DetailedFieldSourceMap;
use App\Models\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * D2 mapped field rules (DetailedFieldSourceMap, MAPPED_PLATFORM_SOURCE): the values the platform already holds for
 * the issuance context. Read-only; a key whose source is empty is omitted (the shell then prints no row: never a blank
 * placeholder). Only new issuance reads these — issued documents keep their frozen snapshot.
 *
 * Two layers:
 *  1. explicit readers for derived values (policy term, coverage flags, renewal due date ...);
 *  2. a generic reader driven by the map's own `table.column` source: the row is anchored on the issuance context
 *     only — the policy / claim / proposal / quote / offer / payment / transaction / party / provider in play, and
 *     the source ids a non-policy flow passes in ctx['sources'] (DocumentEngine::issueProviderDocument). A table that
 *     cannot be anchored to this document is never read "by latest row": the key stays unresolved.
 */
final class MappedFieldValues
{
    /** Keys never printed from the generic reader (privacy-gated, hashes, or owned by Zones A/B/F). */
    private const SKIP = ['verification.token', 'party.date_of_birth', 'confidentiality.class', 'document.number', 'document.issued_at', 'issuer.legal_name'];

    /** Columns never printed (secrets, raw identity). */
    private const SECRET_COLUMNS = '/(token|hash|secret|password|legal_identity|encrypted)/';

    /** @var array<string, array<int, string>> */
    private static array $columns = [];

    /** @return array<string, mixed> */
    public static function resolve(Policy $policy, array $ctx): array
    {
        $v = self::explicit($policy, $ctx);
        $anchors = self::anchors($policy, $ctx);
        $currency = (string) ($ctx['currency'] ?? $policy->currency ?? 'XAF');
        foreach (DetailedFieldSourceMap::MAP as [$key, $source]) {
            if ($key === null || isset($v[$key]) || in_array($key, self::SKIP, true)) {
                continue;
            }
            try {
                $val = self::read((string) $source, $anchors, $currency);
            } catch (Throwable) {
                $val = null;
            }
            if ($val !== null && $val !== '' && $val !== []) {
                $v[$key] = $val;
            }
        }

        return array_filter($v, fn ($x) => $x !== null && $x !== '' && $x !== []);
    }

    /** @return array<string, string> anchor column => id */
    public static function anchors(Policy $policy, array $ctx): array
    {
        $offer = $policy->proposal?->offer;
        $claim = $ctx['claim'] ?? null;
        $tx = $ctx['transaction'] ?? null;
        $payment = $ctx['payment'] ?? null;
        $a = array_filter([
            'policy_id' => $policy->exists ? $policy->id : null,
            'proposal_id' => $policy->proposal_id ?? $offer?->proposal?->id ?? null,
            'quote_offer_id' => $offer?->id,
            'quote_id' => $offer?->quote_id,
            'party_id' => $policy->party_id,
            'carrier_id' => $policy->carrier_id ?? ($ctx['carrier_id'] ?? null),
            'product_id' => $offer?->product_id,
            'claim_id' => is_object($claim) ? $claim->id : null,
            'policy_transaction_id' => is_object($tx) ? $tx->id : null,
            'payment_intent_id' => is_object($payment) ? $payment->id : ($policy->payment_intent_id ?? null),
            'provider_profile_id' => $ctx['provider_id'] ?? null,
            'tenant_branch_id' => $ctx['branch_id'] ?? null,
        ]);
        foreach ((array) ($ctx['sources'] ?? []) as $col => $id) {
            if (is_string($col) && str_ends_with($col, '_id') && is_scalar($id) && $id !== '') {
                $a[$col] = (string) $id;
            }
        }
        // Rows reachable from an anchored row by its own foreign keys (e.g. a preauth's member / contract).
        foreach (['health_preauthorization_id' => 'health_preauthorizations', 'provider_contract_id' => 'provider_contracts', 'health_provider_claim_id' => 'health_provider_claims'] as $col => $table) {
            if (isset($a[$col]) && Schema::hasTable($table)) {
                $row = (array) DB::table($table)->where('id', $a[$col])->first();
                foreach ($row as $k => $val) {
                    if (str_ends_with($k, '_id') && is_string($val) && $val !== '' && ! isset($a[$k]) && $k !== 'tenant_id') {
                        $a[$k] = $val;
                    }
                }
            }
        }

        return $a;
    }

    /** Parses one map source ("t.c", "t.c1, c2", "t.fk -> t2.c", "t", "sum(t.c)", alternatives split by " / ") and reads it. */
    public static function read(string $source, array $anchors, string $currency): mixed
    {
        foreach (preg_split('#\s+/\s+#', $source) as $alt) {
            $sum = (bool) preg_match('/^\s*sum\(([\w.]+)\)/', $alt, $m);
            $alt = $sum ? $m[1] : trim((string) preg_replace('/\s*\(.*$/', '', $alt)); // drop "(filter)" notes
            $val = null;
            if (preg_match('/^(\w+)\.(\w+)\s*->\s*(\w+)\.(\w+)$/', $alt, $m)) {
                $fks = self::values($m[1], [$m[2]], $anchors, $currency, false, true);
                if ($fks && Schema::hasTable($m[3]) && self::hasColumn($m[3], $m[4])) {
                    $val = self::join(DB::table($m[3])->whereIn('id', array_slice($fks, 0, 20))->pluck($m[4])->all(), $m[4], $currency);
                }
            } elseif (preg_match('/^(\w+)\.(\w+(?:\s*,\s*\w+)*)$/', $alt, $m)) {
                $cols = array_map('trim', explode(',', $m[2]));
                $val = $sum ? self::sum($m[1], $cols[0], $anchors, $currency) : self::values($m[1], $cols, $anchors, $currency);
            } elseif (preg_match('/^(\w+)$/', $alt, $m)) {
                $val = self::values($m[1], ['display_name', 'full_name', 'name', 'official_name', 'reference', 'code', 'serial_number', 'status'], $anchors, $currency, true);
            }
            if ($val !== null && $val !== '' && $val !== []) {
                return $val;
            }
        }

        return null;
    }

    /** @return mixed joined display value, or (raw) the list of raw values */
    private static function values(string $table, array $cols, array $anchors, string $currency, bool $firstLabelOnly = false, bool $raw = false): mixed
    {
        $q = self::anchored($table, $anchors);
        if (! $q) {
            return null;
        }
        $cols = array_values(array_filter($cols, fn ($c) => self::hasColumn($table, $c) && ! preg_match(self::SECRET_COLUMNS, $c)));
        if ($cols === []) {
            return null;
        }
        if ($firstLabelOnly) {
            $cols = [$cols[0]];
        }
        if (self::hasColumn($table, 'created_at')) {
            $q->orderByDesc('created_at');
        }
        $rows = $q->limit(20)->get($cols);
        if ($raw) {
            return $rows->pluck($cols[0])->filter()->unique()->values()->all();
        }
        $out = [];
        foreach ($rows as $r) {
            $parts = [];
            foreach ($cols as $c) {
                $p = self::fmt($r->{$c}, $c, $currency);
                if ($p !== null) {
                    $parts[] = count($cols) > 1 ? ucwords(str_replace('_', ' ', preg_replace('/_minor$/', '', $c))).': '.$p : $p;
                }
            }
            if ($parts !== []) {
                $out[] = implode(', ', $parts);
            }
        }
        $out = array_values(array_unique($out));

        return $out === [] ? null : implode(' · ', $out);
    }

    private static function sum(string $table, string $col, array $anchors, string $currency): ?string
    {
        $q = self::anchored($table, $anchors);

        return $q && self::hasColumn($table, $col) && $q->exists() ? rtrim(rtrim(number_format((float) $q->sum($col), 2, '.', ' '), '0'), '.') : null;
    }

    private static function anchored(string $table, array $anchors): ?\Illuminate\Database\Query\Builder
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        // The anchored row itself (ctx source id / model in play).
        foreach ($anchors as $col => $id) {
            if (self::tableFor($col) === $table) {
                return DB::table($table)->where('id', $id);
            }
        }
        // Rows pointing at an anchor through their own foreign key (most specific first).
        foreach (['health_preauthorization_id', 'health_provider_claim_id', 'health_provider_settlement_batch_id', 'provider_tariff_version_id', 'provider_contract_id',
            'claim_id', 'policy_transaction_id', 'payment_intent_id', 'policy_id', 'proposal_id', 'quote_offer_id', 'quote_id', 'provider_profile_id', 'party_id', 'product_id'] as $col) {
            if (isset($anchors[$col]) && self::hasColumn($table, $col)) {
                return DB::table($table)->where($col, $anchors[$col]);
            }
        }
        foreach ($anchors as $col => $id) {
            if (self::hasColumn($table, $col) && ! in_array($col, ['carrier_id', 'tenant_id'], true)) {
                return DB::table($table)->where($col, $id);
            }
        }

        return null;
    }

    /** "health_preauthorization_id" => "health_preauthorizations", "…_batch_id" => "…_batches". */
    private static function tableFor(string $col): ?string
    {
        $base = substr($col, 0, -3);
        $aliases = ['provider_profile' => 'provider_profiles', 'party' => 'parties', 'product' => 'insurance_products', 'payment_intent' => 'payment_intents', 'carrier' => 'carriers'];
        if (isset($aliases[$base])) {
            return $aliases[$base];
        }
        foreach ([$base.'s', $base.'es', preg_replace('/y$/', 'ies', $base)] as $t) {
            if (Schema::hasTable($t)) {
                return $t;
            }
        }

        return null;
    }

    private static function hasColumn(string $table, string $col): bool
    {
        self::$columns[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];

        return in_array($col, self::$columns[$table], true);
    }

    private static function join(array $vals, string $col, string $currency): ?string
    {
        $out = array_values(array_unique(array_filter(array_map(fn ($x) => self::fmt($x, $col, $currency), $vals), fn ($x) => $x !== null)));

        return $out === [] ? null : implode(' · ', $out);
    }

    private static function fmt(mixed $x, string $col, string $currency): ?string
    {
        if ($x === null || $x === '') {
            return null;
        }
        if (is_bool($x)) {
            return $x ? 'Yes / Oui' : 'No / Non';
        }
        if (str_ends_with($col, '_minor') && is_numeric($x)) {
            return number_format(((int) $x) / 100, 0, '.', ' ').' '.$currency;
        }
        if (str_ends_with($col, '_bp') && is_numeric($x)) {
            return rtrim(rtrim(number_format(((int) $x) / 100, 2, '.', ''), '0'), '.').' %';
        }
        if (is_string($x) && ($x[0] ?? '') !== '' && in_array($x[0], ['{', '['], true)) {
            $d = json_decode($x, true);
            if (is_array($d)) {
                $flat = [];
                array_walk_recursive($d, function ($v, $k) use (&$flat) {
                    if (is_scalar($v) && $v !== '' && ! preg_match(self::SECRET_COLUMNS, (string) $k)) {
                        $flat[] = (is_string($k) ? str_replace('_', ' ', $k).': ' : '').(is_bool($v) ? ($v ? 'yes' : 'no') : $v);
                    }
                });

                return $flat === [] ? null : implode(', ', array_slice($flat, 0, 12));
            }
        }

        return is_scalar($x) ? trim((string) $x) : null;
    }

    /** @return array<string, mixed> derived values the table map alone cannot express */
    private static function explicit(Policy $policy, array $ctx): array
    {
        $offer = $policy->proposal?->offer;
        $coverages = (array) ($policy->terms_snapshot['coverage_snapshot']['coverages'] ?? $offer?->coverage_snapshot['coverages'] ?? []);
        $name = fn ($c) => is_array($c['name'] ?? null) ? ($c['name']['en'] ?? reset($c['name'])) : ($c['name'] ?? $c['code'] ?? '?');

        return array_filter([
            'coverage.mandatory_flags' => implode(' · ', array_filter(array_map(fn ($c) => is_array($c) && isset($c['mandatory']) ? $name($c).' ('.($c['mandatory'] ? 'mandatory / obligatoire' : 'optional / facultative').')' : null, $coverages))) ?: null,
            'policy.term' => $policy->coverage_starts_at && $policy->coverage_ends_at ? $policy->coverage_starts_at->format('d/m/Y').' → '.$policy->coverage_ends_at->format('d/m/Y') : null,
            'policy.certificates' => $policy->certificate_number,
            'payment.amount_words' => ($pay = $ctx['payment'] ?? ($policy->payment_intent_id ? DB::table('payment_intents')->where('id', $policy->payment_intent_id)->first() : null)) && isset($pay->amount_minor)
                ? \App\Application\Shared\AmountInWords::bilingual((int) $pay->amount_minor, (string) ($pay->currency ?? $policy->currency ?? 'XAF')) : null,
        ], fn ($x) => $x !== null && $x !== '');
    }

    /** @return array{mapped: int, source_exists: int, keys: array<int, string>} distinct mapped keys, and those whose source table exists */
    public static function coverageUniverse(): array
    {
        $keys = [];
        foreach (DetailedFieldSourceMap::MAP as [$key, $source]) {
            if ($key === null || in_array($key, self::SKIP, true)) {
                continue;
            }
            $exists = false;
            foreach (preg_split('#\s+/\s+#', (string) $source) as $alt) {
                if (preg_match('/^(?:sum\()?(\w+)/', trim($alt), $m) && Schema::hasTable($m[1])) {
                    $exists = true;
                }
            }
            $keys[$key] = ($keys[$key] ?? false) || $exists;
        }

        return ['mapped' => count($keys), 'source_exists' => count(array_filter($keys)), 'keys' => array_keys(array_filter($keys))];
    }
}
