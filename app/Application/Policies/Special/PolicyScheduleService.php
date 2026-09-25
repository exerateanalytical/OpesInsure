<?php

declare(strict_types=1);

namespace App\Application\Policies\Special;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Risks\RiskAssetTypes;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PRD-011 (PRE §66–73) — special product profiles and dated schedules.
 *
 * GROUP_MASTER → GROUP_MEMBER items (members of a group life/health/PA master policy)
 * FLEET        → FLEET_VEHICLE items (vehicles on a fleet policy; risk_asset must be a VEHICLE when given)
 * CONSTRUCTION → CONSTRUCTION_WORK items (sites / contract works sections)
 * AGRICULTURE  → AGRI_PLOT / AGRI_HERD items (crop plots, herds; risk_asset CROP/LIVESTOCK/AQUACULTURE)
 * OPEN_COVER   → no schedule items; shipments go through CargoDeclarationService
 * LIFE         → no schedule items; surrender via LifeSurrenderService
 *
 * Schedules are append-only: adding writes a dated row (with pro-rata additional premium),
 * removing sets effective_until (pro-rata return premium). The schedule "as of" a date is the
 * set of rows whose [effective_from, effective_until) covers it. Policy rows are never written;
 * premium adjustments are recorded here for the endorsement flow to pick up.
 */
final class PolicyScheduleService
{
    public const KINDS = ['GROUP_MASTER', 'FLEET', 'OPEN_COVER', 'CONSTRUCTION', 'AGRICULTURE', 'LIFE'];

    /** kind => allowed item types => allowed risk asset types (null = any/none) */
    public const ITEM_TYPES = [
        'GROUP_MASTER' => ['GROUP_MEMBER' => ['PERSON', 'HEALTH_MEMBER', 'LIFE_ASSURED']],
        'FLEET' => ['FLEET_VEHICLE' => ['VEHICLE']],
        'CONSTRUCTION' => ['CONSTRUCTION_WORK' => ['CONSTRUCTION', 'EQUIPMENT']],
        'AGRICULTURE' => ['AGRI_PLOT' => ['CROP'], 'AGRI_HERD' => ['LIVESTOCK', 'AQUACULTURE']],
        'OPEN_COVER' => [],
        'LIFE' => [],
    ];

    public function __construct(
        private readonly SpecialPolicyGuard $guard,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function createProfile(string $tenantId, string $policyId, string $kind, array $terms, User $actor): array
    {
        $kind = strtoupper($kind);
        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown special product kind.']);
        }
        $this->guard->openPolicy($tenantId, $policyId);
        if (DB::table('special_policy_profiles')->where('policy_id', $policyId)->exists()) {
            throw ValidationException::withMessages(['policy' => 'Policy already has a special product profile.']);
        }
        if ($kind === 'OPEN_COVER') {
            $this->validateOpenCoverTerms($terms);
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenantId, $policyId, $kind, $terms, $actor) {
            DB::table('special_policy_profiles')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'policy_id' => $policyId, 'kind' => $kind,
                'terms' => json_encode((object) $terms), 'status' => 'ACTIVE', 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('special_policy.profile.created', 'policy', $policyId, ['profile_id' => $id, 'kind' => $kind]);
            $this->outbox->record('special_policy.profile.created', 'policy', $policyId, ['tenant_id' => $tenantId, 'profile_id' => $id, 'kind' => $kind]);
        });

