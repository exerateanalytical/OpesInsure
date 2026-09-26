<?php

declare(strict_types=1);

namespace App\Application\Rating;

use App\Application\Engines\EngineResult;
use App\Application\Shared\CanonicalJson;
use App\Application\Temporal\ReferenceInstant;
use App\Application\Temporal\TemporalResolutionException;
use App\Application\Temporal\VersionResolver;
use App\Application\Vehicles\Power\FiscalPowerReviewRequired;
use App\Application\Vehicles\Power\VehicleStampDutyService;
use App\Domain\Rating\DeterministicRatingEngine;
use App\Domain\Rating\PricingResult;
use App\Models\InsuranceProduct;
use App\Models\Quote;
use App\Models\TariffVersion;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-RAT-001 / 003 / 004 / 005 — the one rating path. Resolves tariff, tax/levy and fee versions through
 * the Temporal engine (exactly one per key on the reference date, never "latest"), prices with the pure
 * DeterministicRatingEngine, wraps the result in an EngineResult (engine RATING) and — for quotes —
 * persists an immutable rating_runs snapshot carrying every version used. Preview and reproduction go
 * through the same price() so a past date re-prices exactly as it did then.
 */
final class RatingService
{
    public function __construct(
        private readonly DeterministicRatingEngine $engine,
        private readonly VersionResolver $versions,
        private readonly CanonicalJson $json,
    ) {}

    /** The tariff that applies to $product on $at, or null when none / ambiguous (the product is skipped). */
    public function tariffFor(InsuranceProduct $product, ReferenceInstant $at, ?CarbonImmutable $asOf = null): ?TariffVersion
    {
        try {
            return TariffVersion::find($this->versions->resolve('tariff', ['insurance_product_id' => $product->id], $at, $asOf)->id);
        } catch (TemporalResolutionException) {
            return null;
        }
    }

    /**
     * Price facts against one tariff version on a reference instant. Stateless.
     *
     * @return array{pricing: PricingResult, engine_result: EngineResult, resolved_versions: array<string,string>, tables: array}
     */
    public function price(TariffVersion $tariff, array $facts, string $lineCode, ?string $tenantId, ReferenceInstant $at, ?CarbonImmutable $asOf = null, array $context = []): array
    {
        $tax = $this->taxTables($lineCode, $at, $asOf);
        // Vehicle Power master: automobile stamp duty from the VERIFIED fiscal_power_cv (never hp / the declared fact).
        $fiscal = app(VehicleStampDutyService::class)->resolve($lineCode, $facts, $tenantId, $at->businessDate(), $tariff->product);
        if ($fiscal['status'] === 'REVIEW_REQUIRED') {
            throw new FiscalPowerReviewRequired($fiscal);
        }
        if ($duty = VehicleStampDutyService::chargeTable($fiscal)) {
            $tax[] = $duty;
        }
        $fees = $this->feeTables($tenantId, $at, $asOf);
        $pricing = $this->engine->price($facts, $tariff->rules, $tax, $fees, [...$context, 'line_code' => $lineCode]);

        $resolved = ['tariff' => $tariff->id];
        foreach ($tax as $t) {
            $resolved[($t['source_table'] === 'vehicle_stamp_duty_rate_schedules' ? 'tax_levy_vehicle:' : 'tax_levy:').$t['key']] = $t['source_id'];
        }
        foreach ($fees as $f) {
            $resolved['fee_schedule:'.$f['key']] = $f['source_id'];
        }
        $inputs = ['facts' => $facts, 'line_code' => $lineCode, 'tenant_id' => $tenantId, 'tariff_rules_hash' => $tariff->rules_hash, 'context' => $context,
            'reference_date' => $at->businessDate(), 'versions' => $resolved];
        $trace = array_map(fn (array $l) => [
            'rule_code' => $l['code'], 'rule_version' => $l['source_version'] ?? $tariff->version,
            'source_table' => $l['source_table'] ?? 'tariff_versions', 'source_id' => $l['source_id'] ?? $tariff->id,
            'condition' => $l['kind'].(isset($l['method']) ? ':'.$l['method'] : '').(isset($l['operator']) ? ':'.$l['fact'].' '.$l['operator'] : ''),
            'input_values' => isset($l['fact']) ? [$l['fact'] => data_get($facts, $l['fact'])] : [],
            'result' => (string) $l['amount_minor'], 'message_key' => 'rating.line.'.strtolower($l['kind']),
        ], $pricing->lines);
        $engineResult = new EngineResult('RATING', 'PRICED', $at->referenceAt->toDateTimeImmutable(), ($asOf ?? CarbonImmutable::now())->toDateTimeImmutable(),
            EngineResult::hashInputs($inputs), $trace, $resolved, false, [], $pricing->warnings);

        return ['pricing' => $pricing, 'engine_result' => $engineResult, 'resolved_versions' => $resolved, 'fiscal_power' => $fiscal,
            'tables' => ['tax_levy_version_id' => ($tax[0]['source_table'] ?? null) === 'tax_levy_versions' ? $tax[0]['source_id'] : null, 'fee_schedule_version_id' => $fees[0]['source_id'] ?? null]];
    }

