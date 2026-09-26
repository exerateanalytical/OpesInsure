<?php

declare(strict_types=1);

namespace App\Application\Claims\RepairNetwork;

use App\Application\Audit\AuditWriter;
use App\Application\Claims\Taxonomy\ClaimTaxonomy;
use App\Application\Providers\ProviderRegistry;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agent GP3 — Gap Closure Pack v1 file 03: approved garage network (approved_garage_master, PENDING_INSURER_NETWORK_SOURCE) and
 * technical experts / loss adjusters (technical_experts, official source DGTCFM, PENDING_OFFICIAL_IMPORT).
 *
 * No parallel register: garages and experts are canonical providers (ProviderRegistry, provider_profiles categories GARAGE /
 * EXPERT); insurer networks, contracts and tariff profiles are the existing provider_networks / provider_contracts /
 * provider_tariff_versions. This service adds what the pack requires on top:
 *  - capabilities (garage services incl. bodywork / mechanical / glass / towing / inspection, expert specialties, vehicle makes);
 *  - register provenance: imported rows carry data_status PENDING_VERIFICATION + source; a DIFFERENT user verifies the source
 *    (maker-checker) before credentialing can reach APPROVED (assertSourceVerified) — so no unverified entry can be appointed.
 */
final class RepairNetworkService
{
    public const CATEGORIES = ['GARAGE', 'EXPERT', 'ADJUSTER', 'SURVEYOR'];

    /** Statuses of an imported register entry that block credentialing. */
    public const PENDING = ['PENDING_VERIFICATION', 'PENDING_OFFICIAL_IMPORT', 'PENDING_PRIVATE_SOURCE'];

    /** Verified status by category: experts come from the public DGTCFM list, garages from the insurer's private network. */
    public const VERIFIED = ['EXPERT' => 'VERIFIED_PUBLIC_SOURCE', 'ADJUSTER' => 'VERIFIED_PUBLIC_SOURCE', 'SURVEYOR' => 'VERIFIED_PUBLIC_SOURCE', 'GARAGE' => 'VERIFIED'];

    public const DGTCFM_EXPERTS_URL = 'https://dgtcfm.cm/les-acteurs-de-la-profession/liste-des-experts-techniques/';

    /** Pack garage boolean columns => partners.garage_service code. */
    public const GARAGE_FLAGS = ['bodywork' => 'BODYWORK', 'mechanical' => 'MECHANICAL_REPAIR', 'glass' => 'WINDSCREEN', 'towing' => 'TOWING', 'inspection' => 'INSPECTION'];

    /** Pack expert specialties => partners.adjuster_type (seeded as aliases in workflow_gap_closure_motor_claims_2026.json). */
    public const EXPERT_SPECIALTIES = [
        'AUTOMOBILE' => 'MOTOR_EXPERT', 'FIRE_RISK' => 'PROPERTY_FIRE_EXPERT', 'PROPERTY' => 'PROPERTY_FIRE_EXPERT', 'MARINE' => 'MARINE_SURVEYOR',
        'ENGINEERING' => 'ENGINEERING_EXPERT', 'MEDICAL' => 'MEDICAL_EXPERT', 'VALUATION' => 'VALUER', 'INVESTIGATION' => 'INVESTIGATOR',
        'MISCELLANEOUS_DAMAGE' => 'MISCELLANEOUS_DAMAGE_EXPERT', 'INDUSTRIAL_EQUIPMENT' => 'INDUSTRIAL_EQUIPMENT_EXPERT',
    ];

    public function __construct(private readonly ProviderRegistry $providers, private readonly AuditWriter $audit) {}

    /** Called by ProviderRegistry::transition: an unverified imported garage / expert cannot be approved. */
    public static function assertSourceVerified(object $profile, string $to): void
    {
        if ($to === 'APPROVED' && in_array($profile->category, self::CATEGORIES, true) && in_array($profile->data_status ?? null, self::PENDING, true)) {
            throw new ApiProblemException('PROVIDER_SOURCE_UNVERIFIED', 409,
                "This {$profile->category} register entry is {$profile->data_status}: its source must be verified before approval.");
        }
    }

    public static function specialtyCode(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return self::EXPERT_SPECIALTIES[$code] ?? (in_array($code, self::EXPERT_SPECIALTIES, true) || self::listHas('adjuster_type', $code) ? $code : null);
    }

