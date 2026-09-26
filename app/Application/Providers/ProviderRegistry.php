<?php

declare(strict_types=1);

namespace App\Application\Providers;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Party;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-PRV-001 / REQ-PRV-004 — canonical provider master.
 *
 * A provider is a party (golden record) + a partners row (canonical partner, typed) + a provider_profiles row with
 * the credentialing machine PROSPECT → APPLICATION → UNDER_REVIEW → APPROVED → ACTIVE → SUSPENDED → TERMINATED.
 * Canonical providers are platform-wide: an insurer never gets its own copy (it links via networks and contracts).
 * Relationships to insurers / other parties are explicit rows in party_relationships (REQ-PTY-003).
 */
final class ProviderRegistry
{
    /** provider category => partners.type */
    public const CATEGORIES = [
        'HEALTH' => 'HEALTH_PROVIDER', 'GARAGE' => 'GARAGE', 'ADJUSTER' => 'ADJUSTER', 'EXPERT' => 'EXPERT', 'SURVEYOR' => 'SURVEYOR',
    ];

    public const TRANSITIONS = [
        'PROSPECT' => ['APPLICATION', 'TERMINATED'],
        'APPLICATION' => ['UNDER_REVIEW', 'TERMINATED'],
        'UNDER_REVIEW' => ['APPROVED', 'APPLICATION', 'TERMINATED'],
        'APPROVED' => ['ACTIVE', 'TERMINATED'],
        'ACTIVE' => ['SUSPENDED', 'TERMINATED'],
        'SUSPENDED' => ['ACTIVE', 'TERMINATED'],
        'TERMINATED' => [],
    ];

    /** Explicit provider relationship types (REQ-PRV-004), stored in party_relationships.type. */
    public const RELATIONSHIP_TYPES = [
        'APPROVED_REPAIRER_FOR', 'PANEL_ADJUSTER_FOR', 'PANEL_EXPERT_FOR', 'NETWORK_PROVIDER_FOR', 'SUBCONTRACTOR_OF', 'AFFILIATED_WITH', 'EMPLOYED_BY',
    ];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /**
     * @param array{category: string, name: string, provider_type_code: string, party_id?: ?string, registration_number?: ?string,
     *              city_code?: ?string, region_code?: ?string, legacy_health_provider_id?: ?string, details?: array} $d
     */
    public function register(array $d, ?string $actorId): object
    {
        $category = $d['category'];
        if (! isset(self::CATEGORIES[$category])) {
            throw new ApiProblemException('PROVIDER_CATEGORY_UNKNOWN', 422, "Unknown provider category {$category}.");
        }

        return DB::transaction(function () use ($d, $category, $actorId) {
            if (! empty($d['party_id'])) {
                $party = Party::findOrFail($d['party_id']);
            } else {
                $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $d['name'], 'status' => 'ACTIVE']);
            }
            $partnerType = self::CATEGORIES[$category];
            $existing = Partner::where('party_id', $party->id)->where('type', $partnerType)->first();
            if ($existing && DB::table('provider_profiles')->where('partner_id', $existing->id)->exists()) {
                throw new ApiProblemException('PROVIDER_EXISTS', 409, 'This party is already registered as a provider of this category.');
            }
            $partner = $existing ?? Partner::create([
                'tenant_id' => null, 'party_id' => $party->id, 'type' => $partnerType, 'status' => 'PENDING',
                'legal_name' => $d['name'], 'slug' => Str::slug($d['name']).'-'.Str::lower(Str::random(6)), 'data_origin' => 'PROVIDER_MASTER',
            ]);
            $id = (string) Str::uuid();
            DB::table('provider_profiles')->insert([
                'id' => $id, 'partner_id' => $partner->id, 'party_id' => $party->id, 'category' => $category,
                'provider_type_code' => strtoupper($d['provider_type_code']), 'credentialing_status' => 'PROSPECT',
                'legacy_health_provider_id' => $d['legacy_health_provider_id'] ?? null, 'registration_number' => $d['registration_number'] ?? null,
                'city_code' => $d['city_code'] ?? null, 'region_code' => $d['region_code'] ?? null,
                'details' => json_encode($d['details'] ?? [], JSON_THROW_ON_ERROR), 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($id, null, 'PROSPECT', 'Registered', null, $actorId);
            $this->audit->record('provider.registered', 'provider', $id, ['party_id' => $party->id, 'partner_id' => $partner->id, 'category' => $category]);
            $this->outbox->record('provider.registered', 'provider', $id, ['provider_id' => $id, 'party_id' => $party->id, 'category' => $category]);

            return $this->find($id);
        });
    }

