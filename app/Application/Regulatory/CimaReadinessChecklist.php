<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Models\ApprovalRequest;
use App\Models\Carrier;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\IntermediaryAuthorization;
use App\Models\Partner;
use App\Models\Regulatory\CompulsoryInsuranceRule;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use App\Models\Regulatory\LegalReference;
use App\Models\Regulatory\MicroinsuranceBranch;
use App\Models\Regulatory\OrganizationNameHistory;
use App\Models\Regulatory\ProductRegulatoryMapping;
use App\Models\Regulatory\RegulatoryAuthority;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\Regulatory\RegulatoryBranchSubclass;
use App\Models\Regulatory\RegulatoryClassDefault;
use App\Models\Regulatory\RegulatoryRegime;
use App\Models\Regulatory\RegulatoryReportingCategory;
use App\Models\Regulatory\RegulatoryReportingMapping;
use App\Models\Regulatory\RegulatoryTerm;

/**
 * REQ-CIMA-006 — CIMA-ready checklist evaluator (read-only).
 *
 * The owner's 28-item checklist (section 60 of the specification) is not in the repository; its exact wording
 * is UNVERIFIED. These items are RECONSTRUCTED from CIMA_REGULATORY_DICTIONARY_V1.md and the traceability
 * matrix (REQ-CIMA-001…006, REQ-DUP-017, REQ-SEED-003). Each item names its source so the owner can
 * reconcile it against section 60. Item count is whatever the sources support: nothing is invented to reach 28.
 *
 * Status: PASS | FAIL | WARN (data gap awaiting the owner, e.g. Q2) | MANUAL (not machine-checkable).
 */
final class CimaReadinessChecklist
{
    public const MEASURES_557 = ['PREMIUM_WRITTEN', 'PREMIUM_COLLECTED', 'COMMISSION_RECORDED', 'COMMISSION_COLLECTED', 'COMMISSION_RATE', 'CURRENT_PERIOD', 'PRIOR_PERIOD'];

    public function __construct(private readonly CimaPublicationGuard $guard) {}