        return $this->profileView($tenantId, $policyId);
    }

    public function profileView(string $tenantId, string $policyId): array
    {
        $pr = DB::table('special_policy_profiles')->where('tenant_id', $tenantId)->where('policy_id', $policyId)->first();
        if (! $pr) {
            abort(404, 'Policy has no special product profile.');
        }

        return ['id' => $pr->id, 'policy_id' => $pr->policy_id, 'kind' => $pr->kind, 'status' => $pr->status, 'terms' => json_decode($pr->terms, true)];
    }

    public function addItem(string $tenantId, string $policyId, array $d, User $actor): array
    {
        $policy = $this->guard->openPolicy($tenantId, $policyId);
        $pr = $this->guard->profile($tenantId, $policyId);
        $types = self::ITEM_TYPES[$pr->kind];
        $type = strtoupper((string) ($d['item_type'] ?? (count($types) === 1 ? array_key_first($types) : '')));
        if (! array_key_exists($type, $types)) {
            throw ValidationException::withMessages(['item_type' => "Item type not allowed on a {$pr->kind} policy."]);
        }
        $from = (string) $d['effective_from'];
        SpecialPolicyGuard::assertWithinCover($policy, $from, 'effective_from');
        if (! empty($d['risk_asset_id'])) {
            $asset = DB::table('risk_assets')->where('id', $d['risk_asset_id'])->where('tenant_id', $tenantId)->first();
            if (! $asset || ! in_array(strtoupper($asset->type), $types[$type], true)) {
                throw ValidationException::withMessages(['risk_asset_id' => 'Risk asset not found or of a type not allowed for '.$type.' ('.implode(', ', $types[$type]).').']);
            }
        }
        if (! empty($d['party_id']) && ! DB::table('parties')->where('id', $d['party_id'])->exists()) {
            throw ValidationException::withMessages(['party_id' => 'Party not found.']);
        }
        if (DB::table('policy_schedule_items')->where('profile_id', $pr->id)->where('item_key', $d['item_key'])->whereNull('effective_until')->exists()) {
            throw ValidationException::withMessages(['item_key' => 'An open schedule entry already exists for this key; remove it first.']);
        }
        $annual = (int) ($d['annual_premium_minor'] ?? 0);
        $adj = SpecialPolicyGuard::proRata($annual, $policy, $from);
        $id = (string) Str::uuid();
        $event = self::eventFor($type, 'added');
        DB::transaction(function () use ($id, $tenantId, $pr, $policyId, $type, $d, $annual, $adj, $from, $actor, $event) {
            DB::table('policy_schedule_items')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'profile_id' => $pr->id, 'policy_id' => $policyId, 'item_type' => $type,
                'item_key' => $d['item_key'], 'party_id' => $d['party_id'] ?? null, 'risk_asset_id' => $d['risk_asset_id'] ?? null,
                'display_name' => $d['display_name'], 'category' => $d['category'] ?? null, 'facts' => json_encode((object) ($d['facts'] ?? [])),
                'sum_insured_minor' => $d['sum_insured_minor'] ?? null, 'annual_premium_minor' => $annual, 'adjustment_premium_minor' => $adj,
                'effective_from' => $from, 'added_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($event, 'policy', $policyId, ['item_id' => $id, 'item_type' => $type, 'item_key' => $d['item_key'], 'effective_from' => $from, 'adjustment_premium_minor' => $adj]);
            $this->outbox->record($event, 'policy', $policyId, ['tenant_id' => $tenantId, 'item_id' => $id, 'item_type' => $type, 'effective_from' => $from, 'adjustment_premium_minor' => $adj]);
        });

        return $this->item($id);
    }

    public function removeItem(string $tenantId, string $itemId, string $effectiveUntil, string $reason, User $actor): array
    {
        $item = DB::table('policy_schedule_items')->where('id', $itemId)->where('tenant_id', $tenantId)->first();
        if (! $item) {
            abort(404, 'Schedule item not found.');
        }
        if ($item->effective_until !== null) {
            throw ValidationException::withMessages(['item' => 'Schedule item already removed.']);
        }
        $policy = $this->guard->openPolicy($tenantId, $item->policy_id);
        SpecialPolicyGuard::assertWithinCover($policy, $effectiveUntil, 'effective_until');
        if ($effectiveUntil < \Carbon\CarbonImmutable::parse($item->effective_from)->toDateString()) {
            throw ValidationException::withMessages(['effective_until' => 'Removal date cannot precede the entry date.']);
        }
        $return = -SpecialPolicyGuard::proRata((int) $item->annual_premium_minor, $policy, $effectiveUntil);
        $event = self::eventFor($item->item_type, 'removed');
        DB::transaction(function () use ($item, $effectiveUntil, $reason, $actor, $return, $event, $tenantId) {
            DB::table('policy_schedule_items')->where('id', $item->id)->update([
                'effective_until' => $effectiveUntil, 'removed_by' => $actor->id, 'removal_reason' => $reason,
                'adjustment_premium_minor' => (int) $item->adjustment_premium_minor + $return, 'updated_at' => now(),
            ]);
            $this->audit->record($event, 'policy', $item->policy_id, ['item_id' => $item->id, 'item_key' => $item->item_key, 'effective_until' => $effectiveUntil, 'return_premium_minor' => $return], $reason);
            $this->outbox->record($event, 'policy', $item->policy_id, ['tenant_id' => $tenantId, 'item_id' => $item->id, 'item_type' => $item->item_type, 'effective_until' => $effectiveUntil, 'return_premium_minor' => $return]);
        });

        return $this->item($item->id) + ['return_premium_minor' => $return];
    }

    /** Schedule as of a date (default today) — or the full history when $history. */
    public function schedule(string $tenantId, string $policyId, ?string $asOf = null, bool $history = false): array
    {
        $this->guard->policy($tenantId, $policyId);
        $pr = DB::table('special_policy_profiles')->where('tenant_id', $tenantId)->where('policy_id', $policyId)->first();
        if (! $pr) {
            abort(404, 'Policy has no special product profile.');
        }
        $day = \Carbon\CarbonImmutable::parse($asOf ?? now())->toDateString();
        $rows = DB::table('policy_schedule_items')->where('profile_id', $pr->id)
            ->when(! $history, fn ($q) => $q->whereDate('effective_from', '<=', $day)->where(fn ($x) => $x->whereNull('effective_until')->orWhereDate('effective_until', '>', $day)))
            ->orderBy('item_type')->orderBy('item_key')->orderBy('effective_from')->get();
        $items = $rows->map(fn ($r) => $this->present($r))->all();

        return [
            'policy_id' => $policyId, 'kind' => $pr->kind, 'as_of' => $history ? null : $day, 'count' => count($items),
            'total_sum_insured_minor' => (int) $rows->sum('sum_insured_minor'), 'total_annual_premium_minor' => (int) $rows->sum('annual_premium_minor'),
            'items' => $items,
        ];
    }

    public function item(string $id): array
    {
        return $this->present(DB::table('policy_schedule_items')->where('id', $id)->first());
    }

    private function present(object $r): array
    {
        return [
            'id' => $r->id, 'policy_id' => $r->policy_id, 'item_type' => $r->item_type, 'item_key' => $r->item_key, 'display_name' => $r->display_name,
            'category' => $r->category, 'party_id' => $r->party_id, 'risk_asset_id' => $r->risk_asset_id, 'facts' => json_decode($r->facts, true),
            'sum_insured_minor' => $r->sum_insured_minor === null ? null : (int) $r->sum_insured_minor, 'annual_premium_minor' => (int) $r->annual_premium_minor,
            'adjustment_premium_minor' => (int) $r->adjustment_premium_minor,
            'effective_from' => \Carbon\CarbonImmutable::parse($r->effective_from)->toDateString(),
            'effective_until' => $r->effective_until ? \Carbon\CarbonImmutable::parse($r->effective_until)->toDateString() : null,
            'removal_reason' => $r->removal_reason,
        ];
    }

    private function validateOpenCoverTerms(array $terms): void
    {
        foreach (['per_shipment_limit_minor', 'rate_bps'] as $k) {
            if (! isset($terms[$k]) || ! is_int($terms[$k]) || $terms[$k] <= 0) {
                throw ValidationException::withMessages(["terms.{$k}" => 'Open cover terms need a positive integer '.$k.'.']);
            }
        }
        if (isset($terms['conveyances']) && array_diff((array) $terms['conveyances'], CargoDeclarationService::CONVEYANCES)) {
            throw ValidationException::withMessages(['terms.conveyances' => 'Unknown conveyance.']);
        }
    }

    public static function eventFor(string $itemType, string $verb): string
    {
        $added = $verb === 'added';

        return match ($itemType) {
            'GROUP_MEMBER' => $added ? 'group_policy.member.added' : 'group_policy.member.removed',
            'FLEET_VEHICLE' => $added ? 'fleet_policy.vehicle.added' : 'fleet_policy.vehicle.removed',
            default => $added ? 'special_policy.schedule_item.added' : 'special_policy.schedule_item.removed',
        };
    }

    /** Exposed for docs/tests: risk asset types referenced by the schedules must exist in the catalogue. */
    public static function referencedAssetTypes(): array
    {
        $all = [];
        foreach (self::ITEM_TYPES as $types) {
            foreach ($types as $assetTypes) {
                $all = [...$all, ...$assetTypes];
            }
        }

        return array_values(array_intersect(array_unique($all), RiskAssetTypes::codes()));
    }
}