    public function find(string $id): object
    {
        $p = DB::table('provider_profiles as p')->join('parties', 'parties.id', '=', 'p.party_id')
            ->where('p.id', $id)->select('p.*', 'parties.display_name as name')->first();
        if (! $p) {
            throw new ApiProblemException('PROVIDER_NOT_FOUND', 404, 'Provider not found.');
        }
        $p->details = json_decode((string) $p->details, true);

        return $p;
    }

    public function list(array $filters): array
    {
        return DB::table('provider_profiles as p')->join('parties', 'parties.id', '=', 'p.party_id')
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('p.category', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('p.credentialing_status', $v))
            ->orderBy('parties.display_name')->select('p.id', 'p.category', 'p.provider_type_code', 'p.credentialing_status', 'p.party_id', 'p.partner_id', 'parties.display_name as name')
            ->limit(500)->get()->all();
    }

    public function transition(string $id, string $to, ?string $reason, ?string $evidence, ?string $actorId): object
    {
        return DB::transaction(function () use ($id, $to, $reason, $evidence, $actorId) {
            $p = DB::table('provider_profiles')->where('id', $id)->lockForUpdate()->first();
            if (! $p) {
                throw new ApiProblemException('PROVIDER_NOT_FOUND', 404, 'Provider not found.');
            }
            $from = $p->credentialing_status;
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new ApiProblemException('PROVIDER_TRANSITION_INVALID', 409, "Cannot move a provider from {$from} to {$to}.", [], ['from' => $from, 'to' => $to, 'allowed' => self::TRANSITIONS[$from] ?? []]);
            }
            // Gap pack 03: an imported garage / expert register entry is approved only once its source is verified.
            \App\Application\Claims\RepairNetwork\RepairNetworkService::assertSourceVerified($p, $to);
            if (in_array($to, ['SUSPENDED', 'TERMINATED'], true) && trim((string) $reason) === '') {
                throw new ApiProblemException('REASON_REQUIRED', 422, "A reason is required to move a provider to {$to}.");
            }
            DB::table('provider_profiles')->where('id', $id)->update(['credentialing_status' => $to, 'updated_at' => now()]);
            $partnerStatus = match ($to) { 'ACTIVE' => 'ACTIVE', 'SUSPENDED' => 'SUSPENDED', 'TERMINATED' => 'TERMINATED', default => 'PENDING' };
            DB::table('partners')->where('id', $p->partner_id)->update(['status' => $partnerStatus, 'updated_at' => now()]);
            if ($to === 'TERMINATED') {
                DB::table('provider_network_memberships')->where('provider_profile_id', $id)->where('status', 'ACTIVE')
                    ->update(['status' => 'ENDED', 'effective_to' => DB::raw("GREATEST(effective_from + 1, CURRENT_DATE)"), 'end_reason' => 'Provider terminated: '.$reason, 'updated_at' => now()]);
            }
            $this->event($id, $from, $to, $reason, $evidence, $actorId);
            $this->audit->record('provider.credentialing_changed', 'provider', $id, ['from' => $from, 'to' => $to], $reason);
            $this->outbox->record('provider.credentialing_changed', 'provider', $id, ['provider_id' => $id, 'from' => $from, 'to' => $to]);

            return $this->find($id);
        });
    }

    public function history(string $id): array
    {
        return DB::table('provider_credentialing_events')->where('provider_profile_id', $id)->orderBy(DB::getDriverName() === 'pgsql' ? 'seq' : 'occurred_at')->get()->all();
    }

    /** @param array{code: string, name: string, facility_type_code?: ?string, city_code?: ?string, region_code?: ?string, address?: ?string, specialties?: list<string>} $d */
    public function addFacility(string $providerId, array $d): object
    {
        $p = $this->find($providerId);
        if ($p->credentialing_status === 'TERMINATED') {
            throw new ApiProblemException('PROVIDER_TERMINATED', 409, 'A terminated provider cannot receive facilities.');
        }
        if (DB::table('provider_facilities')->where('provider_profile_id', $providerId)->where('code', $d['code'])->exists()) {
            throw new ApiProblemException('FACILITY_EXISTS', 409, "Facility {$d['code']} already exists for this provider.");
        }

        return DB::transaction(function () use ($providerId, $d) {
            $id = (string) Str::uuid();
            DB::table('provider_facilities')->insert([
                'id' => $id, 'provider_profile_id' => $providerId, 'code' => $d['code'], 'name' => $d['name'],
                'facility_type_code' => $d['facility_type_code'] ?? null, 'city_code' => $d['city_code'] ?? null, 'region_code' => $d['region_code'] ?? null,
                'address' => $d['address'] ?? null, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_unique(array_map('strtoupper', $d['specialties'] ?? [])) as $s) {
                DB::table('provider_facility_specialties')->insert(['id' => (string) Str::uuid(), 'provider_facility_id' => $id, 'specialty_code' => $s, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('provider.facility_added', 'provider', $providerId, ['facility_id' => $id, 'code' => $d['code']]);

            return $this->facility($id);
        });
    }

    public function facility(string $id): object
    {
        $f = DB::table('provider_facilities')->where('id', $id)->first() ?? throw new ApiProblemException('FACILITY_NOT_FOUND', 404, 'Facility not found.');
        $f->specialties = DB::table('provider_facility_specialties')->where('provider_facility_id', $id)->orderBy('specialty_code')->pluck('specialty_code')->all();
        $f->services = DB::table('provider_facility_services as fs')->join('medical_services as m', 'm.id', '=', 'fs.medical_service_id')
            ->where('fs.provider_facility_id', $id)->orderBy('m.code')->select('m.id', 'm.code', 'm.name', 'fs.specialty_code')->get()->all();

        return $f;
    }

    /** Provider → facility → specialty → service tree. */
    public function tree(string $providerId): object
    {
        $p = $this->find($providerId);
        $p->facilities = DB::table('provider_facilities')->where('provider_profile_id', $providerId)->orderBy('code')->pluck('id')
            ->map(fn ($id) => $this->facility($id))->all();
        $p->relationships = $this->relationships($providerId);

        return $p;
    }

    public function addFacilityService(string $facilityId, string $medicalServiceId, ?string $specialtyCode): object
    {
        $f = $this->facility($facilityId);
        if ($specialtyCode !== null && ! in_array(strtoupper($specialtyCode), $f->specialties, true)) {
            throw new ApiProblemException('SPECIALTY_NOT_AT_FACILITY', 422, 'The facility does not offer this specialty.');
        }
        if (! DB::table('medical_services')->where('id', $medicalServiceId)->where('status', 'ACTIVE')->exists()) {
            throw new ApiProblemException('MEDICAL_SERVICE_UNKNOWN', 422, 'Unknown or inactive medical service.');
        }
        DB::table('provider_facility_services')->insertOrIgnore(['id' => (string) Str::uuid(), 'provider_facility_id' => $facilityId, 'medical_service_id' => $medicalServiceId,
            'specialty_code' => $specialtyCode ? strtoupper($specialtyCode) : null, 'created_at' => now(), 'updated_at' => now()]);

        return $this->facility($facilityId);
    }

    /** REQ-PRV-004 — explicit relationship from the provider's party to another party (typically an insurer's). */
    public function relate(string $providerId, string $toPartyId, string $type, ?string $validFrom, ?string $validTo, ?string $tenantId, ?string $actorId): object
    {
        $p = $this->find($providerId);
        if (! in_array($type, self::RELATIONSHIP_TYPES, true)) {
            throw new ApiProblemException('RELATIONSHIP_TYPE_UNKNOWN', 422, "Unknown provider relationship type {$type}.", [], ['allowed' => self::RELATIONSHIP_TYPES]);
        }
        if ($toPartyId === $p->party_id) {
            throw new ApiProblemException('RELATIONSHIP_SELF', 422, 'A provider cannot be related to itself.');
        }
        Party::findOrFail($toPartyId);
        $dup = DB::table('party_relationships')->where('from_party_id', $p->party_id)->where('to_party_id', $toPartyId)->where('type', $type)
            ->where('status', 'ACTIVE')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->exists();
        if ($dup) {
            throw new ApiProblemException('RELATIONSHIP_EXISTS', 409, 'This relationship already exists.');
        }
        $id = (string) Str::uuid();
        DB::table('party_relationships')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'from_party_id' => $p->party_id, 'to_party_id' => $toPartyId, 'type' => $type,
            'valid_from' => $validFrom, 'valid_to' => $validTo, 'status' => 'ACTIVE',
            'details' => json_encode(['provider_id' => $providerId, 'source' => 'PROVIDER_MASTER'], JSON_THROW_ON_ERROR), 'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('provider.relationship_added', 'provider', $providerId, ['relationship_id' => $id, 'to_party_id' => $toPartyId, 'type' => $type]);
        $this->outbox->record('provider.relationship_added', 'provider', $providerId, ['provider_id' => $providerId, 'to_party_id' => $toPartyId, 'type' => $type]);

        return DB::table('party_relationships')->where('id', $id)->first();
    }

    public function relationships(string $providerId): array
    {
        $p = DB::table('provider_profiles')->where('id', $providerId)->first();

        return DB::table('party_relationships')->where('from_party_id', $p->party_id)->whereIn('type', self::RELATIONSHIP_TYPES)
            ->orderBy('type')->get()->all();
    }

    private function event(string $id, ?string $from, string $to, ?string $reason, ?string $evidence, ?string $actorId): void
    {
        DB::table('provider_credentialing_events')->insert([
            'id' => (string) Str::uuid(), 'provider_profile_id' => $id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason,
            'evidence_reference' => $evidence, 'actor_id' => $actorId, 'occurred_at' => now(),
        ]);
    }
}