    /** @return array{checklist_code: string, reconstructed: bool, source_note: string, totals: array<string, int>, items: list<array{id: string, label: string, source: string, status: string, detail: string}>} */
    public function evaluate(): array
    {
        $items = [];
        $add = function (string $label, string $source, string $status, string $detail = '') use (&$items): void {
            $items[] = ['id' => sprintf('CIMA-READY-%02d', count($items) + 1), 'label' => $label, 'source' => $source, 'status' => $status, 'detail' => $detail];
        };
        $ok = fn (bool $c) => $c ? 'PASS' : 'FAIL';

        // Dictionary (REQ-CIMA-001)
        $add('CIMA regime seeded and effective-dated', 'Dictionary §versioning', $ok(RegulatoryRegime::where('code', 'CIMA')->where('status', 'ACTIVE')->exists()));
        $branches = RegulatoryBranch::current()->get();
        $add('Article 328 branches 1-23 present', 'Dictionary: Art. 328', $ok($branches->count() === 23), $branches->count().' current branch(es)');
        $add('Branch 19 reserved and never usable', 'Dictionary: Art. 328', $ok($branches->where('reserved', true)->pluck('number')->values()->all() === [19]));
        $add('Branches 14 and 15 never accessory', 'Dictionary: Art. 328-1', $ok($branches->where('accessory_allowed', false)->pluck('number')->sort()->values()->all() === [14, 15]));
        $add('Complementary covers only on branches 20/21', 'Dictionary: Art. 328', $ok($branches->where('complementary_covers_allowed', true)->pluck('number')->sort()->values()->all() === [20, 21]));
        $add('Branch subdivisions recorded', 'Dictionary: subdivisions', RegulatoryBranchSubclass::where('status', 'ACTIVE')->exists() ? 'PASS' : 'WARN');
        $add('Article 717 microinsurance branches present', 'Dictionary: Art. 717', $ok(MicroinsuranceBranch::where('status', 'ACTIVE')->exists()));
        $add('Article 411 reporting categories present', 'Dictionary: Art. 411', $ok(RegulatoryReportingCategory::where('kind', 'ART_411_CATEGORY')->where('status', 'ACTIVE')->exists()));
        $measures = RegulatoryReportingCategory::where('kind', 'ART_557_MEASURE')->where('status', 'ACTIVE')->pluck('code')->all();
        $missing = array_diff(self::MEASURES_557, $measures);
        $add('Article 557 intermediary measures (written/collected premium, recorded/collected commission, rate, current/prior period)', 'Dictionary: Art. 557', $ok($missing === []), $missing ? 'Missing: '.implode(', ', $missing) : '');
        $terms = RegulatoryTerm::where('namespace', 'CIMA_TERM')->where('status', 'ACTIVE')->withCount(['translations as fr_count' => fn ($q) => $q->where('locale', 'fr'), 'translations as en_count' => fn ($q) => $q->where('locale', 'en')])->get();
        $untranslated = $terms->filter(fn ($t) => ! $t->fr_count || ! $t->en_count)->count();
        $add('Controlled terminology bilingual (FR + EN) by semantic code', 'Dictionary: terminology', $ok($terms->isNotEmpty() && $untranslated === 0), "{$untranslated} term(s) missing a language");
        $add('Regulatory authorities recorded', 'Dictionary: authorities', $ok(RegulatoryAuthority::where('status', 'ACTIVE')->exists()));
        $refs = LegalReference::where('status', 'ACTIVE')->pluck('reference')->all();
        $needRefs = ['Article 6', 'Article 7', 'Article 8', 'Article 12', 'Article 13', 'Article 65', 'Article 65-1', 'Article 200', 'Article 328', 'Article 328-1', 'Article 328-2', 'Article 411', 'Article 557', 'Article 717'];
        $add('Legal reference library complete', 'Dictionary §19', $ok(array_diff($needRefs, $refs) === []), implode(', ', array_diff($needRefs, $refs)));
        $add('Compulsory insurance rules recorded (incl. motor liability, branch 10)', 'Dictionary: compulsory insurance', $ok(CompulsoryInsuranceRule::where('status', 'ACTIVE')->where('branch_code', 'like', 'CIMA_10_%')->exists()));

        // Product mapping (REQ-CIMA-003)
        $add('Class default → CIMA branch mappings configured', 'Dictionary: automatic mapping', $ok(RegulatoryClassDefault::where('status', 'ACTIVE')->exists()));
        $products = InsuranceProduct::get();
        $primary = ProductRegulatoryMapping::where('status', 'ACTIVE')->where('relationship_type', 'PRIMARY')->distinct()->pluck('insurance_product_id')->flip();
        $unmapped = $products->reject(fn ($p) => isset($primary[$p->id]))->count();
        $add('Every product has a PRIMARY CIMA branch mapping', 'Dictionary: product_regulatory_mappings', $ok($unmapped === 0), "{$unmapped} product(s) unmapped");
        $pending = ProductRegulatoryMapping::where('status', 'PENDING_APPROVAL')->count();
        $add('No product mapping left awaiting approval', 'Dictionary: maker-checker', $pending === 0 ? 'PASS' : 'WARN', "{$pending} pending");
        $badAcc = ProductRegulatoryMapping::where('status', 'ACTIVE')->where('relationship_type', 'ACCESSORY')->whereIn('branch_code', $branches->where('accessory_allowed', false)->pluck('code'))->count();
        $add('No active accessory mapping on branch 14/15', 'Dictionary: Art. 328-1', $ok($badAcc === 0), "{$badAcc} offending mapping(s)");

        // Insurer authorization (REQ-CIMA-002, REQ-DUP-017)
        $active = InsurerRegulatoryAuthorization::where('status', 'ACTIVE')->get();
        $realCarriersWithProducts = Carrier::where('is_demo', false)->whereIn('id', $products->pluck('carrier_id')->unique())->pluck('id');
        $withoutAuth = $realCarriersWithProducts->diff($active->pluck('carrier_id'))->count();
        $add('Every non-demo insurer selling products holds an ACTIVE CIMA authorization', 'Dictionary: insurer → authorization → branches; OQ Q2', $withoutAuth === 0 ? 'PASS' : 'WARN',
            "{$withoutAuth} insurer(s) without a recorded agrément (owner question Q2 open; publication gate stays on)");
        $add('Every active authorization cites regulator evidence', 'Dictionary: source mandatory', $ok($active->every(fn ($a) => $a->is_demo || filled($a->source_document))));
        $add('No DEMO authorization on a real insurer', 'Dictionary: DEMO provenance', $ok(! InsurerRegulatoryAuthorization::where('is_demo', true)->whereHas('carrier', fn ($c) => $c->where('is_demo', false))->exists()));
        $unapproved = $active->filter(function ($a) {
            $req = $a->approval_request_id ? ApprovalRequest::find($a->approval_request_id) : null;

            return ! $req || ! in_array($req->status, ['APPROVED', 'AUTO_APPROVED'], true) || ($a->approved_by !== null && $a->approved_by === $a->created_by);
        })->count();
        $add('Active authorizations approved through the approval engine by a different user', 'REQ-RBAC-005; maker-checker', $ok($unapproved === 0), "{$unapproved} not traceable to an approved request");
        $registerCarriers = Carrier::where('is_official_register', true)->pluck('id');
        $unlinked = $active->whereIn('carrier_id', $registerCarriers)->whereNull('register_authorization_id')->count();
        $add('Canonical authorizations of register insurers linked to their register-year source', 'REQ-DUP-017', $ok($unlinked === 0), "{$unlinked} unlinked");
        $blocked = $products->filter(fn ($p) => ! in_array($p->status, ['ACTIVE', 'RETIRED'], true) && isset($primary[$p->id]) && $this->guard->violations($p, false) !== [])->count();
        $add('No draft/in-review product blocked by the publication gate', 'Dictionary: publication blocked with reason', $blocked === 0 ? 'PASS' : 'WARN', "{$blocked} blocked");

        // Reporting (Art. 411 / 557)
        $lines = InsuranceLine::where('status', 'ACTIVE')->pluck('code');
        $mappedLines = RegulatoryReportingMapping::where('status', 'ACTIVE')->where('owner_type', 'PLATFORM')->where('subject_type', 'INSURANCE_LINE')->pluck('subject_code');
        $add('Every active class mapped to an Article 411 reporting category', 'PLT-CIMA-012', $ok($lines->diff($mappedLines)->isEmpty()), $lines->diff($mappedLines)->join(', '));
        $brokers = Partner::where('type', 'BROKER')->where('status', 'ACTIVE')->where('is_demo', false)->pluck('id');
        $configured = RegulatoryReportingMapping::where('owner_type', 'PARTNER')->where('setup_screen', 'BRK-SET-CIMA-004')->where('status', 'ACTIVE')->distinct()->pluck('owner_id');
        $add('Active brokers have an Article 557 commission reporting mapping', 'BRK-SET-CIMA-004; Art. 557', $brokers->diff($configured)->isEmpty() ? 'PASS' : 'WARN', $brokers->diff($configured)->count().' broker(s) not configured');

        // Seed provenance (REQ-SEED-003)
        $regBrokers = Partner::where('is_official_register', true)->pluck('id');
        $withAuth = IntermediaryAuthorization::whereIn('partner_id', $regBrokers)->distinct()->pluck('partner_id');
        $add('Register intermediaries carry an effective-dated register authorization', 'REQ-SEED-003', $ok($regBrokers->diff($withAuth)->isEmpty()));
        $orgs = Carrier::where('is_official_register', true)->pluck('id')->merge($regBrokers);
        $withHistory = OrganizationNameHistory::whereIn('subject_id', $orgs)->distinct()->pluck('subject_id');
        $add('Register insurers and intermediaries have a name/brand history', 'REQ-SEED-003', $ok($orgs->diff($withHistory)->isEmpty()), $orgs->diff($withHistory)->count().' without history');

        $add('CIMA codes never shown to customers (labels by semantic code only)', 'Dictionary: context rule; REQ-CIMA-004', 'MANUAL', 'Verify customer screens in the app.');

        $totals = array_count_values(array_column($items, 'status')) + ['PASS' => 0, 'FAIL' => 0, 'WARN' => 0, 'MANUAL' => 0];

        return ['checklist_code' => ReconstructedChecklists::CIMA_READINESS, 'reconstructed' => true,
            'source_note' => ReconstructedChecklists::CIMA_READINESS.": reconstructed from the CIMA dictionary; the owner's 28-item wording (spec section 60) is UNVERIFIED.", 'totals' => $totals, 'items' => $items];
    }
}
