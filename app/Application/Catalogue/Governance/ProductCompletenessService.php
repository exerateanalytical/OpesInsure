<?php

declare(strict_types=1);

namespace App\Application\Catalogue\Governance;

use App\Application\Capabilities\CapabilityResolver;
use App\Application\Catalogue\Governance\Models\ProductGovernance;
use App\Application\Catalogue\Sandbox\ProductSandbox;
use App\Application\DocumentCatalogue\DocumentPublicationGate;
use App\Application\Documents\Engine\ProductDocumentGate;
use App\Application\Regulatory\CimaAuthorizationService;
use App\Application\Regulatory\CimaPublicationGuard;
use App\Application\Rules\RuleSetResolver;
use App\Models\InsuranceProduct;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * REQ-PRD-007 / REQ-PRD-010 — product configuration completeness and publication blockers
 * (PRE §97–98; SCREEN_TO_API_MATRIX owner §35). Read-only: it never applies defaults or writes.
 *
 * Blocking checks (publication refused while any fails):
 *   CAPABILITY_PROFILE       the insurer has an ACTIVE capability profile (owner decision 28: a missing profile blocks governance publication)
 *   REGULATORY_MAPPING       PRIMARY CIMA branch mapping (or a family default that submission applies) + regulatory reference
 *   CIMA_BRANCH_AUTHORIZED   insurer holds an ACTIVE authorization for every PRIMARY / COMPLEMENTARY branch (CimaPublicationGuard)
 *   PRICING_MODE             RATING capability configured + an APPROVED tariff effective on the version start (the publish gate)
 *   UNDERWRITING_MODE        UNDERWRITING capability configured; RULE_ENGINE needs eligibility rules
 *   DOCUMENT_MAPPING         DocumentPublicationGate blocking items + ProductDocumentGate when OPES_GENERATED
 *   ACCOUNTING_MAPPING       ACCOUNTING capability configured; OPES_LEDGER needs ACTIVE ledger accounts in the product currency
 *   CLAIMS_CONFIGURATION     CLAIMS_INTAKE and CLAIMS_DECISION capabilities configured
 *   PRODUCT_TESTS            latest sandbox run of the test pack PASSED on the current configuration
 * Warnings never block (coverages, EN/FR names, governance owner, review date, document warnings).
 */
final class ProductCompletenessService
{
    public const BLOCKING = ['CAPABILITY_PROFILE', 'REGULATORY_MAPPING', 'CIMA_BRANCH_AUTHORIZED', 'PRICING_MODE', 'UNDERWRITING_MODE', 'DOCUMENT_MAPPING', 'ACCOUNTING_MAPPING', 'CLAIMS_CONFIGURATION', 'PRODUCT_TESTS'];

    public function __construct(
        private readonly CimaPublicationGuard $cima,
        private readonly CimaAuthorizationService $authorizations,
        private readonly CapabilityResolver $capabilities,
        private readonly ProductSandbox $sandbox,
        private readonly RuleSetResolver $ruleSets,
    ) {}

    /**
     * @return array{status:string,publishable:bool,score:int,checks:list<array{code:string,blocking:bool,passed:bool,detail:string}>,blockers:list<array>,warnings:list<array>}
     */
    public function evaluate(InsuranceProduct $v): array
    {
        $v->loadMissing('carrierProduct.family');
        $checks = [
            $this->capabilityProfile($v),
            $this->regulatoryMapping($v),
            $this->cimaAuthorized($v),
            $this->pricing($v),
            $this->underwriting($v),
            ...$this->documents($v),
            $this->accounting($v),
            $this->claims($v),
            $this->tests($v),
            ...$this->warnings($v),
        ];
        $blockers = array_values(array_filter($checks, fn ($c) => $c['blocking'] && ! $c['passed']));
        $warnings = array_values(array_filter($checks, fn ($c) => ! $c['blocking'] && ! $c['passed']));
        $blocking = array_filter($checks, fn ($c) => $c['blocking']);
        $passed = count(array_filter($blocking, fn ($c) => $c['passed']));

        return [
            'status' => $blockers !== [] ? 'INCOMPLETE' : ($warnings !== [] ? 'COMPLETE_WITH_WARNINGS' : 'COMPLETE'),
            'publishable' => $blockers === [],
            'score' => $blocking === [] ? 100 : (int) floor(100 * $passed / count($blocking)),
            'checks' => $checks, 'blockers' => $blockers, 'warnings' => $warnings,
        ];
    }

    /** @return list<string> blocking reasons (empty = publishable) */
    public function blockers(InsuranceProduct $v): array
    {
        return array_map(fn ($c) => $c['code'].': '.$c['detail'], $this->evaluate($v)['blockers']);
    }

