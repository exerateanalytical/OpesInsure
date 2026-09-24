<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use App\Models\MasterData\MasterDataAlias;
use App\Models\MasterData\MasterDataChange;
use App\Models\MasterData\MasterDataList;
use App\Models\MasterData\MasterDataReviewItem;
use App\Models\MasterData\MasterDataValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Other / Not listed" workflow. The fallback never blocks a transaction: the
 * raw text is kept on the transaction and a suggestion is filed here. Identical
 * proposals are de-duplicated and counted; likely duplicates of existing values
 * are flagged (DUPLICATE_FOUND). Admins approve (new value), merge (into an
 * existing value, raw text becomes an alias) or reject.
 */
final class MasterDataReviewService
{
    public function __construct(private readonly MasterDataSearch $search) {}

    /** @param array{parent_code?:?string, locale?:?string, user_id?:?string, tenant_id?:?string, screen?:?string, line_code?:?string, field_key?:?string, suggested_category?:?string} $ctx */
    public function submit(string $domain, string $list, string $text, array $ctx = []): array
    {
        $text = trim($text);
        $listRow = MasterDataList::where(['domain_code' => $domain, 'code' => $list])->first();
        if (! $listRow) {
            throw ValidationException::withMessages(['list' => __('Unknown master data list :d.:l.', ['d' => $domain, 'l' => $list])]);
        }
        if ($text === '' || mb_strlen($text) > 200) {
            throw ValidationException::withMessages(['text' => __('Enter the missing value (1–200 characters).')]);
        }
        $norm = MasterDataNormalizer::normalize($text);

        // Exact match with an existing value or alias: no review needed.
        $exact = collect($this->search->search($domain, $list, $text, $ctx['parent_code'] ?? null) ?? [])
            ->first(fn ($v) => ! ($v['is_other'] ?? false) && ($v['score'] ?? 0) >= 700);
        if ($exact) {
            return ['status' => 'MATCHED', 'value' => $exact, 'review' => null];
        }

        return DB::transaction(function () use ($listRow, $domain, $list, $text, $norm, $ctx) {
            $submission = array_filter(['text' => $text, 'user_id' => $ctx['user_id'] ?? null, 'tenant_id' => $ctx['tenant_id'] ?? null, 'screen' => $ctx['screen'] ?? null, 'at' => now()->toIso8601String()]);
            $item = MasterDataReviewItem::where(['list_id' => $listRow->id, 'normalized' => $norm])
                ->where('parent_code', $ctx['parent_code'] ?? null)->whereIn('status', MasterDataReviewItem::OPEN)->lockForUpdate()->first();
            if ($item) {
                $subs = $item->submissions ?? [];
                $subs[] = $submission;
                $item->update(['submission_count' => $item->submission_count + 1, 'submissions' => array_slice($subs, -50)]);

                return ['status' => $item->status, 'value' => null, 'review' => $this->present($item)];
            }
            $dupes = collect($this->search->search($domain, $list, $text, $ctx['parent_code'] ?? null, null, 5) ?? [])
                ->reject(fn ($v) => $v['is_other'] ?? false)->map(fn ($v) => ['code' => $v['code'], 'label' => $v['label'], 'score' => $v['score']])->values()->all();
            $item = MasterDataReviewItem::create([
                'list_id' => $listRow->id, 'domain_code' => $domain, 'list_code' => $list, 'raw_input' => $text, 'normalized' => $norm,
                'parent_code' => $ctx['parent_code'] ?? null, 'suggested_category' => $ctx['suggested_category'] ?? null, 'locale' => $ctx['locale'] ?? null,
                'status' => $dupes ? 'DUPLICATE_FOUND' : 'SUBMITTED', 'proposer_user_id' => $ctx['user_id'] ?? null, 'tenant_id' => $ctx['tenant_id'] ?? null,
                'screen' => $ctx['screen'] ?? null, 'line_code' => $ctx['line_code'] ?? null, 'field_key' => $ctx['field_key'] ?? null,
                'possible_duplicates' => $dupes ?: null, 'submission_count' => 1, 'submissions' => [$submission],
            ]);

            return ['status' => $item->status, 'value' => null, 'review' => $this->present($item)];
        });
    }

    /** Approve as a new controlled value. */
    public function approve(MasterDataReviewItem $item, array $data, ?string $actorId): MasterDataValue
    {
        $this->assertOpen($item);

        return DB::transaction(function () use ($item, $data, $actorId) {
            $labelEn = trim($data['label_en'] ?? $item->raw_input);
            $labelFr = trim($data['label_fr'] ?? $labelEn);
            $code = strtoupper($data['code'] ?? MasterDataNormalizer::codeFrom($labelEn));
            if (MasterDataValue::where(['list_id' => $item->list_id, 'code' => $code])->exists()) {
                throw ValidationException::withMessages(['code' => __('Code :c already exists in this list; merge instead.', ['c' => $code])]);
            }
            $value = MasterDataValue::create([
                'list_id' => $item->list_id, 'domain_code' => $item->domain_code, 'list_code' => $item->list_code, 'code' => $code,
                'label_en' => $labelEn, 'label_fr' => $labelFr, 'parent_code' => $data['parent_code'] ?? $item->parent_code,
                'parent_value_id' => $this->parentId($item->list_id, $data['parent_code'] ?? $item->parent_code),
                'sort_order' => 100000, 'status' => 'ACTIVE', 'source_type' => 'USER_SUBMITTED', 'verified_at' => now(), 'verified_by' => $actorId,
                'is_seeded' => false,
            ]);
            if (MasterDataNormalizer::normalize($item->raw_input) !== MasterDataNormalizer::normalize($labelEn)) {
                MasterDataAlias::create(['value_id' => $value->id, 'alias' => $item->raw_input]);
            }
            $item->update(['status' => 'APPROVED', 'resolved_value_id' => $value->id, 'resolved_by' => $actorId, 'resolved_at' => now(), 'resolution_note' => $data['note'] ?? null]);
            $this->log('REVIEW', $item->id, $item->domain_code, 'REVIEW_APPROVED', null, ['value' => $code], $actorId);

            return $value;
        });
    }

