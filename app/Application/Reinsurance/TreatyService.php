<?php

declare(strict_types=1);

namespace App\Application\Reinsurance;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\FinancialDistribution\ReinsuranceReference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-REI-001: reinsurer profiles, treaties, effective-dated treaty versions and participants.
 * Versions are DRAFT until activated by a second person (maker-checker); an activated version is
 * frozen, and activating a newer one closes the older one's effective_to the day before.
 */
final class TreatyService
{
    public const REINSURER_ROLES = ['REINSURER', 'REINSURANCE_BROKER', 'RETROCESSIONAIRE'];

    /** FACULTATIVE placements are REQ-REI-003 (slip-based), not treaties. */
    public const TREATY_REINSURANCE_TYPES = ['TREATY', 'FACULTATIVE_OBLIGATORY'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function createReinsurer(string $tenantId, array $data): array
    {
        $this->require(in_array($data['role'] ?? 'REINSURER', self::REINSURER_ROLES, true), 'role', 'Unknown reinsurer role.');
        $this->require(! DB::table('reinsurers')->where('tenant_id', $tenantId)->where('code', $data['code'])->exists(), 'code', 'Reinsurer code already used.');
        $row = ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'party_id' => $data['party_id'] ?? null, 'code' => $data['code'], 'name' => $data['name'],
            'role' => $data['role'] ?? 'REINSURER', 'country_code' => $data['country_code'] ?? null, 'rating' => $data['rating'] ?? null,
            'rating_agency' => $data['rating_agency'] ?? null, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()];
        DB::table('reinsurers')->insert($row);
        $this->audit->record('reinsurance.reinsurer.created', 'reinsurer', $row['id'], ['code' => $row['code'], 'role' => $row['role']]);

        return $row;
    }

    public function updateReinsurerStatus(string $tenantId, string $id, string $status, string $reason): array
    {
        $this->require(in_array($status, ['ACTIVE', 'SUSPENDED', 'INACTIVE'], true), 'status', 'Unknown status.');
        $old = $this->reinsurer($tenantId, $id);
        DB::table('reinsurers')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
        $this->audit->recordChange('reinsurance.reinsurer.status_changed', 'reinsurer', $id, ['status' => $old->status], ['status' => $status], $reason);

        return (array) $this->reinsurer($tenantId, $id);
    }

    public function createTreaty(string $tenantId, array $data): array
    {
        $this->require(in_array($data['treaty_type'], ReinsuranceReference::TREATY_TYPES, true), 'treaty_type', 'Unknown treaty type.');
        $this->require(in_array($data['reinsurance_type'] ?? 'TREATY', self::TREATY_REINSURANCE_TYPES, true), 'reinsurance_type', 'Treaties are TREATY or FACULTATIVE_OBLIGATORY; facultative placements are separate.');
        $this->require(! DB::table('reinsurance_treaties')->where('tenant_id', $tenantId)->where('code', $data['code'])->exists(), 'code', 'Treaty code already used.');
        $row = ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $data['code'], 'name' => $data['name'], 'reinsurance_type' => $data['reinsurance_type'] ?? 'TREATY',
            'treaty_type' => $data['treaty_type'], 'currency' => strtoupper($data['currency']), 'underwriting_year' => $data['underwriting_year'] ?? null,
            'status' => 'DRAFT', 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('reinsurance_treaties')->insert($row);
        $this->audit->record('reinsurance.treaty.created', 'reinsurance_treaty', $row['id'], ['code' => $row['code'], 'treaty_type' => $row['treaty_type']]);

        return $row;
    }

