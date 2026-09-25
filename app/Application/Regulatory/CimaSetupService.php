<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Application\Audit\AuditWriter;
use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Partner;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use App\Models\Regulatory\ProductRegulatoryMapping;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\Regulatory\RegulatoryReportingCategory;
use App\Models\Regulatory\RegulatoryReportingMapping;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CIMA-005 — insurer and broker CIMA setup screens, read models + the one write path for scoped reporting mappings.
 *   INS-SET-CIMA-001 authorization · 002 authorized branches · 003 product→CIMA mapping · 004 reporting mapping
 *   · 005 accessory risk mapping · 006 life complementary covers
 *   BRK-SET-CIMA-001 reporting configuration · 002 regulatory product classification · 003 premium/collection
 *   classification · 004 commission reporting mapping
 * No new tables: authorizations are the canonical insurer_regulatory_authorizations, product mappings are
 * product_regulatory_mappings, and every reporting mapping (platform, insurer or broker) is a
 * regulatory_reporting_mappings row scoped by owner_type/owner_id.
 */
final class CimaSetupService
{
    /** screen → [owner type, allowed subject types, target kind] */
    public const SCREENS = [
        'INS-SET-CIMA-004' => ['CARRIER', ['INSURANCE_LINE', 'INSURANCE_PRODUCT'], 'ART_411_CATEGORY'],
        'BRK-SET-CIMA-001' => ['PARTNER', ['REPORTING_MEASURE'], 'ART_557_MEASURE'],
        'BRK-SET-CIMA-002' => ['PARTNER', ['INSURANCE_LINE', 'INSURANCE_PRODUCT'], 'ART_411_CATEGORY'],
        'BRK-SET-CIMA-003' => ['PARTNER', ['PREMIUM_STATUS'], 'ART_557_MEASURE'],
        'BRK-SET-CIMA-004' => ['PARTNER', ['COMMISSION_TYPE'], 'ART_557_MEASURE'],
    ];

    /** Art. 557 measures each broker screen may map to (seeded codes; see cima_regulatory_master_2026.json). */
    public const SCREEN_MEASURES = [
        'BRK-SET-CIMA-003' => ['PREMIUM_WRITTEN', 'PREMIUM_COLLECTED'],
        'BRK-SET-CIMA-004' => ['COMMISSION_RECORDED', 'COMMISSION_COLLECTED', 'COMMISSION_RATE'],
    ];

    public function __construct(
        private readonly CimaAuthorizationService $authorizations,
        private readonly CimaPublicationGuard $guard,
        private readonly OrganizationNameHistoryService $names,
        private readonly AuditWriter $audit,
    ) {}

    /** @return array<string, mixed> INS-SET-CIMA-001…006 for one insurer */
    public function insurerSetup(Carrier $carrier): array
    {
        $auths = InsurerRegulatoryAuthorization::with(['branches', 'registerAuthorization'])->where('carrier_id', $carrier->id)->orderByDesc('effective_from')->get();
        $branches = RegulatoryBranch::current()->orderBy('number')->get();
        $products = InsuranceProduct::where('carrier_id', $carrier->id)->orderBy('code')->get();
        $mappings = ProductRegulatoryMapping::whereIn('insurance_product_id', $products->pluck('id'))->whereIn('status', ['ACTIVE', 'PENDING_APPROVAL'])->get()->groupBy('insurance_product_id');
        $productRows = $products->map(function (InsuranceProduct $p) use ($mappings) {
            $m = $mappings->get($p->id, collect());

            return ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'status' => $p->status,
                'primary' => $m->where('relationship_type', 'PRIMARY')->pluck('branch_code')->values()->all(),
                'accessory' => $m->where('relationship_type', 'ACCESSORY')->pluck('branch_code')->values()->all(),
                'complementary' => $m->where('relationship_type', 'COMPLEMENTARY')->pluck('branch_code')->values()->all(),
                'pending' => $m->where('status', 'PENDING_APPROVAL')->count(),
                'violations' => $this->guard->violations($p, false)];
        })->values();