    private function capabilityProfile(InsuranceProduct $v): array
    {
        $profile = $this->capabilities->profileAt($v->carrier_id);

        return $this->check('CAPABILITY_PROFILE', true, $profile !== null, $profile !== null
            ? 'Insurer capability profile v'.$profile->version.' is active.' : 'Missing capability profile: the insurer has no ACTIVE capability profile; governance publication is blocked.');
    }

    private function regulatoryMapping(InsuranceProduct $v): array
    {
        $primary = $this->cima->activeMappings($v)->where('relationship_type', 'PRIMARY');
        $default = $v->carrierProduct?->family?->default_branch_code;
        $ref = filled($v->regulatory_reference);
        if ($primary->isNotEmpty()) {
            return $this->check('REGULATORY_MAPPING', true, $ref, $ref ? 'PRIMARY CIMA branch '.$primary->pluck('branch_code')->implode(', ').'; reference '.$v->regulatory_reference : 'Regulatory reference is missing.');
        }
        if ($default !== null) {
            return $this->check('REGULATORY_MAPPING', true, $ref, 'No explicit mapping; family default '.$default.' is applied at submission.'.($ref ? '' : ' Regulatory reference is missing.'));
        }

        return $this->check('REGULATORY_MAPPING', true, false, 'Missing regulatory mapping: no PRIMARY CIMA branch mapping and no family default branch.'.($ref ? '' : ' Regulatory reference is missing.'));
    }

    private function cimaAuthorized(InsuranceProduct $v): array
    {
        $mappings = $this->cima->activeMappings($v);
        if ($mappings->isEmpty()) {
            $default = $v->carrierProduct?->family?->default_branch_code;
            if ($default === null) {
                return $this->check('CIMA_BRANCH_AUTHORIZED', true, false, 'No CIMA branch to authorize (map the product first).');
            }
            $ok = $this->authorizations->isAuthorized($v->carrier_id, $default);

            return $this->check('CIMA_BRANCH_AUTHORIZED', true, $ok, $ok ? "Insurer authorized for {$default}." : "Unauthorized CIMA branch: insurer holds no ACTIVE authorization for {$default}.");
        }
        $violations = array_values(array_filter($this->cima->violations($v, false), fn ($m) => ! str_contains($m, 'no PRIMARY CIMA branch mapping')));

        return $this->check('CIMA_BRANCH_AUTHORIZED', true, $violations === [], $violations === [] ? 'Every mapped branch is authorized.' : implode(' ', $violations));
    }

