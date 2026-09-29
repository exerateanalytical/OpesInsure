<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use App\Application\Audit\AuditWriter;
use App\Models\CarrierApiConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Daily copy of Activa's referential data (SouscriptionCMR/ReferentialData) into carrier_reference_data, idempotent:
 * an item is keyed by (carrier, provider, domain, code) and only rewritten when its content hash changes; items no
 * longer returned become RETIRED (never deleted). Items of a domain listed in config activa.reference_domains are
 * linked to our master data (carrier_master_data_mappings, target ACTIVA) on an exact code / label / alias match —
 * an existing mapping is never overwritten; unmatched items stay unmapped for review.
 *
 * The 200 of ReferentialData has no published schema, so the payload is read generically: an object of
 * domain => list, or a list of {domain/type/categorie, code, libelle} rows.
 */
final class ActivaReferenceDataSync
{
    public const MAPPING_TARGET = 'ACTIVA';

    public function __construct(private readonly ActivaApi $api, private readonly ActivaConnections $connections, private readonly AuditWriter $audit) {}

    /** @return array{connections: int, received: int, created: int, updated: int, unchanged: int, retired: int, mapped: int, errors: array<string, string>} */
    public function run(?CarrierApiConnection $only = null): array
    {
        $stats = ['connections' => 0, 'received' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'retired' => 0, 'mapped' => 0, 'errors' => []];
        $targets = $only ? [$only] : array_filter($this->connections->all(), fn (CarrierApiConnection $c) => $c->status !== 'DISABLED');
        foreach ($targets as $c) {
            try {
                $payload = $this->api->referentialData($c);
            } catch (ActivaException $e) {
                $stats['errors'][$c->environment] = $e->errorCode;

                continue;
            }
            $stats['connections']++;
            $s = $this->store($c, $payload);
            foreach (['received', 'created', 'updated', 'unchanged', 'retired', 'mapped'] as $k) {
                $stats[$k] += $s[$k];
            }
            $c->forceFill(['last_reference_sync_at' => now()])->save();
            $this->audit->record('integration.carrier_api.reference_synced', 'carrier_api_connection', $c->id, ['carrier_id' => $c->carrier_id] + $s);
        }

        return $stats;
    }