        return [
            'carrier' => ['id' => $carrier->id, 'name' => $carrier->trade_name ?: $carrier->legal_name ?: $carrier->cima_code, 'canonical_id' => $carrier->canonical_id,
                'licence_branch' => $carrier->licence_branch, 'is_demo' => (bool) $carrier->is_demo, 'name_history' => $this->names->history($carrier)->map->only(['legal_name', 'trade_name', 'effective_from', 'effective_until', 'source'])->all()],
            'INS-SET-CIMA-001' => [
                'register_source' => $this->authorizations->registerSource($carrier)->map->only(['id', 'reference_year', 'branch', 'status', 'source_authority', 'effective_from'])->all(),
                'authorizations' => $auths->map(fn ($a) => ['id' => $a->id, 'reference' => $a->authorization_reference, 'source' => $a->source, 'source_document' => $a->source_document,
                    'status' => $a->status, 'effective_from' => $a->effective_from?->toDateString(), 'effective_until' => $a->effective_until?->toDateString(),
                    'licence_family' => $a->licence_family, 'register_year' => $a->registerAuthorization?->reference_year, 'approval_request_id' => $a->approval_request_id])->all(),
                // Q2 is open: nothing is inferred from the register.
                'unverified' => $auths->where('status', 'ACTIVE')->isEmpty(),
            ],
            'INS-SET-CIMA-002' => $branches->reject->reserved->map(fn ($b) => ['code' => $b->code, 'number' => $b->number, 'label' => $b->label_fr, 'family' => $b->business_family,
                'authorized' => $this->authorizations->isAuthorized($carrier->id, $b->code)])->values()->all(),
            'INS-SET-CIMA-003' => $productRows->map(fn ($r) => array_diff_key($r, ['accessory' => 1, 'complementary' => 1]))->all(),
            'INS-SET-CIMA-004' => $this->mappingsFor('CARRIER', $carrier->id, 'INS-SET-CIMA-004'),
            'INS-SET-CIMA-005' => $productRows->filter(fn ($r) => $r['accessory'] !== [])->map(fn ($r) => ['code' => $r['code'], 'accessory' => $r['accessory']])->values()->all(),
            'INS-SET-CIMA-006' => $productRows->filter(fn ($r) => $r['complementary'] !== [] || array_intersect($r['primary'], ['CIMA_20_LIFE_DEATH', 'CIMA_21_UNIT_LINKED']) !== [])
                ->map(fn ($r) => ['code' => $r['code'], 'primary' => $r['primary'], 'complementary' => $r['complementary']])->values()->all(),
        ];
    }

    /** @return array<string, mixed> BRK-SET-CIMA-001…004 for one intermediary */
    public function brokerSetup(Partner $partner): array
    {
        $measures = RegulatoryReportingCategory::where('kind', 'ART_557_MEASURE')->where('status', 'ACTIVE')->orderBy('sequence')->get(['code', 'label_fr', 'label_en']);
        $configured = collect($this->mappingsFor('PARTNER', $partner->id, 'BRK-SET-CIMA-001'))->pluck('measure_code')->all();

        return [
            'partner' => ['id' => $partner->id, 'name' => $partner->trade_name ?: $partner->legal_name ?: $partner->party?->display_name, 'type' => $partner->type,
                'canonical_id' => $partner->canonical_id, 'licence_number' => $partner->licence_number,
                'register' => $partner->intermediaryAuthorizations()->orderByDesc('reference_year')->get()->map->only(['reference_year', 'intermediary_type', 'status', 'source_authority'])->all(),
                'name_history' => $this->names->history($partner)->map->only(['legal_name', 'trade_name', 'effective_from', 'effective_until', 'source'])->all()],
            'BRK-SET-CIMA-001' => $measures->map(fn ($m) => ['code' => $m->code, 'label' => $m->label_fr, 'label_en' => $m->label_en, 'enabled' => in_array($m->code, $configured, true)])->all(),
            'BRK-SET-CIMA-002' => $this->mappingsFor('PARTNER', $partner->id, 'BRK-SET-CIMA-002'),
            'BRK-SET-CIMA-003' => $this->mappingsFor('PARTNER', $partner->id, 'BRK-SET-CIMA-003'),
            'BRK-SET-CIMA-004' => $this->mappingsFor('PARTNER', $partner->id, 'BRK-SET-CIMA-004'),
        ];
    }

    /**
     * Adds an effective-dated, owner-scoped reporting mapping. A newer mapping for the same subject closes the
     * previous one (effective_until) instead of overwriting it.
     *
     * @param  array{subject_type: string, subject_code: string, target_code: string, effective_from?: string, notes?: string}  $data
     */
    public function addReportingMapping(string $screen, string $ownerId, array $data, User $actor): RegulatoryReportingMapping
    {
        [$ownerType, $subjects, $kind] = self::SCREENS[$screen] ?? throw ValidationException::withMessages(['screen' => ["Unknown setup screen {$screen}."]]);
        $owner = $ownerType === 'CARRIER' ? Carrier::find($ownerId) : Partner::find($ownerId);
        if (! $owner) {
            throw ValidationException::withMessages(['owner' => ['Unknown '.strtolower($ownerType).'.']]);
        }
        if (! in_array($data['subject_type'] ?? null, $subjects, true) || blank($data['subject_code'] ?? null)) {
            throw ValidationException::withMessages(['subject_type' => ['Subject must be one of '.implode(', ', $subjects).'.']]);
        }
        $target = (string) ($data['target_code'] ?? '');
        if (! RegulatoryReportingCategory::where('kind', $kind)->where('code', $target)->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['target_code' => ["{$target} is not an active ".($kind === 'ART_557_MEASURE' ? 'Article 557 measure' : 'Article 411 category').'.']]);
        }
        if (isset(self::SCREEN_MEASURES[$screen]) && ! in_array($target, self::SCREEN_MEASURES[$screen], true)) {
            throw ValidationException::withMessages(['target_code' => ["{$screen} maps to ".implode(' / ', self::SCREEN_MEASURES[$screen]).' only.']]);
        }
        $from = $data['effective_from'] ?? now()->toDateString();

        return DB::transaction(function () use ($screen, $ownerType, $ownerId, $data, $kind, $target, $from, $actor) {
            RegulatoryReportingMapping::where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('setup_screen', $screen)
                ->where('subject_type', $data['subject_type'])->where('subject_code', $data['subject_code'])->whereNull('effective_until')
                ->when($screen === 'BRK-SET-CIMA-001', fn ($q) => $q->where('measure_code', $target))
                ->update(['effective_until' => $from, 'status' => 'ENDED']);
            $m = RegulatoryReportingMapping::create([
                'regime' => 'CIMA', 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'setup_screen' => $screen,
                'subject_type' => $data['subject_type'], 'subject_code' => $data['subject_code'],
                'reporting_category_code' => $kind === 'ART_411_CATEGORY' ? $target : 'ART_557',
                'measure_code' => $kind === 'ART_557_MEASURE' ? $target : null,
                'effective_from' => $from, 'status' => 'ACTIVE', 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
            ]);
            $this->audit->record('regulatory.reporting_mapping.created', 'regulatory_reporting_mapping', $m->id, ['screen' => $screen, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'target' => $target]);

            return $m;
        });
    }

    public function endReportingMapping(RegulatoryReportingMapping $m, User $actor): RegulatoryReportingMapping
    {
        $m->update(['effective_until' => now()->toDateString(), 'status' => 'ENDED']);
        $this->audit->record('regulatory.reporting_mapping.ended', 'regulatory_reporting_mapping', $m->id, ['by' => $actor->id]);

        return $m->refresh();
    }

    /** @return list<array<string, mixed>> */
    public function mappingsFor(string $ownerType, string $ownerId, string $screen): array
    {
        return RegulatoryReportingMapping::where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('setup_screen', $screen)
            ->orderBy('subject_code')->orderByDesc('effective_from')->get()
            ->map(fn ($m) => ['id' => $m->id, 'subject_type' => $m->subject_type, 'subject_code' => $m->subject_code, 'reporting_category_code' => $m->reporting_category_code,
                'measure_code' => $m->measure_code, 'status' => $m->status, 'effective_from' => $m->effective_from?->toDateString(), 'effective_until' => $m->effective_until?->toDateString()])
            ->all();
    }
}