    private function pricing(InsuranceProduct $v): array
    {
        $mode = $this->capabilities->mode($v->carrier_id, 'RATING', $v->id);
        $configured = $mode['source'] !== CapabilityResolver::SOURCE_DEFAULT;
        $on = $v->effective_from?->toDateString() ?? now()->toDateString();
        $tariff = $v->tariffs()->where('status', 'APPROVED')->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on))->exists();
        $detail = ($configured ? 'Pricing mode '.$mode['mode'].'.' : 'Missing pricing mode: RATING is not configured in the insurer capability profile.')
            .($tariff ? ' Approved tariff in force.' : ' No APPROVED tariff effective on '.$on.'.');

        return $this->check('PRICING_MODE', true, $configured && $tariff, $detail);
    }

    private function underwriting(InsuranceProduct $v): array
    {
        $mode = $this->capabilities->mode($v->carrier_id, 'UNDERWRITING', $v->id);
        if ($mode['source'] === CapabilityResolver::SOURCE_DEFAULT) {
            return $this->check('UNDERWRITING_MODE', true, false, 'Missing underwriting mode: UNDERWRITING is not configured in the insurer capability profile.');
        }
        if ($mode['mode'] === 'RULE_ENGINE') {
            $at = ($v->effective_from ?? now())->toDateTimeImmutable();
            $rules = ! empty($v->eligibility_rules) || $this->ruleSets->resolve('ELIGIBILITY', $v->id, $v->line_code, $at)->isNotEmpty()
                || DB::table('rule_sets')->where('insurance_product_id', $v->id)->where('domain', 'ELIGIBILITY')->whereIn('status', ['APPROVED', 'ACTIVE'])->exists();

            return $this->check('UNDERWRITING_MODE', true, $rules, $rules ? 'RULE_ENGINE with approved eligibility rules.' : 'Underwriting mode RULE_ENGINE but no approved eligibility rule set for this version or line.');
        }

        return $this->check('UNDERWRITING_MODE', true, true, 'Underwriting mode '.$mode['mode'].'.');
    }

    /** @return list<array> */
    private function documents(InsuranceProduct $v): array
    {
        $out = [];
        $matrix = app(DocumentPublicationGate::class)->check($v);
        $engine = rescue(fn () => app(ProductDocumentGate::class)->evaluate($v), null, false);
        $failed = $engine ? array_values(array_filter($engine['checks'], fn ($c) => ! $c['passed'])) : [];
        $engineBlocks = $engine && $engine['blocking'] && $failed !== [];
        $problems = [...$matrix['blocking'], ...($engineBlocks ? array_map(fn ($c) => $c['code'].' ('.$c['detail'].')', $failed) : [])];
        $out[] = $this->check('DOCUMENT_MAPPING', true, $problems === [], $problems === []
            ? 'Document mapping OK'.($engine ? ' (issuance mode '.$engine['mode'].')' : '').'.' : 'Document mapping incomplete: '.implode('; ', $problems));
        foreach ($matrix['warnings'] as $w) {
            $out[] = $this->check('DOCUMENT_WARNING', false, false, $w);
        }
        if (! $engineBlocks && $failed !== []) {
            $out[] = $this->check('DOCUMENT_ENGINE_WARNING', false, false, 'Carrier-document mode; not blocking: '.implode(', ', array_column($failed, 'code')));
        }

        return $out;
    }

    private function accounting(InsuranceProduct $v): array
    {
        $mode = $this->capabilities->mode($v->carrier_id, 'ACCOUNTING', $v->id);
        if ($mode['source'] === CapabilityResolver::SOURCE_DEFAULT) {
            return $this->check('ACCOUNTING_MAPPING', true, false, 'Missing accounting mapping: ACCOUNTING is not configured in the insurer capability profile.');
        }
        if ($mode['mode'] === 'OPES_LEDGER') {
            $currency = $v->carrierProduct?->currency ?? 'XAF';
            $ok = DB::table('ledger_accounts')->where('status', 'ACTIVE')->where('currency', $currency)->exists();

            return $this->check('ACCOUNTING_MAPPING', true, $ok, $ok ? "OPES_LEDGER with ACTIVE {$currency} ledger accounts." : "Accounting mode OPES_LEDGER but no ACTIVE ledger account in {$currency}.");
        }

        return $this->check('ACCOUNTING_MAPPING', true, true, 'Accounting mode '.$mode['mode'].'.');
    }

    private function claims(InsuranceProduct $v): array
    {
        $missing = [];
        foreach (['CLAIMS_INTAKE', 'CLAIMS_DECISION'] as $cap) {
            if ($this->capabilities->mode($v->carrier_id, $cap, $v->id)['source'] === CapabilityResolver::SOURCE_DEFAULT) {
                $missing[] = $cap;
            }
        }

        return $this->check('CLAIMS_CONFIGURATION', true, $missing === [], $missing === [] ? 'Claims intake and decision modes configured.' : 'Missing claims configuration: '.implode(', ', $missing).' not configured.');
    }

    private function tests(InsuranceProduct $v): array
    {
        try {
            $latest = $this->sandbox->latestRun($v);
        } catch (Throwable $e) {
            return $this->check('PRODUCT_TESTS', true, false, 'Product tests could not be verified: '.$e->getMessage());
        }
        if ($latest === null) {
            return $this->check('PRODUCT_TESTS', true, false, 'No product test run: run the test policy pack in the sandbox.');
        }
        $run = $latest['run'];
        if (! $latest['current']) {
            return $this->check('PRODUCT_TESTS', true, false, 'The configuration changed since the last test run; re-run the test policy pack.');
        }

        return $this->check('PRODUCT_TESTS', true, $run->status === 'PASSED', $run->status === 'PASSED'
            ? "Test pack passed ({$run->cases_total} cases)." : "Failed product tests: {$run->cases_failed} of {$run->cases_total} cases failed.");
    }

    /** @return list<array> */
    private function warnings(InsuranceProduct $v): array
    {
        $g = ProductGovernance::find($v->id);
        $names = (array) ($v->carrierProduct?->name ?? []);

        return [
            $this->check('COVERAGES', false, DB::table('product_coverages')->where('insurance_product_id', $v->id)->exists(), 'At least one coverage configured.'),
            $this->check('WORDING_EN_FR', false, filled($names['en'] ?? null) && filled($names['fr'] ?? null), 'EN and FR product names.'),
            $this->check('GOVERNANCE_OWNER', false, $g?->owner_user_id !== null, 'Product owner assigned.'),
            $this->check('REVIEW_DATE', false, $g?->next_review_date !== null, 'Next product review date set.'),
        ];
    }

    private function check(string $code, bool $blocking, bool $passed, string $detail): array
    {
        return ['code' => $code, 'blocking' => $blocking, 'passed' => $passed, 'detail' => $detail];
    }
}
