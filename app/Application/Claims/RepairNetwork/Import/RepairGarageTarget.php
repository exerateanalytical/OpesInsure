<?php

declare(strict_types=1);

namespace App\Application\Claims\RepairNetwork\Import;

use App\Application\Claims\RepairNetwork\RepairNetworkService;
use App\Application\Import\ImportTarget;
use App\Application\MasterData\MasterDataNormalizer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Agent GP3 — approved garage network import (gap pack 03 approved_garage_master, PENDING_INSURER_NETWORK_SOURCE) through the
 * generic ImportPipeline (upload, mapping, maker-checker approval, audit). Rows become canonical GARAGE providers with
 * data_status PENDING_VERIFICATION; they cannot be approved / used until a second user verifies the source.
 * Duplicates (same registration number, or same normalised legal name among garages) are never overwritten.
 */
class RepairGarageTarget implements ImportTarget
{
    public function key(): string
    {
        return 'repair_garages';
    }

    public function label(): string
    {
        return 'Approved garages / repairers (insurer network)';
    }

    public function fields(): array
    {
        return ['legal_name' => true, 'trade_name' => false, 'registration_number' => false, 'region' => false, 'city' => false, 'address' => false,
            'phone' => false, 'email' => false, 'services' => false, 'vehicle_makes' => false, 'bodywork' => false, 'mechanical' => false, 'glass' => false,
            'towing' => false, 'inspection' => false, 'source' => true, 'source_url' => false, 'effective_from' => false];
    }

    public function params(array $params): array
    {
        return [];
    }

    protected function category(): string
    {
        return 'GARAGE';
    }

    protected function nameField(): string
    {
        return 'legal_name';
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $name = trim((string) ($row[$this->nameField()] ?? ''));
        if ($name === '' || $this->missingRequired($row)) {
            return ['status' => 'ERROR', 'error' => $this->requiredMessage()];
        }
        $key = MasterDataNormalizer::normalize($name);
        if (isset($seen[$key])) {
            return ['status' => 'ERROR', 'error' => "$name repeated in the file"];
        }
        $seen[$key] = true;
        if ($err = $this->invalidCodes($row)) {
            return ['status' => 'ERROR', 'error' => $err];
        }
        $reg = trim((string) ($row['registration_number'] ?? ''));
        $dup = DB::table('provider_profiles as p')->join('parties', 'parties.id', '=', 'p.party_id')->where('p.category', $this->category())
            ->where(fn ($q) => $q->whereRaw('lower(parties.display_name) = ?', [mb_strtolower($name)])
                ->when($reg !== '', fn ($q) => $q->orWhere('p.registration_number', $reg)))
            ->value('p.id');

        return $dup ? ['status' => 'DUPLICATE', 'key' => $name, 'matches' => $dup] : ['status' => 'NEW', 'key' => $name];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        return app(RepairNetworkService::class)->registerEntry($this->entry($row, $batchId), $actor?->id)->id;
    }

    public function finish(array $params): void {}

    protected function missingRequired(array $row): bool
    {
        return trim((string) ($row['source'] ?? '')) === '';
    }

    protected function requiredMessage(): string
    {
        return 'legal_name and source (insurer network agreement / list reference) are required';
    }

    /** @return list<string> */
    protected static function split(mixed $v): array
    {
        return array_values(array_filter(array_map(fn ($s) => trim($s), preg_split('/[|;,]/', (string) $v) ?: [])));
    }

    protected static function flag(mixed $v): bool
    {
        return in_array(strtoupper(trim((string) $v)), ['1', 'Y', 'YES', 'TRUE', 'OUI', 'X'], true);
    }

    /** @return list<string> */
    protected function services(array $row): array
    {
        $codes = array_map('strtoupper', self::split($row['services'] ?? ''));
        foreach (RepairNetworkService::GARAGE_FLAGS as $col => $code) {
            if (self::flag($row[$col] ?? null)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    protected function invalidCodes(array $row): ?string
    {
        foreach ($this->services($row) as $c) {
            if (! DB::table('master_data_values')->where(['domain_code' => 'partners', 'list_code' => 'garage_service', 'code' => $c])->exists()) {
                return "unknown garage service $c";
            }
        }
        foreach (self::split($row['vehicle_makes'] ?? '') as $m) {
            if (! DB::table('vehicle_makes')->where('code', strtoupper($m))->exists()) {
                return "unknown vehicle make $m";
            }
        }

        return null;
    }

    protected function entry(array $row, string $batchId): array
    {
        return [
            'category' => 'GARAGE', 'name' => trim((string) $row['legal_name']), 'provider_type_code' => 'GARAGE_REPAIRER',
            'trade_name' => $row['trade_name'] ?? null, 'registration_number' => ($row['registration_number'] ?? '') ?: null,
            'region' => isset($row['region']) ? strtoupper((string) $row['region']) : null, 'city' => isset($row['city']) ? strtoupper((string) $row['city']) : null,
            'address' => $row['address'] ?? null, 'phones' => self::split($row['phone'] ?? ''), 'emails' => self::split($row['email'] ?? ''),
            'source' => mb_substr('IMPORT '.$batchId.': '.$row['source'], 0, 120), 'source_url' => ($row['source_url'] ?? '') ?: null,
            'effective_from' => ($row['effective_from'] ?? '') ?: null, 'data_status' => 'PENDING_VERIFICATION',
            'services' => $this->services($row), 'vehicle_makes' => array_map('strtoupper', self::split($row['vehicle_makes'] ?? '')),
        ];
    }
}