    private static function listHas(string $list, string $code): bool
    {
        return DB::table('master_data_values')->where(['domain_code' => 'partners', 'list_code' => $list, 'code' => $code, 'status' => 'ACTIVE'])->exists();
    }

    /**
     * Registers an imported register entry (garage or expert) as a canonical provider with its provenance and capabilities.
     *
     * @param  array<string, mixed>  $d  category, name, provider_type_code, trade_name, registration_number, decision_reference, city, region,
     *                                   address, phones, emails, source_url, source, effective_from, services, specialties, vehicle_makes
     */
    public function registerEntry(array $d, ?string $actorId): object
    {
        return DB::transaction(function () use ($d, $actorId) {
            $p = $this->providers->register([
                'category' => $d['category'], 'name' => $d['name'], 'provider_type_code' => $d['provider_type_code'],
                'registration_number' => $d['registration_number'] ?? null, 'city_code' => $d['city'] ?? null, 'region_code' => $d['region'] ?? null,
                'details' => array_filter(['address' => $d['address'] ?? null, 'source' => $d['source'] ?? null]),
            ], $actorId);
            DB::table('provider_profiles')->where('id', $p->id)->update(array_filter([
                'official_name' => $d['name'], 'trade_name' => $d['trade_name'] ?? null, 'decision_reference' => $d['decision_reference'] ?? null,
                'phones' => json_encode(array_values($d['phones'] ?? [])), 'emails' => json_encode(array_values($d['emails'] ?? [])),
                'source_url' => $d['source_url'] ?? null, 'data_status' => $d['data_status'] ?? 'PENDING_VERIFICATION',
                'data_source' => $d['source'] ?? ClaimTaxonomy::SOURCE, 'effective_from' => $d['effective_from'] ?? now()->toDateString(), 'updated_at' => now(),
            ], fn ($v) => $v !== null));
            $this->setCapabilities($p->id, 'SERVICE', $d['services'] ?? [], $actorId, $d['source'] ?? null);
            $this->setCapabilities($p->id, 'SPECIALTY', $d['specialties'] ?? [], $actorId, $d['source'] ?? null);
            $this->setCapabilities($p->id, 'VEHICLE_MAKE', $d['vehicle_makes'] ?? [], $actorId, $d['source'] ?? null);

            return $this->show($p->id);
        });
    }

