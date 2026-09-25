<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Risk;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Kyc\Models\KycRiskAssessment;
use App\Application\Kyc\Risk\CustomerRiskRatingService;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-AML-002 — explainable customer AML risk rating. It does NOT re-implement the rating: the weighted
 * customer / geography (country) / product / channel score, bands, PEP / sanctions hard triggers, EDD triggers,
 * source of funds / wealth requirements and the rescreen-by-band schedule are CustomerRiskRatingService (owner
 * decision 27, config kyc.risk — every weight / band / period NULL until configured). This layer adds:
 *
 *   - screening facts from App\Application\Compliance\Aml\Screening\ScreeningService (agent E8) when that class
 *     exists and exposes riskFacts(tenantId, partyId): list<string> (e.g. PEP_CONFIRMED_MATCH); otherwise the KYC
 *     ScreeningCheck rows already read by CustomerRiskRatingService are the source (MANUAL_AUDITED);
 *   - the source of funds / wealth status (declared through the existing KYC sources endpoint);
 *   - a human-readable explanation of every factor, stored append-only in aml_risk_reviews;
 *   - EDD workflow: HIGH or EDD-required ratings open (or reuse) one AML_EDD case per customer on the case engine;
 *   - the periodic rescreen date by band (kyc:rescreen-due opens the rounds; nothing is scheduled while NULL).
 */
final class AmlRiskRatingService
{
    public const SCREENING_SERVICE = 'App\\Application\\Compliance\\Aml\\Screening\\ScreeningService';

    public const EDD_CASE_TYPE = 'AML_EDD';

    public function __construct(
        private readonly CustomerRiskRatingService $rating,
        private readonly CaseService $cases,
        private readonly AuditWriter $audit,
    ) {}

    /** Latest non-superseded KYC submission of a customer in the tenant. */
    public function submissionFor(string $tenantId, string $partyId): KycSubmission
    {
        $s = KycSubmission::where('tenant_id', $tenantId)->where('party_id', $partyId)->whereNull('superseded_by_submission_id')->latest('created_at')->first();
        if (! $s) {
            throw ValidationException::withMessages(['party_id' => 'The customer has no KYC submission to rate.']);
        }

        return $s;
    }

    /**
     * @param  array<string, mixed>  $inputs  country_code, product_codes, channel, customer_type (overlay on the previous inputs)
     * @return array<string, mixed>
     */
    public function rate(string $tenantId, string $partyId, array $inputs, string $reason, ?User $actor): array
    {
        $s = $this->submissionFor($tenantId, $partyId);
        [$screeningFacts, $source] = $this->screeningFacts($tenantId, $partyId);
        $prev = $this->rating->latest($s);
        if ($screeningFacts !== []) {
            $inputs['declared_factors'] = array_values(array_unique([...(array) ($inputs['declared_factors'] ?? $prev?->inputs['declared_factors'] ?? []), ...$screeningFacts]));
        }

        return DB::transaction(function () use ($s, $inputs, $reason, $actor, $screeningFacts, $source) {
            $a = $this->rating->assess($s, $inputs, [], $actor?->id, $reason);
            $explanation = $this->explain($a, $screeningFacts, $source);
            $edd = $a->rating === 'HIGH' || $a->edd_required;
            $case = $edd ? $this->eddCase($s, $a, $explanation, $actor) : null;
            $id = (string) Str::uuid();
            DB::table('aml_risk_reviews')->insert([
                'id' => $id, 'tenant_id' => $s->tenant_id, 'party_id' => $s->party_id, 'kyc_submission_id' => $s->id, 'kyc_risk_assessment_id' => $a->id,
                'band' => $a->rating, 'score' => $a->score, 'explanation' => json_encode($explanation, JSON_THROW_ON_ERROR), 'screening_source' => $source,
                'edd_required' => $edd, 'edd_case_id' => $case?->id, 'next_rescreen_at' => $a->next_rescreen_at, 'reason' => $reason,
                'rated_by' => $actor?->id, 'created_at' => now(),
            ]);
            $this->audit->record('aml.risk.rated', 'party', $s->party_id, ['aml_risk_review_id' => $id, 'band' => $a->rating, 'score' => $a->score,
                'edd_required' => $edd, 'edd_case_id' => $case?->id, 'screening_source' => $source], $reason);

            return $this->present((array) DB::table('aml_risk_reviews')->find($id));
        });
    }

    /** @return array<string, mixed>|null */
    public function latest(string $tenantId, string $partyId): ?array
    {
        $row = DB::table('aml_risk_reviews')->where('tenant_id', $tenantId)->where('party_id', $partyId)->orderByDesc('created_at')->first();

        return $row ? $this->present((array) $row) : null;
    }