    /**
     * QuoteService's rating call: price one product for a quote and persist the rating_runs snapshot.
     *
     * @return array{run_id: string, pricing: ?PricingResult, failure: ?string}
     */
    public function rateQuote(Quote $quote, TariffVersion $tariff, ReferenceInstant $at): array
    {
        $runId = (string) Str::uuid();
        $asOf = CarbonImmutable::now();
        // The quote version is part of the run identity: an amended quote that returns to earlier facts is a new run.
        $hashInput = ['facts' => $quote->risk_facts, 'rules_hash' => $tariff->rules_hash, 'quote_version' => (int) $quote->version];
        // The verified fiscal power / stamp duty schedule is a rating input too (a re-rate after verification is a new run).
        $fiscal = app(VehicleStampDutyService::class)->resolve((string) $quote->line_code, (array) $quote->risk_facts, $quote->tenant_id, $at->businessDate(), $tariff->product);
        if (! in_array($fiscal['status'], ['NOT_APPLICABLE', 'NOT_CONFIGURED'], true)) {
            $hashInput['fiscal_power'] = array_intersect_key($fiscal, array_flip(['status', 'fiscal_power_record_id', 'schedule_id', 'rate_xaf', 'exemption']));
        }
        $inputHash = $this->json->hash($hashInput);
        $row = ['id' => $runId, 'tenant_id' => $quote->tenant_id, 'quote_id' => $quote->id, 'tariff_version_id' => $tariff->id, 'input_hash' => $inputHash,
            'input_snapshot' => $this->json->encode($quote->risk_facts), 'engine_version' => DeterministicRatingEngine::ENGINE_VERSION,
            'reference_at' => $at->referenceAt->toIso8601String(), 'recorded_as_of' => $asOf->toIso8601String(),
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        try {
            $r = $this->price($tariff, $quote->risk_facts, $quote->line_code, $quote->tenant_id, $at);
            $output = $r['pricing']->toArray();
            DB::table('rating_runs')->insert([...$row, ...$r['tables'], 'fiscal_power_snapshot' => in_array($r['fiscal_power']['status'], ['APPLIED', 'EXEMPT'], true) ? $this->json->encode($r['fiscal_power']) : null, 'status' => 'SUCCEEDED', 'output_snapshot' => $this->json->encode($output), 'output_hash' => $this->json->hash($output),
                'resolved_versions' => $this->json->encode($r['resolved_versions']), 'engine_result' => $this->json->encode($r['engine_result']->toArray()),
                'branch_allocation' => json_encode($output['branch_allocation']), 'allocation_status' => $output['allocation_status']]);

            return ['run_id' => $runId, 'pricing' => $r['pricing'], 'failure' => null];
        } catch (DomainException|TemporalResolutionException $e) {
            DB::table('rating_runs')->insert([...$row, 'status' => 'FAILED', 'failure_reason' => $e->getMessage(),
                'fiscal_power_snapshot' => $e instanceof FiscalPowerReviewRequired ? $this->json->encode($e->snapshot) : null]);

            return ['run_id' => $runId, 'pricing' => null, 'failure' => $e->getMessage()];
        }
    }

    /**
     * REQ-RAT-004 / REQ-TMP-002: re-price a stored run with the exact versions it used (by id) and
     * report whether the output is byte-identical. Also re-resolves through the Temporal engine at the
     * run's reference date and knowledge time to show the same versions would be chosen today.
     */
    public function reproduce(string $runId): array
    {
        $run = DB::table('rating_runs')->where('id', $runId)->first() ?? throw new DomainException('Rating run not found.');
        if ($run->status !== 'SUCCEEDED' || $run->reference_at === null) {
            throw new DomainException('Only rating v2 successful runs can be reproduced.');
        }
        $quote = Quote::findOrFail($run->quote_id);
        $tariff = TariffVersion::findOrFail($run->tariff_version_id);
        $stored = json_decode($run->resolved_versions, true) ?: [];
        $at = ReferenceInstant::at(CarbonImmutable::parse($run->reference_at));
        $asOf = CarbonImmutable::parse($run->recorded_as_of);
        $facts = json_decode($run->input_snapshot, true);

        $tax = $fees = [];
        foreach ($stored as $k => $id) {
            if (str_starts_with($k, 'tax_levy:')) {
                $tax[] = $this->tableRow('tax_levy_versions', $id, substr($k, 9));
            } elseif (str_starts_with($k, 'fee_schedule:')) {
                $fees[] = $this->tableRow('fee_schedule_versions', $id, substr($k, 13));
            } elseif (str_starts_with($k, 'tax_levy_vehicle:')) {
                // The stamp duty is reproduced from the snapshot taken at rating (the approved rate version is immutable).
                $tax[] = VehicleStampDutyService::chargeTable((array) json_decode((string) $run->fiscal_power_snapshot, true)) ?? throw new DomainException('Missing stamp duty snapshot.');
            }
        }
        $pricing = $this->engine->price($facts, $tariff->rules, $tax, $fees, ['line_code' => $quote->line_code]);
        $hash = $this->json->hash($pricing->toArray());

        $reResolved = null;
        try {
            $reResolved = $this->price($this->tariffFor($tariff->product, $at, $asOf) ?? throw new DomainException('No tariff'), $facts, $quote->line_code, $quote->tenant_id, $at, $asOf)['resolved_versions'];
        } catch (DomainException|TemporalResolutionException) {
        }

        return [
            'rating_run_id' => $run->id, 'reference_at' => $at->referenceAt->toIso8601String(), 'recorded_as_of' => $asOf->toIso8601String(),
            'stored_output_hash' => $run->output_hash, 'reproduced_output_hash' => $hash, 'identical' => $hash === $run->output_hash,
            'versions' => $stored, 'temporal_resolution_matches' => $reResolved !== null && $this->sameVersions($reResolved, $stored),
            'pricing' => $pricing->toArray(),
        ];
    }

    /** Each applicable tax/levy table: one version per (jurisdiction, line_code) resolved on the date. */
    private function taxTables(string $lineCode, ReferenceInstant $at, ?CarbonImmutable $asOf): array
    {
        try {
            $v = $this->versions->resolve('tax_levy', ['jurisdiction' => 'CM', 'line_code' => $lineCode], $at, $asOf);
        } catch (TemporalResolutionException $e) {
            if ($e->reasonCode === TemporalResolutionException::AMBIGUOUS) {
                throw $e;
            }

            return [];
        }

        return [$this->tableRow('tax_levy_versions', $v->id, 'CM/'.$lineCode)];
    }

    /** Every fee code effective on the date; a tenant-specific schedule overrides the global one of the same code. */
    private function feeTables(?string $tenantId, ReferenceInstant $at, ?CarbonImmutable $asOf): array
    {
        $codes = DB::table('fee_schedule_versions')->where('status', 'APPROVED')
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($w) => $w->orWhere('tenant_id', $tenantId)))
            ->distinct()->orderBy('code')->pluck('code');
        $out = [];
        foreach ($codes as $code) {
            foreach ($tenantId !== null ? [$tenantId, null] : [null] as $owner) {
                try {
                    $v = $this->versions->resolve('fee_schedule', ['code' => $code, 'tenant_id' => $owner], $at, $asOf);
                    $out[$code] = $this->tableRow('fee_schedule_versions', $v->id, $code.'/'.($owner ?? 'GLOBAL'));
                    break;
                } catch (TemporalResolutionException $e) {
                    if ($e->reasonCode === TemporalResolutionException::AMBIGUOUS) {
                        throw $e;
                    }
                }
            }
        }

        return array_values($out);
    }

    private function tableRow(string $table, string $id, string $key): array
    {
        $row = DB::table($table)->where('id', $id)->first() ?? throw new DomainException("Missing {$table} row {$id}.");
        ChargeTableService::assertUsable($table, $row);

        return ['key' => $key, 'source_table' => $table, 'source_id' => $row->id, 'version' => (int) $row->version,
            'data_status' => $row->data_status ?? null, 'rules' => json_decode($row->rules, true, 512, JSON_THROW_ON_ERROR)];
    }

    private function sameVersions(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return $a === $b;
    }
}