    /** Merge into an existing value: the submitted text becomes an alias. */
    public function merge(MasterDataReviewItem $item, MasterDataValue $into, ?string $actorId, ?string $note = null): MasterDataValue
    {
        $this->assertOpen($item);
        if ($into->list_id !== $item->list_id) {
            throw ValidationException::withMessages(['value' => __('Merge target must belong to the same list.')]);
        }

        return DB::transaction(function () use ($item, $into, $actorId, $note) {
            $norm = MasterDataNormalizer::normalize($item->raw_input);
            if (! MasterDataAlias::where(['value_id' => $into->id, 'normalized' => $norm])->exists()) {
                MasterDataAlias::create(['value_id' => $into->id, 'alias' => $item->raw_input]);
            }
            $item->update(['status' => 'MERGED', 'resolved_value_id' => $into->id, 'resolved_by' => $actorId, 'resolved_at' => now(), 'resolution_note' => $note]);
            $this->log('REVIEW', $item->id, $item->domain_code, 'REVIEW_MERGED', null, ['into' => $into->code], $actorId);

            return $into;
        });
    }

    public function reject(MasterDataReviewItem $item, ?string $actorId, ?string $note = null): void
    {
        $this->assertOpen($item);
        $item->update(['status' => 'REJECTED', 'resolved_by' => $actorId, 'resolved_at' => now(), 'resolution_note' => $note]);
        $this->log('REVIEW', $item->id, $item->domain_code, 'REVIEW_REJECTED', null, ['note' => $note], $actorId);
    }

    /** Merge two values: $from becomes INACTIVE and redirects to $into; its code and labels become aliases. */
    public function mergeValues(MasterDataValue $from, MasterDataValue $into, ?string $actorId): void
    {
        if ($from->id === $into->id || $from->list_id !== $into->list_id) {
            throw ValidationException::withMessages(['value' => __('Values must be different and in the same list.')]);
        }
        DB::transaction(function () use ($from, $into, $actorId) {
            foreach (array_unique([$from->label_en, $from->label_fr]) as $alias) {
                if (! MasterDataAlias::where(['value_id' => $into->id, 'normalized' => MasterDataNormalizer::normalize($alias)])->exists()) {
                    MasterDataAlias::create(['value_id' => $into->id, 'alias' => $alias, 'alias_type' => 'ALIAS']);
                }
            }
            MasterDataAlias::create(['value_id' => $into->id, 'alias' => $from->code, 'alias_type' => 'MERGED_CODE']);
            $from->update(['status' => 'INACTIVE', 'merged_into_id' => $into->id, 'admin_modified_at' => now()]);
            MasterDataValue::where('parent_value_id', $from->id)->update(['parent_value_id' => $into->id, 'parent_code' => $into->code]);
            $this->log('VALUE', $from->id, $from->domain_code, 'MERGED', ['code' => $from->code], ['into' => $into->code], $actorId);
        });
    }

    public function recordUsage(?string $tenantId, array $valueIds): void
    {
        $valueIds = array_values(array_unique(array_filter($valueIds)));
        if (! $valueIds) {
            return;
        }
        DB::table('master_data_values')->whereIn('id', $valueIds)->increment('usage_count');
        if ($tenantId) {
            foreach ($valueIds as $id) {
                DB::statement('INSERT INTO master_data_value_usage (value_id, tenant_id, uses, last_used_at) VALUES (?, ?, 1, now())
                    ON CONFLICT (value_id, tenant_id) DO UPDATE SET uses = master_data_value_usage.uses + 1, last_used_at = now()', [$id, $tenantId]);
            }
        }
    }

    public function present(MasterDataReviewItem $item): array
    {
        return ['id' => $item->id, 'domain' => $item->domain_code, 'list' => $item->list_code, 'raw_input' => $item->raw_input, 'status' => $item->status,
            'submission_count' => $item->submission_count, 'possible_duplicates' => $item->possible_duplicates ?? []];
    }

    private function assertOpen(MasterDataReviewItem $item): void
    {
        if (! in_array($item->status, MasterDataReviewItem::OPEN, true)) {
            throw ValidationException::withMessages(['status' => __('This suggestion is already :s.', ['s' => strtolower($item->status)])]);
        }
    }

    private function parentId(string $listId, ?string $parentCode): ?string
    {
        if (! $parentCode) {
            return null;
        }
        $list = MasterDataList::find($listId);
        if (! $list?->parent_list_code) {
            return null;
        }
        [$d, $l] = str_contains($list->parent_list_code, '.') ? explode('.', $list->parent_list_code, 2) : [$list->domain_code, $list->parent_list_code];

        return MasterDataValue::where(['domain_code' => $d, 'list_code' => $l, 'code' => $parentCode])->value('id');
    }

    private function log(string $type, string $id, ?string $domain, string $action, ?array $before, ?array $after, ?string $actorId): void
    {
        MasterDataChange::create(['entity_type' => $type, 'entity_id' => $id, 'domain_code' => $domain, 'action' => $action, 'before' => $before, 'after' => $after, 'actor_id' => $actorId, 'source' => 'ADMIN']);
    }
}