    /** @return array{0: list<string>, 1: string} */
    private function screeningFacts(string $tenantId, string $partyId): array
    {
        if (class_exists(self::SCREENING_SERVICE)) {
            $svc = app(self::SCREENING_SERVICE);
            if (method_exists($svc, 'riskFacts')) {
                $facts = array_values(array_filter(array_map(fn ($f) => strtoupper((string) $f), (array) $svc->riskFacts($tenantId, $partyId))));
                if ($facts !== []) {
                    return [$facts, 'SCREENING_SERVICE'];
                }
                // No list-screening facts for the party: the KYC screening checks remain the only screening source.
            }
        }

        return [[], 'KYC_SCREENING_CHECKS'];
    }

    /** @return array<string, mixed> */
    private function explain(KycRiskAssessment $a, array $screeningFacts, string $source): array
    {
        $labels = ['customer' => 'Customer type', 'country' => 'Geography', 'product' => 'Product', 'channel' => 'Channel', 'occupation' => 'Occupation'];
        $factors = [];
        foreach ((array) $a->factors as $f) {
            $name = $labels[$f['factor']] ?? $f['factor'];
            $vals = implode(', ', (array) $f['values']) ?: 'none';
            $factors[] = $f + ['label' => $name, 'explanation' => match ($f['status']) {
                'SCORED' => "{$name} {$vals}: score {$f['score']} x weight {$f['weight']}.",
                'NO_INPUT' => "{$name}: no input recorded; the score cannot be computed.",
                'NOT_APPLICABLE' => "{$name}: not configured and not supplied; ignored.",
                default => "{$name} {$vals}: weight or score not configured (UNVERIFIED).",
            }];
        }
        $facts = (array) ($a->triggers['facts'] ?? []);
        $screening = array_values(array_filter($facts, fn ($f) => preg_match('/^(PEP|SANCTIONS|ADVERSE_MEDIA)/', (string) $f)));
        $sources = [];
        foreach (['source_of_funds', 'source_of_wealth'] as $k) {
            $sources[$k] = ! $a->{$k.'_required'} ? 'NOT_REQUIRED' : (empty($a->{$k}) ? 'MISSING' : 'DECLARED');
        }
        $hard = (array) ($a->triggers['hard'] ?? []);
        $summary = match (true) {
            $hard !== [] => 'HIGH by hard trigger: '.implode(', ', $hard).'.',
            $a->rating === 'UNRATED' => 'UNRATED: configuration or inputs missing ('.implode(', ', (array) $a->configuration_gaps).').',
            default => "{$a->rating}: weighted score {$a->score} against the configured bands.",
        };

        return ['band' => $a->rating, 'score' => $a->score, 'summary' => $summary, 'factors' => $factors,
            'screening' => ['source' => $source, 'facts' => array_values(array_unique([...$screening, ...$screeningFacts])), 'mode' => $a->screening_mode],
            'sources_of_funds_wealth' => $sources, 'hard_triggers' => $hard, 'edd_triggers' => (array) ($a->triggers['edd'] ?? []),
            'configuration_gaps' => (array) $a->configuration_gaps, 'kyc_risk_assessment_version' => $a->version,
            'rescreen' => ['band' => $a->rating, 'next_rescreen_at' => $a->next_rescreen_at?->toIso8601String(),
                'note' => $a->next_rescreen_at ? 'Periodic rescreen scheduled by band (kyc.risk.rescreen_months).' : 'No rescreen period configured for this band (kyc.risk.rescreen_months is NULL).']];
    }

    private function eddCase(KycSubmission $s, KycRiskAssessment $a, array $explanation, ?User $actor): WorkCase
    {
        $open = DB::table('aml_risk_reviews')->where('tenant_id', $s->tenant_id)->where('party_id', $s->party_id)->whereNotNull('edd_case_id')
            ->whereExists(fn ($q) => $q->from('cases')->whereColumn('cases.id', 'aml_risk_reviews.edd_case_id')->whereNull('cases.closed_at')
                ->whereNotIn('cases.status', ['CLOSED', 'CANCELLED']))
            ->orderByDesc('created_at')->value('edd_case_id');
        if ($open) {
            return WorkCase::withoutGlobalScopes()->findOrFail($open);
        }

        return $this->cases->open($s->tenant_id, self::EDD_CASE_TYPE, [
            'title' => 'Enhanced due diligence: '.$explanation['summary'], 'priority' => 'HIGH',
            'subject_type' => 'party', 'subject_id' => $s->party_id, 'source_type' => 'kyc_risk_assessment', 'source_id' => $a->id,
            'idempotency_key' => 'aml-edd:'.$a->id,
        ], $actor);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        return ['id' => $row['id'], 'party_id' => $row['party_id'], 'kyc_submission_id' => $row['kyc_submission_id'], 'kyc_risk_assessment_id' => $row['kyc_risk_assessment_id'],
            'band' => $row['band'], 'score' => $row['score'] === null ? null : (float) $row['score'],
            'explanation' => is_string($row['explanation']) ? json_decode($row['explanation'], true) : $row['explanation'],
            'screening_source' => $row['screening_source'], 'edd_required' => (bool) $row['edd_required'], 'edd_case_id' => $row['edd_case_id'],
            'next_rescreen_at' => $row['next_rescreen_at'], 'reason' => $row['reason'], 'created_at' => (string) $row['created_at']];
    }
}