    public function addVersion(string $tenantId, string $treatyId, array $data): array
    {
        $treaty = $this->treaty($tenantId, $treatyId);
        $this->require($treaty->status !== 'CANCELLED', 'treaty', 'Treaty is cancelled.');
        $this->validateTerms($treaty->treaty_type, $data);
        $participants = $data['participants'] ?? [];
        $this->require($participants !== [], 'participants', 'At least one participant is required.');
        foreach ($participants as $p) {
            $r = DB::table('reinsurers')->where('tenant_id', $tenantId)->where('id', $p['reinsurer_id'] ?? null)->first();
            $this->require($r !== null && $r->role !== 'REINSURANCE_BROKER', 'participants', 'Participants must be reinsurers of this tenant.');
            if (! empty($p['broker_id'])) {
                $this->require(DB::table('reinsurers')->where('tenant_id', $tenantId)->where('id', $p['broker_id'])->where('role', 'REINSURANCE_BROKER')->exists(), 'participants', 'Broker must be a REINSURANCE_BROKER of this tenant.');
            }
        }
        $this->require(count(array_unique(array_column($participants, 'reinsurer_id'))) === count($participants), 'participants', 'A reinsurer may participate once per version.');

        return DB::transaction(function () use ($tenantId, $treaty, $data, $participants) {
            DB::table('reinsurance_treaties')->where('id', $treaty->id)->lockForUpdate()->first();
            $version = (int) DB::table('reinsurance_treaty_versions')->where('treaty_id', $treaty->id)->max('version') + 1;
            $row = ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'treaty_id' => $treaty->id, 'version' => $version,
                'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null, 'status' => 'DRAFT',
                'scope' => json_encode(['line_codes' => array_values($data['line_codes'] ?? [])]),
                'retention_minor' => $data['retention_minor'] ?? null, 'cession_percent' => $data['cession_percent'] ?? null, 'lines' => $data['lines'] ?? null,
                'max_capacity_minor' => $data['max_capacity_minor'] ?? null, 'commission_percent' => $data['commission_percent'] ?? 0,
                'brokerage_percent' => $data['brokerage_percent'] ?? 0, 'tax_percent' => $data['tax_percent'] ?? 0,
                'layers' => json_encode(array_values($data['layers'] ?? [])), 'rate_percent' => $data['rate_percent'] ?? null,
                'attachment_ratio' => $data['attachment_ratio'] ?? null, 'limit_ratio' => $data['limit_ratio'] ?? null, 'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()];
            DB::table('reinsurance_treaty_versions')->insert($row);
            foreach ($participants as $p) {
                DB::table('reinsurance_treaty_participants')->insert(['id' => (string) Str::uuid(), 'treaty_version_id' => $row['id'], 'reinsurer_id' => $p['reinsurer_id'],
                    'broker_id' => $p['broker_id'] ?? null, 'share_percent' => $p['share_percent'], 'is_lead' => (bool) ($p['is_lead'] ?? false), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('reinsurance.treaty_version.created', 'reinsurance_treaty_version', $row['id'], ['treaty_id' => $treaty->id, 'version' => $version]);

            return $this->version($tenantId, $row['id']);
        });
    }

    /** Maker-checker activation. Signed shares must total exactly 100%. */
    public function activateVersion(string $tenantId, string $versionId, string $reason): array
    {
        $v = $this->version($tenantId, $versionId);
        $this->require($v['status'] === 'DRAFT', 'status', 'Only a DRAFT version can be activated.');
        $this->require($v['created_by'] === null || $v['created_by'] !== auth()->id(), 'approver', 'The creator of a treaty version cannot activate it.');
        $total = round(array_sum(array_map(fn ($p) => (float) $p['share_percent'], $v['participants'])), 4);
        $this->require(abs($total - 100.0) < 0.00005, 'participants', "Participant shares total {$total}%, must be 100%.");
        $this->require(! DB::table('reinsurers')->whereIn('id', array_column($v['participants'], 'reinsurer_id'))->where('status', '!=', 'ACTIVE')->exists(), 'participants', 'All participants must be ACTIVE.');

        DB::transaction(function () use ($v, $tenantId) {
            $dayBefore = Carbon::parse($v['effective_from'])->subDay()->toDateString();
            $older = DB::table('reinsurance_treaty_versions')->where('treaty_id', $v['treaty_id'])->where('status', 'ACTIVE')->lockForUpdate()->get();
            foreach ($older as $o) {
                if ($o->effective_from >= $v['effective_from']) {
                    DB::table('reinsurance_treaty_versions')->where('id', $o->id)->update(['status' => 'SUPERSEDED', 'updated_at' => now()]);
                } elseif ($o->effective_to === null || $o->effective_to > $dayBefore) {
                    DB::table('reinsurance_treaty_versions')->where('id', $o->id)->update(['effective_to' => $dayBefore, 'updated_at' => now()]);
                }
            }
            DB::table('reinsurance_treaty_versions')->where('id', $v['id'])->update(['status' => 'ACTIVE', 'approved_by' => auth()->id(), 'activated_at' => now(), 'updated_at' => now()]);
            DB::table('reinsurance_treaties')->where('id', $v['treaty_id'])->where('status', 'DRAFT')->update(['status' => 'ACTIVE', 'updated_at' => now()]);
            $this->outbox->record('reinsurance.treaty_version.activated', 'reinsurance_treaty', $v['treaty_id'], ['tenant_id' => $tenantId, 'treaty_version_id' => $v['id'], 'version' => $v['version'], 'effective_from' => $v['effective_from']]);
        });
        $this->audit->recordChange('reinsurance.treaty_version.activated', 'reinsurance_treaty_version', $v['id'], ['status' => 'DRAFT'], ['status' => 'ACTIVE'], $reason);

        return $this->version($tenantId, $versionId);
    }

    /**
     * Active versions effective on $date, for the given line (versions without a line scope apply to all lines).
     *
     * @return array<int, array<string, mixed>>
     */
    public function effectiveVersions(string $tenantId, string $date, ?string $lineCode = null, ?string $currency = null): array
    {
        $ids = DB::table('reinsurance_treaty_versions as v')->join('reinsurance_treaties as t', 't.id', '=', 'v.treaty_id')
            ->where('v.tenant_id', $tenantId)->where('v.status', 'ACTIVE')->where('t.status', 'ACTIVE')
            ->when($currency, fn ($q) => $q->where('t.currency', $currency))
            ->whereDate('v.effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('v.effective_to')->orWhereDate('v.effective_to', '>=', $date))
            ->orderBy('t.code')->pluck('v.id');

        return collect($ids)->map(fn ($id) => $this->version($tenantId, $id))
            ->filter(fn ($v) => $v['scope']['line_codes'] === [] || ($lineCode !== null && in_array($lineCode, $v['scope']['line_codes'], true)))
            ->values()->all();
    }

    public function version(string $tenantId, string $id): array
    {
        $v = DB::table('reinsurance_treaty_versions as v')->join('reinsurance_treaties as t', 't.id', '=', 'v.treaty_id')
            ->where('v.tenant_id', $tenantId)->where('v.id', $id)->select('v.*', 't.treaty_type', 't.code as treaty_code', 't.currency')->first();
        if (! $v) {
            abort(404, 'Treaty version not found.');
        }
        $a = (array) $v;
        $a['scope'] = json_decode($a['scope'], true) + ['line_codes' => []];
        $a['layers'] = json_decode($a['layers'], true);
        $a['effective_from'] = substr((string) $a['effective_from'], 0, 10);
        $a['effective_to'] = $a['effective_to'] === null ? null : substr((string) $a['effective_to'], 0, 10);
        $a['participants'] = DB::table('reinsurance_treaty_participants')->where('treaty_version_id', $id)->orderByDesc('is_lead')->orderBy('created_at')->get()->map(fn ($p) => (array) $p)->all();

        return $a;
    }

    public function treaty(string $tenantId, string $id): object
    {
        return DB::table('reinsurance_treaties')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404, 'Treaty not found.');
    }

    public function reinsurer(string $tenantId, string $id): object
    {
        return DB::table('reinsurers')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404, 'Reinsurer not found.');
    }

    private function validateTerms(string $type, array $d): void
    {
        foreach (['commission_percent', 'brokerage_percent', 'tax_percent', 'cession_percent', 'rate_percent'] as $k) {
            $this->require(! isset($d[$k]) || ((float) $d[$k] >= 0 && (float) $d[$k] <= 100), $k, "{$k} must be between 0 and 100.");
        }
        match ($type) {
            'QUOTA_SHARE' => $this->require(isset($d['cession_percent']) && (float) $d['cession_percent'] > 0, 'cession_percent', 'Quota share needs cession_percent.'),
            'SURPLUS' => $this->require(($d['retention_minor'] ?? 0) > 0 && ($d['lines'] ?? 0) > 0, 'retention_minor', 'Surplus needs retention_minor and lines.'),
            'EXCESS_OF_LOSS' => $this->validateLayers($d['layers'] ?? []),
            'STOP_LOSS' => $this->require(isset($d['rate_percent'], $d['attachment_ratio'], $d['limit_ratio']), 'rate_percent', 'Stop loss needs rate_percent, attachment_ratio and limit_ratio.'),
            default => null,
        };
    }

    private function validateLayers(array $layers): void
    {
        $this->require($layers !== [], 'layers', 'Excess of loss needs at least one layer.');
        $prevTop = null;
        usort($layers, fn ($a, $b) => ($a['attachment_minor'] ?? 0) <=> ($b['attachment_minor'] ?? 0));
        foreach ($layers as $l) {
            $this->require(($l['attachment_minor'] ?? -1) >= 0 && ($l['limit_minor'] ?? 0) > 0, 'layers', 'Each layer needs attachment_minor >= 0 and limit_minor > 0.');
            $this->require($prevTop === null || $l['attachment_minor'] >= $prevTop, 'layers', 'Layers must not overlap.');
            $prevTop = $l['attachment_minor'] + $l['limit_minor'];
        }
    }

    private function require(bool $ok, string $field, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