    /** Adds capabilities (idempotent). Codes are validated against their canonical list. @param list<string> $codes */
    public function setCapabilities(string $providerId, string $kind, array $codes, ?string $actorId, ?string $source = null): array
    {
        $p = $this->profile($providerId);
        $now = now();
        foreach (array_unique(array_filter(array_map(fn ($c) => strtoupper(trim((string) $c)), $codes))) as $code) {
            $canonical = match ($kind) {
                'SERVICE' => self::listHas('garage_service', $code) ? $code : null,
                'SPECIALTY' => self::specialtyCode($code),
                'VEHICLE_MAKE' => DB::table('vehicle_makes')->where('code', $code)->exists() ? $code : null,
                default => throw new ApiProblemException('CAPABILITY_KIND_UNKNOWN', 422, "Unknown capability kind $kind."),
            };
            if ($canonical === null) {
                throw new ApiProblemException('CAPABILITY_CODE_UNKNOWN', 422, "Unknown $kind code $code.", [], ['kind' => $kind, 'code' => $code]);
            }
            if ($kind === 'SERVICE' && $p->category !== 'GARAGE') {
                throw new ApiProblemException('CAPABILITY_NOT_ALLOWED', 422, 'Garage services apply to GARAGE providers only.');
            }
            DB::table('provider_capabilities')->insertOrIgnore(['id' => (string) Str::uuid(), 'provider_profile_id' => $providerId, 'kind' => $kind,
                'code' => $canonical, 'source' => $source === null ? null : mb_substr($source, 0, 64), 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
        }

        return $this->capabilities($providerId);
    }

    /** @return array<string, list<string>> */
    public function capabilities(string $providerId): array
    {
        return DB::table('provider_capabilities')->where('provider_profile_id', $providerId)->orderBy('code')->get()
            ->groupBy('kind')->map(fn ($g) => $g->pluck('code')->all())->all() + ['SERVICE' => [], 'SPECIALTY' => [], 'VEHICLE_MAKE' => []];
    }

    /** Maker-checker verification of the register entry's source (DGTCFM list / insurer network agreement). */
    public function verifySource(string $providerId, array $d, User $checker): object
    {
        return DB::transaction(function () use ($providerId, $d, $checker) {
            $p = DB::table('provider_profiles')->where('id', $providerId)->lockForUpdate()->first()
                ?? throw new ApiProblemException('PROVIDER_NOT_FOUND', 404, 'Provider not found.');
            if (! in_array($p->category, self::CATEGORIES, true)) {
                throw new ApiProblemException('PROVIDER_NOT_REPAIR_NETWORK', 422, 'Only garages and experts are verified here.');
            }
            if (! in_array($p->data_status, self::PENDING, true)) {
                throw new ApiProblemException('PROVIDER_NOT_PENDING_VERIFICATION', 409, 'This register entry is not pending verification.');
            }
            if ($p->created_by !== null && $p->created_by === $checker->id) {
                throw new ApiProblemException('MAKER_CHECKER_VIOLATION', 403, 'The user who registered or imported the entry cannot verify it.');
            }
            $sourceUrl = $d['source_url'] ?? $p->source_url;
            $reference = $d['source_reference'] ?? $p->decision_reference;
            if (! $sourceUrl && ! $reference) {
                throw new ApiProblemException('SOURCE_REQUIRED', 422, 'A source URL or reference (decision / network agreement) is required to verify.');
            }
            $status = self::VERIFIED[$p->category];
            DB::table('provider_profiles')->where('id', $providerId)->update(['data_status' => $status, 'source_url' => $sourceUrl,
                'decision_reference' => $reference, 'verified_at' => now(), 'verified_by' => $checker->id, 'updated_at' => now()]);
            $this->audit->record('provider.source_verified', 'provider', $providerId, ['from' => $p->data_status, 'to' => $status, 'source_url' => $sourceUrl, 'reference' => $reference]);

            return $this->show($providerId);
        });
    }

    public function show(string $providerId): object
    {
        $p = $this->providers->find($providerId);
        $p->capabilities = $this->capabilities($providerId);
        $p->networks = DB::table('provider_network_memberships as m')->join('provider_networks as n', 'n.id', '=', 'm.provider_network_id')
            ->where('m.provider_profile_id', $providerId)->select('n.id', 'n.code', 'n.name', 'n.tenant_id', 'm.status')->get()->all();
        $p->contract_ids = DB::table('provider_contracts')->where('provider_profile_id', $providerId)->pluck('id')->all();
        $p->tariff_profile_ids = DB::table('provider_tariff_versions')->whereIn('provider_contract_id', $p->contract_ids ?: ['00000000-0000-0000-0000-000000000000'])->pluck('id')->all();
        $p->production_usable = ! in_array($p->data_status ?? null, self::PENDING, true);

        return $p;
    }

    /** @return list<object> */
    public function list(string $category, array $f): array
    {
        return DB::table('provider_profiles as p')->join('parties', 'parties.id', '=', 'p.party_id')->where('p.category', $category)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('p.credentialing_status', $v))
            ->when($f['data_status'] ?? null, fn ($q, $v) => $q->where('p.data_status', $v))
            ->when($f['city'] ?? null, fn ($q, $v) => $q->where('p.city_code', strtoupper($v)))
            ->when($f['capability'] ?? null, fn ($q, $v) => $q->whereExists(fn ($s) => $s->from('provider_capabilities as c')
                ->whereColumn('c.provider_profile_id', 'p.id')->where('c.code', strtoupper($v))))
            ->orderBy('parties.display_name')->limit(500)
            ->get(['p.id', 'parties.display_name as name', 'p.trade_name', 'p.provider_type_code', 'p.credentialing_status', 'p.data_status', 'p.city_code',
                'p.region_code', 'p.registration_number', 'p.decision_reference', 'p.source_url', 'p.verified_at'])
            ->each(fn ($r) => $r->capabilities = $this->capabilities($r->id))->all();
    }

    private function profile(string $id): object
    {
        return DB::table('provider_profiles')->where('id', $id)->first() ?? throw new ApiProblemException('PROVIDER_NOT_FOUND', 404, 'Provider not found.');
    }
}