    /** @return array{received: int, created: int, updated: int, unchanged: int, retired: int, mapped: int} */
    public function store(CarrierApiConnection $c, mixed $payload): array
    {
        $items = $this->flatten($payload);
        $s = ['received' => count($items), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'retired' => 0, 'mapped' => 0];
        $provider = $c->provider;
        $now = now();

        DB::transaction(function () use ($c, $items, $provider, $now, &$s) {
            $existing = DB::table('carrier_reference_data')->where(['carrier_id' => $c->carrier_id, 'provider' => $provider])->get()
                ->keyBy(fn ($r) => $r->domain."\0".$r->code);
            $seen = [];
            foreach ($items as $it) {
                $key = $it['domain']."\0".$it['code'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $hash = hash('sha256', json_encode([$it['label'], $it['payload']], JSON_UNESCAPED_UNICODE));
                $row = $existing[$key] ?? null;
                if ($row === null) {
                    DB::table('carrier_reference_data')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $c->carrier_id, 'provider' => $provider, 'domain' => $it['domain'],
                        'code' => $it['code'], 'label' => $it['label'], 'payload' => json_encode($it['payload'], JSON_UNESCAPED_UNICODE), 'content_hash' => $hash, 'status' => 'ACTIVE',
                        'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                    $s['created']++;
                } elseif ($row->content_hash !== $hash || $row->status !== 'ACTIVE') {
                    DB::table('carrier_reference_data')->where('id', $row->id)->update(['label' => $it['label'], 'payload' => json_encode($it['payload'], JSON_UNESCAPED_UNICODE),
                        'content_hash' => $hash, 'status' => 'ACTIVE', 'last_seen_at' => $now, 'updated_at' => $now]);
                    $s['updated']++;
                } else {
                    DB::table('carrier_reference_data')->where('id', $row->id)->update(['last_seen_at' => $now]);
                    $s['unchanged']++;
                }
            }
            if ($items !== []) {
                foreach ($existing as $key => $row) {
                    if (! isset($seen[$key]) && $row->status === 'ACTIVE') {
                        DB::table('carrier_reference_data')->where('id', $row->id)->update(['status' => 'RETIRED', 'updated_at' => $now]);
                        $s['retired']++;
                    }
                }
            }
            $s['mapped'] = $this->link($c);
        });

        return $s;
    }

    /** Links unmapped items of configured domains to master data values (exact code / label / alias, one match only). */
    private function link(CarrierApiConnection $c): int
    {
        $mapped = 0;
        foreach ((array) config('activa.reference_domains', []) as $domain => $listCode) {
            $rows = DB::table('carrier_reference_data')->where(['carrier_id' => $c->carrier_id, 'provider' => $c->provider, 'status' => 'ACTIVE'])
                ->whereRaw('lower(domain) = ?', [strtolower((string) $domain)])->whereNull('mapped_value_id')->get();
            foreach ($rows as $row) {
                $norm = Str::lower(Str::ascii(trim((string) ($row->label ?? ''))));
                $values = DB::table('master_data_values')->where('list_code', $listCode)->where('status', 'ACTIVE')->whereNull('tenant_id')
                    ->where(function ($q) use ($row, $norm) {
                        $q->whereRaw('upper(code) = ?', [strtoupper((string) $row->code)]);
                        if ($norm !== '') {
                            $q->orWhereRaw('lower(label_fr) = ?', [$norm])->orWhereRaw('lower(label_en) = ?', [$norm])
                                ->orWhereIn('id', DB::table('master_data_aliases')->where('normalized', $norm)->select('value_id'));
                        }
                    })->limit(2)->pluck('id');
                if ($values->count() !== 1) {
                    continue;
                }
                $valueId = (string) $values->first();
                $exists = DB::table('carrier_master_data_mappings')->where(['carrier_id' => $c->carrier_id, 'value_id' => $valueId, 'target' => self::MAPPING_TARGET])->exists();
                if (! $exists) {
                    DB::table('carrier_master_data_mappings')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $c->carrier_id, 'value_id' => $valueId, 'target' => self::MAPPING_TARGET,
                        'external_code' => mb_substr((string) $row->code, 0, 128), 'external_label' => $row->label ? mb_substr((string) $row->label, 0, 255) : null,
                        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('carrier_reference_data')->where('id', $row->id)->update(['mapped_value_id' => $valueId]);
                $mapped++;
            }
        }

        return $mapped;
    }

    /** @return list<array{domain: string, code: string, label: ?string, payload: array}> */
    public function flatten(mixed $payload): array
    {
        $out = [];
        if (is_array($payload) && isset($payload['data']) && is_array($payload['data']) && count($payload) <= 3) {
            $payload = $payload['data'];
        }
        if (! is_array($payload)) {
            return [];
        }
        if (array_is_list($payload)) {
            foreach ($payload as $row) {
                if (is_array($row) && ($item = $this->item($row, (string) ($row['domain'] ?? $row['type'] ?? $row['categorie'] ?? $row['table'] ?? $row['nomTable'] ?? 'default')))) {
                    $out[] = $item;
                }
            }

            return $out;
        }
        foreach ($payload as $domain => $rows) {
            if (! is_array($rows)) {
                continue;
            }
            foreach (array_is_list($rows) ? $rows : [$rows] as $row) {
                if (is_array($row) && ($item = $this->item($row, (string) $domain))) {
                    $out[] = $item;
                } elseif (is_scalar($row) && (string) $row !== '') {
                    $out[] = ['domain' => mb_substr((string) $domain, 0, 96), 'code' => mb_substr((string) $row, 0, 128), 'label' => (string) $row, 'payload' => ['value' => $row]];
                }
            }
        }

        return $out;
    }

    private function item(array $row, string $domain): ?array
    {
        $code = null;
        foreach ($row as $k => $v) {
            $lk = strtolower((string) $k);
            if (is_scalar($v) && (string) $v !== '' && ($lk === 'code' || $lk === 'id' || str_starts_with($lk, 'code') || str_starts_with($lk, 'cod'))) {
                $code = (string) $v;
                break;
            }
        }
        $label = null;
        foreach ($row as $k => $v) {
            $lk = strtolower((string) $k);
            if (is_scalar($v) && (string) $v !== '' && ($lk === 'label' || $lk === 'libelle' || str_starts_with($lk, 'lib') || $lk === 'name' || $lk === 'nom' || str_starts_with($lk, 'desi') || str_starts_with($lk, 'desc'))) {
                $label = (string) $v;
                break;
            }
        }
        $code ??= $label;
        if ($code === null) {
            return null;
        }

        return ['domain' => mb_substr($domain, 0, 96), 'code' => mb_substr($code, 0, 128), 'label' => $label !== null ? mb_substr($label, 0, 500) : null, 'payload' => $row];
    }
}
