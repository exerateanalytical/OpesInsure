<?php

declare(strict_types=1);

namespace App\Application\Claims\Types;

use App\Application\Audit\AuditWriter;
use App\Application\Claims\ClaimReferenceCodes;
use App\Application\Events\OutboxWriter;
use App\Models\Claim;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-007 — claim types per product / line (claim_type_versions). Codes are the workflow claim types of
 * ClaimReferenceCodes::CLAIM_TYPES; the line is the master-data claims.claim_category.
 *
 * Resolution (most specific ACTIVE version in force at the date wins): tenant+product override > tenant line override >
 * platform default. Reporting deadlines are platform defaults an insurer may override — never presented as legal deadlines.
 * Insurer overrides are drafted by a maker and activated by a different checker.
 */
final class ClaimTypeCatalogue
{
    /** Commercial line codes → claims.claim_category (same aliases as the evidence rules). */
    public const LINE_ALIASES = ['AUTO' => 'MOTOR', 'AUTOMOBILE' => 'MOTOR', 'FLEET' => 'MOTOR', 'MOTOR_FLEET' => 'MOTOR', 'HOME' => 'PROPERTY', 'MRH' => 'PROPERTY',
        'HOME_MULTIRISK' => 'PROPERTY', 'BUSINESS_MULTIRISK' => 'PROPERTY', 'SANTE' => 'HEALTH', 'VIE' => 'LIFE', 'VOYAGE' => 'TRAVEL'];

    public const DEADLINE_NOTICE = 'Platform default reporting period, configurable per insurer; not a legal deadline.';

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public static function normaliseLine(?string $line): ?string
    {
        $line = strtoupper(trim((string) $line));

        return $line === '' ? null : (self::LINE_ALIASES[$line] ?? $line);
    }

    /** @return array{line_code: ?string, product_id: ?string} */
    public function lineFor(Claim $claim): array
    {
        $policy = $claim->policy;
        $product = $policy?->proposal?->offer?->product;
        $line = $policy?->terms_snapshot['line_code'] ?? $product?->line_code ?? $policy?->proposal?->offer?->quote?->line_code ?? null;

        return ['line_code' => self::normaliseLine($line), 'product_id' => $product?->id];
    }

