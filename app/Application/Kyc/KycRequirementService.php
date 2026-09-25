<?php

declare(strict_types=1);

namespace App\Application\Kyc;

use App\Application\DocumentCatalogue\DocumentCatalogueService;
use App\Application\Kyc\Models\KycLevelRequirement;
use App\Models\KycSubmission;
use Illuminate\Support\Carbon;

/**
 * REQ-KYC-001/002 required documents per KYC level, expressed as document catalogue canonical codes.
 * Tenant rows (kyc_level_requirements.tenant_id) replace platform rows with the same requirement_code.
 */
final class KycRequirementService
{
    public const LEVELS = ['SIMPLIFIED', 'STANDARD', 'ENHANCED'];

    /** Workflow Data Master v1 kyc_aml.kyc_levels alias (stored code stays SIMPLIFIED). */
    public const LEVEL_ALIASES = ['BASIC' => 'SIMPLIFIED'];

    public static function normalizeLevel(string $level): string
    {
        $level = strtoupper($level);

        return self::LEVEL_ALIASES[$level] ?? $level;
    }

    public const KINDS = ['INDIVIDUAL', 'CORPORATE'];

    public function __construct(private readonly DocumentCatalogueService $catalogue) {}

    /** @return list<array<string, mixed>> */
    public function requirements(?string $tenantId, string $kind, string $level): array
    {
        $rows = KycLevelRequirement::where('subject_kind', $kind)->where('kyc_level', $level)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($w) => $w->orWhere('tenant_id', $tenantId)))
            ->orderByRaw('tenant_id NULLS FIRST')->orderBy('requirement_code')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->requirement_code] = [
                'requirement_code' => $r->requirement_code, 'applies_to' => $r->applies_to, 'mandatory' => $r->mandatory,
                'accepted_canonical_codes' => $r->accepted_canonical_codes, 'refresh_months' => $r->refresh_months,
                'source' => $r->tenant_id ? 'TENANT' : $r->source,
                'document_types' => array_map(fn (string $c) => $this->describe($c), $r->accepted_canonical_codes),
            ];
        }

        return array_values($out);
    }

    /**
     * Requirement status for a submission: each requirement with satisfied_by document ids; missing = mandatory unsatisfied.
     *
     * @return array{requirements: list<array<string, mixed>>, missing: list<string>}
     */
    public function evaluate(KycSubmission $s): array
    {
        $docs = $s->documents()->get();
        $today = Carbon::now();
        $reqs = $this->requirements($s->tenant_id, $s->subject_kind ?? 'INDIVIDUAL', $s->kyc_level ?? config('kyc.default_level', 'STANDARD'));
        $missing = [];
        foreach ($reqs as &$req) {
            $ids = [];
            foreach ($docs as $d) {
                if ($d->scan_status !== 'CLEAN' || ($d->valid_until && Carbon::parse($d->valid_until)->lt($today))) {
                    continue;
                }
                if (array_intersect($this->codesFor($d->pivot->purpose, $d->document_type_code, $req['applies_to']), $req['accepted_canonical_codes'])) {
                    $ids[] = $d->id;
                }
            }
            $req['satisfied_by'] = $ids;
            $req['satisfied'] = $ids !== [];
            if ($req['mandatory'] && ! $req['satisfied']) {
                $missing[] = $req['requirement_code'];
            }
        }

        return ['requirements' => $reqs, 'missing' => $missing];
    }

    /** Smallest refresh period among the level's requirements, else config; null when none is set. */
    public function refreshMonths(KycSubmission $s): ?int
    {
        $months = array_filter(array_column($this->requirements($s->tenant_id, $s->subject_kind, $s->kyc_level), 'refresh_months'));
        if ($months) {
            return (int) min($months);
        }
        $cfg = config('kyc.refresh_months.'.$s->kyc_level);

        return $cfg === null ? null : (int) $cfg;
    }

    /** @return list<string> canonical codes a pivot purpose / document type code evidences for the given party role */
    private function codesFor(?string $purpose, ?string $typeCode, string $appliesTo): array
    {
        $purpose = strtoupper((string) $purpose);
        $isRep = str_starts_with($purpose, 'REPRESENTATIVE_');
        if (($appliesTo === 'REPRESENTATIVE') !== $isRep) {
            return [];
        }
        $p = $isRep ? substr($purpose, strlen('REPRESENTATIVE_')) : $purpose;
        $codes = [];
        foreach (config('kyc.purpose_aliases', []) as $code => $aliases) {
            if (in_array($p, $aliases, true)) {
                $codes[] = $code;
            }
        }
        if (! $isRep && $typeCode) {
            $codes[] = strtoupper($typeCode);
        }

        return $codes;
    }

    /** @return array{canonical_code: string, type_id: ?string, name_en: ?string, name_fr: ?string, catalogue: string} */
    private function describe(string $code): array
    {
        $t = $this->catalogue->find($code);

        return ['canonical_code' => $code, 'type_id' => $t?->type_id, 'name_en' => $t?->name_en, 'name_fr' => $t?->name_fr, 'catalogue' => $t ? 'FOUND' : 'NOT_SEEDED'];
    }
}
