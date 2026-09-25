<?php

declare(strict_types=1);

namespace App\Application\Providers;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Interfaces\Http\Errors\ApiProblemException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-PRV-002 — insurer-named networks, dated memberships, contracts, medical service catalogue with provider code
 * mapping and versioned tariffs (price, contracted price, copay, insurer share). Tariffs attach to a contract, never
 * to a copy of the provider. An approved tariff version is frozen (DB trigger); a change is a new version, approved
 * by a different user (maker-checker).
 */
final class ProviderNetworkService
{
    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox, private readonly ProviderRegistry $providers) {}

    // ----------------------------------------------------------------- medical service catalogue

    public function addMedicalService(array $d): object
    {
        $code = strtoupper($d['code']);
        if (DB::table('medical_services')->where('code', $code)->exists()) {
            throw new ApiProblemException('MEDICAL_SERVICE_EXISTS', 409, "Medical service {$code} already exists.");
        }
        $id = (string) Str::uuid();
        DB::table('medical_services')->insert(['id' => $id, 'code' => $code, 'name' => $d['name'], 'category_code' => strtoupper($d['category_code']),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('medical_service.added', 'medical_service', $id, ['code' => $code]);

        return DB::table('medical_services')->where('id', $id)->first();
    }

    public function medicalServices(?string $category): array
    {
        return DB::table('medical_services')->when($category, fn ($q, $v) => $q->where('category_code', $v))->orderBy('code')->get()->all();
    }

    public function mapProviderCode(string $providerId, string $providerCode, string $medicalServiceId): object
    {
        $this->providers->find($providerId);
        if (! DB::table('medical_services')->where('id', $medicalServiceId)->exists()) {
            throw new ApiProblemException('MEDICAL_SERVICE_UNKNOWN', 422, 'Unknown medical service.');
        }
        $existing = DB::table('provider_service_code_mappings')->where('provider_profile_id', $providerId)->where('provider_code', $providerCode)->first();
        if ($existing && $existing->medical_service_id !== $medicalServiceId) {
            throw new ApiProblemException('PROVIDER_CODE_MAPPED', 409, "Provider code {$providerCode} is already mapped to another service.");
        }
        if (! $existing) {
            DB::table('provider_service_code_mappings')->insert(['id' => (string) Str::uuid(), 'provider_profile_id' => $providerId, 'provider_code' => $providerCode,
                'medical_service_id' => $medicalServiceId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('provider.code_mapped', 'provider', $providerId, ['provider_code' => $providerCode, 'medical_service_id' => $medicalServiceId]);
        }

        return DB::table('provider_service_code_mappings')->where('provider_profile_id', $providerId)->where('provider_code', $providerCode)->first();
    }

    public function resolveProviderCode(string $providerId, string $providerCode): ?object
    {
        return DB::table('provider_service_code_mappings as m')->join('medical_services as s', 's.id', '=', 'm.medical_service_id')
            ->where('m.provider_profile_id', $providerId)->where('m.provider_code', $providerCode)->select('s.*')->first();
    }

    // ----------------------------------------------------------------- networks & memberships

    public function createNetwork(string $tenantId, array $d, ?string $actorId): object
    {
        $code = strtoupper($d['code']);
        if (DB::table('provider_networks')->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
            throw new ApiProblemException('NETWORK_EXISTS', 409, "Network {$code} already exists.");
        }
        $id = (string) Str::uuid();
        DB::table('provider_networks')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'carrier_id' => $d['carrier_id'] ?? null, 'code' => $code, 'name' => $d['name'],
            'network_type_code' => strtoupper($d['network_type_code']), 'category' => $d['category'] ?? 'HEALTH', 'status' => 'ACTIVE',
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('provider_network.created', 'provider_network', $id, ['code' => $code]);
        $this->outbox->record('provider_network.created', 'provider_network', $id, ['network_id' => $id, 'tenant_id' => $tenantId, 'code' => $code]);

        return $this->network($tenantId, $id);
    }

    public function network(string $tenantId, string $id): object
    {
        return DB::table('provider_networks')->where('tenant_id', $tenantId)->where('id', $id)->first()
            ?? throw new ApiProblemException('NETWORK_NOT_FOUND', 404, 'Network not found.');
    }

    public function networks(string $tenantId): array
    {
        return DB::table('provider_networks')->where('tenant_id', $tenantId)->orderBy('code')->get()->all();
    }

    public function addMember(string $tenantId, string $networkId, array $d, ?string $actorId): object
    {
        $net = $this->network($tenantId, $networkId);
        $p = $this->providers->find($d['provider_id']);
        if ($p->credentialing_status !== 'ACTIVE') {
            throw new ApiProblemException('PROVIDER_NOT_ACTIVE', 409, "Only ACTIVE providers can join a network (provider is {$p->credentialing_status}).");
        }
        if ($p->category !== $net->category) {
            throw new ApiProblemException('PROVIDER_CATEGORY_MISMATCH', 422, "A {$p->category} provider cannot join a {$net->category} network.");
        }
        $facilityId = $d['facility_id'] ?? null;
        if ($facilityId && ! DB::table('provider_facilities')->where('id', $facilityId)->where('provider_profile_id', $p->id)->exists()) {
            throw new ApiProblemException('FACILITY_NOT_FOUND', 422, 'The facility does not belong to this provider.');
        }
        $from = CarbonImmutable::parse($d['effective_from'])->toDateString();
        $to = isset($d['effective_to']) ? CarbonImmutable::parse($d['effective_to'])->toDateString() : null;
        if ($to !== null && $to <= $from) {
            throw new ApiProblemException('DATES_INVALID', 422, 'effective_to must be after effective_from.');
        }

        return DB::transaction(function () use ($networkId, $p, $facilityId, $from, $to, $actorId) {
            $overlap = DB::table('provider_network_memberships')->where('provider_network_id', $networkId)->where('provider_profile_id', $p->id)
                ->where('status', 'ACTIVE')->where(fn ($q) => $facilityId ? $q->where('provider_facility_id', $facilityId) : $q->whereNull('provider_facility_id'))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
                ->when($to, fn ($q) => $q->where('effective_from', '<', $to))->lockForUpdate()->exists();
            if ($overlap) {
                throw new ApiProblemException('MEMBERSHIP_OVERLAP', 409, 'The provider already has a membership in this network for that period.');
            }
            $id = (string) Str::uuid();
            DB::table('provider_network_memberships')->insert([
                'id' => $id, 'provider_network_id' => $networkId, 'provider_profile_id' => $p->id, 'provider_facility_id' => $facilityId,
                'effective_from' => $from, 'effective_to' => $to, 'status' => 'ACTIVE', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('provider_network.member_added', 'provider_network', $networkId, ['membership_id' => $id, 'provider_id' => $p->id, 'effective_from' => $from]);
            $this->outbox->record('provider_network.member_added', 'provider_network', $networkId, ['membership_id' => $id, 'provider_id' => $p->id]);

            return DB::table('provider_network_memberships')->where('id', $id)->first();
        });
    }

    public function endMembership(string $tenantId, string $membershipId, string $effectiveTo, string $reason): object
    {
        $m = DB::table('provider_network_memberships as m')->join('provider_networks as n', 'n.id', '=', 'm.provider_network_id')
            ->where('n.tenant_id', $tenantId)->where('m.id', $membershipId)->select('m.*')->first()
            ?? throw new ApiProblemException('MEMBERSHIP_NOT_FOUND', 404, 'Membership not found.');
        $to = CarbonImmutable::parse($effectiveTo)->toDateString();
        if ($m->status !== 'ACTIVE' || $to <= $m->effective_from || ($m->effective_to && $to > $m->effective_to)) {
            throw new ApiProblemException('MEMBERSHIP_END_INVALID', 409, 'effective_to must fall inside the current active membership.');
        }
        DB::table('provider_network_memberships')->where('id', $membershipId)->update(['effective_to' => $to, 'end_reason' => $reason, 'updated_at' => now()]);
        $this->audit->record('provider_network.member_ended', 'provider_network', $m->provider_network_id, ['membership_id' => $membershipId, 'effective_to' => $to], $reason);

        return DB::table('provider_network_memberships')->where('id', $membershipId)->first();
    }

    public function members(string $tenantId, string $networkId, ?string $asOf): array
    {
        $this->network($tenantId, $networkId);
        $d = CarbonImmutable::parse($asOf ?? now())->toDateString();

        return DB::table('provider_network_memberships as m')->join('provider_profiles as p', 'p.id', '=', 'm.provider_profile_id')
            ->join('parties', 'parties.id', '=', 'p.party_id')
            ->where('m.provider_network_id', $networkId)->where('m.effective_from', '<=', $d)
            ->where(fn ($q) => $q->whereNull('m.effective_to')->orWhere('m.effective_to', '>', $d))
            ->where('p.credentialing_status', 'ACTIVE')
            ->orderBy('parties.display_name')->select('m.*', 'parties.display_name as provider_name', 'p.category')->get()->all();
    }

    /** Is the provider in-network for this insurer's network on a date? (feeds REQ-HLT-001 eligibility). */
    public function isInNetwork(string $tenantId, string $networkId, string $providerId, ?string $asOf = null): bool
    {
        return collect($this->members($tenantId, $networkId, $asOf))->contains(fn ($m) => $m->provider_profile_id === $providerId);
    }

    // ----------------------------------------------------------------- contracts & tariffs

    public function createContract(string $tenantId, string $networkId, array $d, ?string $actorId): object
    {
        $this->network($tenantId, $networkId);
        $p = $this->providers->find($d['provider_id']);
        if (in_array($p->credentialing_status, ['TERMINATED', 'SUSPENDED'], true)) {
            throw new ApiProblemException('PROVIDER_NOT_CONTRACTABLE', 409, "A {$p->credentialing_status} provider cannot be contracted.");
        }
        if (DB::table('provider_contracts')->where('tenant_id', $tenantId)->where('contract_number', $d['contract_number'])->exists()) {
            throw new ApiProblemException('CONTRACT_EXISTS', 409, 'Contract number already used.');
        }
        $id = (string) Str::uuid();
        DB::table('provider_contracts')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'provider_network_id' => $networkId, 'provider_profile_id' => $p->id,
            'contract_number' => $d['contract_number'], 'effective_from' => $d['effective_from'], 'effective_to' => $d['effective_to'] ?? null,
            'settlement_mode' => $d['settlement_mode'] ?? 'CASHLESS', 'status' => 'ACTIVE', 'document_reference' => $d['document_reference'] ?? null,
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('provider_contract.created', 'provider_contract', $id, ['provider_id' => $p->id, 'network_id' => $networkId]);
        $this->outbox->record('provider_contract.created', 'provider_contract', $id, ['contract_id' => $id, 'provider_id' => $p->id]);

        return $this->contract($tenantId, $id);
    }

    public function contract(string $tenantId, string $id): object
    {
        return DB::table('provider_contracts')->where('tenant_id', $tenantId)->where('id', $id)->first()
            ?? throw new ApiProblemException('CONTRACT_NOT_FOUND', 404, 'Contract not found.');
    }

    /** @param list<array{medical_service_id: string, price_minor: int, contracted_price_minor: int, copay_minor?: int, insurer_share_percent: float|int|string}> $lines */
    public function draftTariff(string $tenantId, string $contractId, string $effectiveFrom, string $currency, array $lines, ?string $actorId): object
    {
        $c = $this->contract($tenantId, $contractId);
        if ($lines === []) {
            throw new ApiProblemException('TARIFF_EMPTY', 422, 'A tariff version needs at least one line.');
        }
        foreach ($lines as $l) {
            $copay = (int) ($l['copay_minor'] ?? 0);
            if ($l['contracted_price_minor'] > $l['price_minor'] || $copay > $l['contracted_price_minor'] || $l['insurer_share_percent'] < 0 || $l['insurer_share_percent'] > 100) {
                throw new ApiProblemException('TARIFF_LINE_INVALID', 422, 'contracted_price ≤ price, copay ≤ contracted_price, insurer share 0–100.');
            }
        }

        return DB::transaction(function () use ($c, $effectiveFrom, $currency, $lines, $actorId, $tenantId) {
            DB::table('provider_contracts')->where('id', $c->id)->lockForUpdate()->first();
            $version = (int) DB::table('provider_tariff_versions')->where('provider_contract_id', $c->id)->max('version') + 1;
            $id = (string) Str::uuid();
            DB::table('provider_tariff_versions')->insert([
                'id' => $id, 'provider_contract_id' => $c->id, 'version' => $version, 'effective_from' => $effectiveFrom, 'currency' => strtoupper($currency),
                'status' => 'DRAFT', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $l) {
                DB::table('provider_tariff_lines')->insert([
                    'id' => (string) Str::uuid(), 'provider_tariff_version_id' => $id, 'medical_service_id' => $l['medical_service_id'],
                    'price_minor' => (int) $l['price_minor'], 'contracted_price_minor' => (int) $l['contracted_price_minor'],
                    'copay_minor' => (int) ($l['copay_minor'] ?? 0), 'insurer_share_percent' => $l['insurer_share_percent'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record('provider_tariff.drafted', 'provider_contract', $c->id, ['tariff_version_id' => $id, 'version' => $version]);

            return $this->tariff($tenantId, $id);
        });
    }

    public function approveTariff(string $tenantId, string $tariffId, string $approverId): object
    {
        return DB::transaction(function () use ($tenantId, $tariffId, $approverId) {
            $t = $this->tariff($tenantId, $tariffId);
            if ($t->status !== 'DRAFT') {
                throw new ApiProblemException('TARIFF_NOT_DRAFT', 409, "Tariff version is {$t->status}.");
            }
            if ($t->created_by !== null && $t->created_by === $approverId) {
                throw new ApiProblemException('MAKER_CHECKER', 403, 'The maker of a tariff version cannot approve it.');
            }
            // The previous approved version ends the day the new one starts.
            DB::table('provider_tariff_versions')->where('provider_contract_id', $t->provider_contract_id)->where('status', 'APPROVED')
                ->where('effective_from', '<', $t->effective_from)->whereNull('effective_to')
                ->update(['effective_to' => $t->effective_from, 'status' => 'SUPERSEDED', 'updated_at' => now()]);
            DB::table('provider_tariff_versions')->where('id', $tariffId)->update(['status' => 'APPROVED', 'approved_by' => $approverId, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record('provider_tariff.approved', 'provider_contract', $t->provider_contract_id, ['tariff_version_id' => $tariffId, 'version' => $t->version]);
            $this->outbox->record('provider_tariff.approved', 'provider_contract', $t->provider_contract_id, ['tariff_version_id' => $tariffId, 'version' => $t->version]);

            return $this->tariff($tenantId, $tariffId);
        });
    }

    public function tariff(string $tenantId, string $id): object
    {
        $t = DB::table('provider_tariff_versions as v')->join('provider_contracts as c', 'c.id', '=', 'v.provider_contract_id')
            ->where('c.tenant_id', $tenantId)->where('v.id', $id)->select('v.*')->first()
            ?? throw new ApiProblemException('TARIFF_NOT_FOUND', 404, 'Tariff version not found.');
        $t->lines = DB::table('provider_tariff_lines as l')->join('medical_services as s', 's.id', '=', 'l.medical_service_id')
            ->where('l.provider_tariff_version_id', $id)->orderBy('s.code')->select('l.*', 's.code as service_code')->get()->all();

        return $t;
    }

    /** Contracted tariff line for a service under a contract on a date (approved versions only). */
    public function priceFor(string $tenantId, string $contractId, string $medicalServiceId, ?string $asOf = null): ?object
    {
        $this->contract($tenantId, $contractId);
        $d = CarbonImmutable::parse($asOf ?? now())->toDateString();

        return DB::table('provider_tariff_versions as v')->join('provider_tariff_lines as l', 'l.provider_tariff_version_id', '=', 'v.id')
            ->where('v.provider_contract_id', $contractId)->whereIn('v.status', ['APPROVED', 'SUPERSEDED'])
            ->where('v.effective_from', '<=', $d)->where(fn ($q) => $q->whereNull('v.effective_to')->orWhere('v.effective_to', '>', $d))
            ->where('l.medical_service_id', $medicalServiceId)->orderByDesc('v.version')
            ->select('l.*', 'v.version', 'v.currency', 'v.effective_from')->first();
    }
}
