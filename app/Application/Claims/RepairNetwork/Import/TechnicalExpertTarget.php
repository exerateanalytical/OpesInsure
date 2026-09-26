<?php

declare(strict_types=1);

namespace App\Application\Claims\RepairNetwork\Import;

use App\Application\Claims\RepairNetwork\RepairNetworkService;
use Illuminate\Support\Facades\DB;

/**
 * Agent GP3 — official import of the DGTCFM technical experts list (gap pack 03 technical_experts, PENDING_OFFICIAL_IMPORT).
 * Record schema: name, decision_reference, registration_number, specialties, phones, email, city, address, source_url, verified_at.
 * Rows become canonical EXPERT providers with data_status PENDING_VERIFICATION until a second user verifies them against the list.
 */
final class TechnicalExpertTarget extends RepairGarageTarget
{
    public function key(): string
    {
        return 'technical_experts';
    }

    public function label(): string
    {
        return 'Technical experts / loss adjusters (DGTCFM list)';
    }

    public function fields(): array
    {
        return ['name' => true, 'decision_reference' => true, 'registration_number' => false, 'specialties' => true, 'phones' => false,
            'email' => false, 'city' => false, 'address' => false, 'source_url' => false];
    }

    protected function category(): string
    {
        return 'EXPERT';
    }

    protected function nameField(): string
    {
        return 'name';
    }

    protected function missingRequired(array $row): bool
    {
        return trim((string) ($row['decision_reference'] ?? '')) === '' || self::split($row['specialties'] ?? '') === [];
    }

    protected function requiredMessage(): string
    {
        return 'name, decision_reference and at least one specialty are required';
    }

    protected function invalidCodes(array $row): ?string
    {
        foreach (self::split($row['specialties'] ?? '') as $s) {
            if (RepairNetworkService::specialtyCode($s) === null) {
                return "unknown expert specialty $s";
            }
        }

        return null;
    }

    protected function entry(array $row, string $batchId): array
    {
        $specialties = array_map(fn ($s) => RepairNetworkService::specialtyCode($s), self::split($row['specialties']));

        return [
            'category' => 'EXPERT', 'name' => trim((string) $row['name']), 'provider_type_code' => $specialties[0],
            'registration_number' => ($row['registration_number'] ?? '') ?: null, 'decision_reference' => trim((string) $row['decision_reference']),
            'city' => isset($row['city']) ? strtoupper((string) $row['city']) : null, 'address' => $row['address'] ?? null,
            'phones' => self::split($row['phones'] ?? ''), 'emails' => self::split($row['email'] ?? ''),
            'source' => 'DGTCFM_EXPERTS import '.$batchId, 'source_url' => ($row['source_url'] ?? '') ?: RepairNetworkService::DGTCFM_EXPERTS_URL,
            'data_status' => 'PENDING_VERIFICATION', 'specialties' => $specialties,
        ];
    }
}
