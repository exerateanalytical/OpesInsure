<?php

declare(strict_types=1);

namespace App\Application\OperationsTaxonomy\Targets;

use App\Application\Cases\CaseTypeCatalogue;
use App\Application\Import\ImportTarget;
use App\Application\OperationsTaxonomy\OperationsCatalogue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gap Closure Pack file 10 sla_profiles (CONFIG_REQUIRED): tenant SLA profiles imported into the canonical
 * sla_policy_overrides (no parallel SLA table). Always PLATFORM_SLA — an import can never create a REGULATORY_DEADLINE
 * (those need a legal basis and are PENDING_VERIFICATION). One profile row => FIRST_RESPONSE (ack) and RESOLUTION overrides.
 * organization_id is the insurer (carriers.id) the profile is scoped to, or empty for the whole tenant.
 */
final class SlaProfileTarget implements ImportTarget
{
    public function key(): string
    {
        return 'sla_profiles';
    }

    public function label(): string
    {
        return 'SLA profiles (PLATFORM_SLA targets)';
    }

    public function fields(): array
    {
        $f = array_fill_keys(OperationsCatalogue::fields('sla_profiles'), false);

        return array_merge($f, ['sla_id' => true, 'case_type' => true, 'effective_from' => true]);
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $code = trim((string) ($row['sla_id'] ?? ''));
        if ($code === '' || trim((string) ($row['case_type'] ?? '')) === '' || strtotime((string) ($row['effective_from'] ?? '')) === false) {
            return ['status' => 'ERROR', 'error' => 'sla_id, case_type and effective_from are required'];
        }
        if (! DB::table('case_types')->where('code', $row['case_type'])->exists()) {
            return ['status' => 'ERROR', 'key' => $code, 'error' => "Unknown case type {$row['case_type']}"];
        }
        $priority = ($row['priority'] ?? null) ?: null;
        if ($priority !== null && ! in_array($priority, CaseTypeCatalogue::PRIORITIES, true)) {
            return ['status' => 'ERROR', 'key' => $code, 'error' => "Unknown priority {$priority}"];
        }
        $ack = $this->minutes($row['ack_target_minutes'] ?? null);
        $res = $this->minutes($row['resolution_target_minutes'] ?? null);
        if ($ack === false || $res === false || ($ack === null && $res === null)) {
            return ['status' => 'ERROR', 'key' => $code, 'error' => 'ack_target_minutes / resolution_target_minutes must be positive integers (at least one)'];
        }
        $org = ($row['organization_id'] ?? null) ?: null;
        if ($org !== null && (! Str::isUuid($org) || ! DB::table('carriers')->where('id', $org)->exists())) {
            return ['status' => 'ERROR', 'key' => $code, 'error' => 'organization_id must be an insurer (carriers.id)'];
        }
        if ($hit = DB::table('sla_policy_overrides')->where('sla_profile_code', $code)->where('status', 'ACTIVE')->value('id')) {
            return ['status' => 'DUPLICATE', 'key' => $code, 'matches' => $hit];
        }
        if (isset($seen[$code])) {
            return ['status' => 'ERROR', 'key' => $code, 'error' => "sla_id {$code} repeated in the file"];
        }
        $seen[$code] = true;

        return ['status' => 'NEW', 'key' => $code];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $tenant = DB::table('import_batches')->where('id', $batchId)->value('tenant_id');
        $first = null;
        foreach (['FIRST_RESPONSE' => $row['ack_target_minutes'] ?? null, 'RESOLUTION' => $row['resolution_target_minutes'] ?? null] as $metric => $minutes) {
            if (($m = $this->minutes($minutes)) === null) {
                continue;
            }
            $id = (string) Str::uuid();
            DB::table('sla_policy_overrides')->insert([
                'id' => $id, 'case_type_code' => $row['case_type'], 'metric' => $metric, 'tenant_id' => $tenant, 'carrier_id' => ($row['organization_id'] ?? null) ?: null,
                'priority' => ($row['priority'] ?? null) ?: null, 'target_business_minutes' => $m, 'deadline_label' => 'PLATFORM_SLA',
                'calendar_id' => Str::isUuid((string) ($row['calendar_id'] ?? '')) ? $row['calendar_id'] : null,
                'pause_states' => json_encode($this->list($row['pause_states'] ?? null)), 'escalation_thresholds' => json_encode($this->list($row['escalation_thresholds'] ?? null)),
                'effective_from' => date('Y-m-d', strtotime((string) $row['effective_from'])),
                'effective_until' => ($row['effective_until'] ?? null) && strtotime((string) $row['effective_until']) ? date('Y-m-d', strtotime((string) $row['effective_until'])) : null,
                'status' => 'ACTIVE', 'sla_profile_code' => trim((string) $row['sla_id']), 'source' => 'IMPORT:'.$batchId, 'created_by' => $actor?->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $first ??= $id;
        }

        return (string) $first;
    }

    public function finish(array $params): void {}

    private function minutes(mixed $v): int|false|null
    {
        if ($v === null || $v === '') {
            return null;
        }

        return ctype_digit((string) $v) && (int) $v > 0 ? (int) $v : false;
    }

    /** @return list<string> "A|B" or JSON array */
    private function list(mixed $v): array
    {
        if (is_array($v)) {
            return array_values($v);
        }
        $s = trim((string) $v);
        if ($s === '') {
            return [];
        }
        $j = json_decode($s, true);

        return is_array($j) ? array_values($j) : array_values(array_filter(array_map('trim', explode('|', $s))));
    }
}
