<?php

declare(strict_types=1);

namespace App\Application\Import\Targets;

use App\Application\Import\ImportTarget;
use App\Application\MasterData\MasterDataCache;
use App\Application\MasterData\MasterDataNormalizer;
use App\Models\MasterData\MasterDataAlias;
use App\Models\MasterData\MasterDataChange;
use App\Models\MasterData\MasterDataList;
use App\Models\MasterData\MasterDataValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MDM-013 — values of one master-data list. Columns: code (derived from label_en when empty), label_en, label_fr,
 * parent_code, description_en, description_fr, aliases (| separated), source_reference. Duplicates: existing code,
 * or a normalized label/alias already in the list. Imported values are MANUAL_VERIFIED, verified by the approver.
 */
final class MasterDataValueTarget implements ImportTarget
{
    /** @var array<string, \Illuminate\Support\Collection> */
    private array $existing = [];

    public function key(): string
    {
        return 'master_data_values';
    }

    public function label(): string
    {
        return 'Master data values';
    }

    public function fields(): array
    {
        return ['code' => false, 'label_en' => true, 'label_fr' => true, 'parent_code' => false, 'description_en' => false,
            'description_fr' => false, 'aliases' => false, 'source_reference' => false];
    }

    public function params(array $params): array
    {
        $domain = (string) ($params['domain'] ?? '');
        $list = (string) ($params['list'] ?? '');
        if (! MasterDataList::where(['domain_code' => $domain, 'code' => $list])->exists()) {
            throw ValidationException::withMessages(['list' => "Unknown list $domain.$list."]);
        }

        return ['domain' => $domain, 'list' => $list];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $code = $this->code($row);
        if (! preg_match('/^[A-Z0-9_]+$/', $code) || ($row['label_en'] ?? null) === null || ($row['label_fr'] ?? null) === null) {
            return ['status' => 'ERROR', 'error' => 'code (A-Z0-9_), label_en and label_fr are required'];
        }
        $existing = $this->existing($params);
        if ($existing->contains('code', $code)) {
            return ['status' => 'DUPLICATE', 'key' => $code, 'matches' => $code];
        }
        $norm = MasterDataNormalizer::normalize($row['label_en']);
        $hit = $existing->first(fn ($v) => str_contains(' '.$v->search_text.' ', " $norm "));
        if ($hit) {
            return ['status' => 'DUPLICATE', 'key' => $code, 'matches' => $hit->code];
        }
        if (isset($seen[$code])) {
            return ['status' => 'ERROR', 'error' => "code $code repeated in the file"];
        }
        $seen[$code] = true;

        return ['status' => 'NEW', 'key' => $code];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $list = MasterDataList::where(['domain_code' => $params['domain'], 'code' => $params['list']])->firstOrFail();
        $v = MasterDataValue::create([
            'list_id' => $list->id, 'domain_code' => $list->domain_code, 'list_code' => $list->code, 'code' => $this->code($row),
            'label_en' => $row['label_en'], 'label_fr' => $row['label_fr'], 'parent_code' => $row['parent_code'] ?? null,
            'description_en' => $row['description_en'] ?? null, 'description_fr' => $row['description_fr'] ?? null,
            'source_type' => 'MANUAL_VERIFIED', 'source_reference' => $row['source_reference'] ?? "import $batchId",
            'sort_order' => 50000, 'verified_at' => now(), 'verified_by' => $actor?->id, 'admin_modified_at' => now(),
        ]);
        foreach (array_unique(array_filter(array_map('trim', explode('|', (string) ($row['aliases'] ?? ''))))) as $alias) {
            MasterDataAlias::create(['value_id' => $v->id, 'alias' => $alias]);
        }
        MasterDataChange::create(['entity_type' => 'VALUE', 'entity_id' => $v->id, 'domain_code' => $v->domain_code, 'action' => 'CREATED',
            'after' => ['code' => $v->code, 'label_en' => $v->label_en, 'import_batch_id' => $batchId], 'actor_id' => $actor?->id, 'source' => 'IMPORT']);
        $this->existing = [];

        return $v->id;
    }

    public function finish(array $params): void
    {
        $list = MasterDataList::where(['domain_code' => $params['domain'], 'code' => $params['list']])->firstOrFail();
        if ($list->parent_list_code) {
            [$pd, $pl] = str_contains($list->parent_list_code, '.') ? explode('.', $list->parent_list_code, 2) : [$list->domain_code, $list->parent_list_code];
            $parentListId = MasterDataList::where(['domain_code' => $pd, 'code' => $pl])->value('id');
            DB::update('UPDATE master_data_values v SET parent_value_id = p.id FROM master_data_values p WHERE v.list_id = ? AND p.list_id = ? AND p.code = v.parent_code AND v.parent_value_id IS NULL', [$list->id, $parentListId]);
        }
        MasterDataCache::bump($params['domain']);
    }

    private function code(array $row): string
    {
        return strtoupper(trim((string) ($row['code'] ?? ''))) ?: MasterDataNormalizer::codeFrom((string) ($row['label_en'] ?? ''));
    }

    private function existing(array $params): \Illuminate\Support\Collection
    {
        $k = $params['domain'].'.'.$params['list'];

        return $this->existing[$k] ??= MasterDataValue::where(['domain_code' => $params['domain'], 'list_code' => $params['list']])->whereNull('tenant_id')
            ->get(['id', 'code', 'search_text']);
    }
}