    /** Effective ACTIVE versions for a tenant / line / product at a date, one per code (most specific scope wins). */
    public function effective(?string $tenantId, ?string $line, ?string $productId = null, ?CarbonInterface $at = null): array
    {
        $line = self::normaliseLine($line);
        if ($line === null) {
            return [];
        }
        $day = ($at ?? now())->toDateString();
        $scopes = array_values(array_filter([
            $tenantId && $productId ? "TENANT:{$tenantId}:PRODUCT:{$productId}" : null,
            $tenantId ? "TENANT:{$tenantId}" : null,
            'PLATFORM',
        ]));
        $rows = DB::table('claim_type_versions')->where('line_code', $line)->where('status', 'ACTIVE')->whereIn('scope_key', $scopes)
            ->whereDate('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day))
            ->orderByDesc('version')->get();
        $out = [];
        foreach ($scopes as $scope) {
            foreach ($rows->where('scope_key', $scope) as $r) {
                $out[$r->code] ??= $this->present($r);
            }
        }
        ksort($out);

        return array_values($out);
    }

    /** The claim type for a claim: the requested code, else the line's default. Null when the line has no configured type. */
    public function resolve(Claim $claim, ?string $code = null): ?array
    {
        ['line_code' => $line, 'product_id' => $product] = $this->lineFor($claim);
        $types = $this->effective($claim->tenant_id, $line, $product, $claim->submitted_at ?? now()); // config in force when the claim is reported
        $code = $code ? strtoupper($code) : null;
        foreach ($types as $t) {
            if ($code !== null && $t['code'] === $code) {
                return $t;
            }
        }
        if ($code !== null && $types !== []) {
            throw ValidationException::withMessages(['claim_type' => "Claim type {$code} is not configured for line {$line}."]);
        }
        foreach ($types as $t) {
            if ($t['is_default']) {
                return $t;
            }
        }

        return $types[0] ?? null;
    }

    /** Maker: an insurer override (new DRAFT version of the tenant / product scope). */
    public function draft(string $tenantId, array $d, User $maker): array
    {
        $code = strtoupper((string) $d['code']);
        if (! isset(ClaimReferenceCodes::CLAIM_TYPES[$code])) {
            throw ValidationException::withMessages(['code' => 'Unknown claim type code.']);
        }
        $line = self::normaliseLine($d['line_code']) ?? throw ValidationException::withMessages(['line_code' => 'A line is required.']);
        $productId = $d['product_id'] ?? null;
        $scope = $productId ? "TENANT:{$tenantId}:PRODUCT:{$productId}" : "TENANT:{$tenantId}";

        return DB::transaction(function () use ($tenantId, $d, $maker, $code, $line, $productId, $scope) {
            $prev = DB::table('claim_type_versions')->where(['scope_key' => $scope, 'line_code' => $line, 'code' => $code])->lockForUpdate()->orderByDesc('version')->first();
            $base = $prev ?? DB::table('claim_type_versions')->where(['scope_key' => 'PLATFORM', 'line_code' => $line, 'code' => $code])->orderByDesc('version')->first();
            $pick = fn (string $k, mixed $fallback) => array_key_exists($k, $d) ? $d[$k] : $fallback;
            $id = (string) Str::uuid();
            DB::table('claim_type_versions')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'product_id' => $productId, 'scope_key' => $scope, 'line_code' => $line, 'code' => $code,
                'version' => ($prev->version ?? 0) + 1,
                'labels' => json_encode($pick('labels', $base ? json_decode((string) $base->labels, true) : ['en' => $code, 'fr' => $code])),
                'applicable_coverages' => json_encode(array_values($pick('applicable_coverages', $base ? json_decode((string) $base->applicable_coverages, true) : []))),
                'reporting_deadline_days' => $pick('reporting_deadline_days', $base->reporting_deadline_days ?? null),
                'evidence_pack_code' => $pick('evidence_pack_code', $base->evidence_pack_code ?? null),
                'required_evidence_codes' => json_encode(array_values($pick('required_evidence_codes', $base ? json_decode((string) $base->required_evidence_codes, true) : []))),
                'default_reserve_minor' => $pick('default_reserve_minor', $base->default_reserve_minor ?? null),
                'currency' => $pick('currency', $base->currency ?? null),
                'is_default' => (bool) $pick('is_default', $base->is_default ?? false),
                'status' => 'DRAFT', 'effective_from' => $d['effective_from'] ?? now()->toDateString(), 'effective_until' => $d['effective_until'] ?? null,
                'source' => 'INSURER_OVERRIDE', 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('claim_type.version.drafted', 'claim_type_version', $id, ['code' => $code, 'line_code' => $line, 'scope' => $scope]);

            return $this->present(DB::table('claim_type_versions')->find($id));
        });
    }

    /** Checker: activates a DRAFT override (maker ≠ checker) and retires the previous ACTIVE version of the same scope. */
    public function approve(string $tenantId, string $id, User $checker): array
    {
        return DB::transaction(function () use ($tenantId, $id, $checker) {
            $v = DB::table('claim_type_versions')->where(['id' => $id, 'tenant_id' => $tenantId])->lockForUpdate()->first()
                ?? abort(404);
            if ($v->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Only a DRAFT claim type version can be approved.']);
            }
            if ($v->created_by === $checker->id) {
                throw ValidationException::withMessages(['approved_by' => 'Maker-checker: the maker of a claim type version cannot approve it.']);
            }
            DB::table('claim_type_versions')->where(['scope_key' => $v->scope_key, 'line_code' => $v->line_code, 'code' => $v->code, 'status' => 'ACTIVE'])
                ->update(['status' => 'RETIRED', 'effective_until' => now()->subDay()->toDateString(), 'updated_at' => now()]);
            DB::table('claim_type_versions')->where('id', $id)->update(['status' => 'ACTIVE', 'approved_by' => $checker->id, 'approved_at' => now(), 'updated_at' => now()]);
            $payload = ['claim_type_version_id' => $id, 'code' => $v->code, 'line_code' => $v->line_code, 'version' => (int) $v->version, 'scope' => $v->scope_key];
            $this->audit->record('claim_type.version.approved', 'claim_type_version', $id, $payload);
            $this->outbox->record('claim_type.version.approved', 'claim_type_version', $id, $payload);

            return $this->present(DB::table('claim_type_versions')->find($id));
        });
    }

    public function present(object $r): array
    {
        return [
            'id' => $r->id, 'code' => $r->code, 'line_code' => $r->line_code, 'category' => ClaimReferenceCodes::categoryFor($r->code),
            'version' => (int) $r->version, 'scope' => $r->scope_key, 'source' => $r->source, 'product_id' => $r->product_id,
            'labels' => json_decode((string) $r->labels, true), 'applicable_coverages' => json_decode((string) $r->applicable_coverages, true),
            'reporting_deadline_days' => $r->reporting_deadline_days === null ? null : (int) $r->reporting_deadline_days,
            'reporting_deadline_notice' => self::DEADLINE_NOTICE,
            'evidence_pack_code' => $r->evidence_pack_code, 'required_evidence_codes' => json_decode((string) $r->required_evidence_codes, true),
            'default_reserve_minor' => $r->default_reserve_minor === null ? null : (int) $r->default_reserve_minor, 'currency' => $r->currency,
            'is_default' => (bool) $r->is_default, 'status' => $r->status, 'effective_from' => $r->effective_from, 'effective_until' => $r->effective_until,
        ];
    }
}
