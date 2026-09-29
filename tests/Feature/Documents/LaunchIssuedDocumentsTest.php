<?php

declare(strict_types=1);

// R9 launch verification: REAL issuance flows (payment webhook -> issuance approval, FNOL, claim decision maker-checker,
// cancellation servicing) produce, for every business event, the document from the PUBLISHED canonical template of its
// DOC code — in the customer's language, on the issuing insurer's letterhead, with a verification code that resolves on
// /verify, a QR, a file hash, money in FCFA, the demo watermark only on demo records, and every field the platform holds
// actually filled ("Not recorded" only where the platform has no data).

use App\Application\Claims\ClaimLifecycleService;
use App\Application\Claims\Decisions\ClaimDecisionService;
use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentPackResolver;
use App\Application\Documents\Verification\PublicVerificationService;
use App\Application\Policies\PolicyIssuanceService;
use App\Application\Policies\PolicyServicingService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Document;
use App\Models\DocumentPackManifest;
use App\Models\DocumentTemplate;
use App\Models\PolicyIssuanceRequest;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\CanonicalTemplates\CanonicalTemplateSeeder;
use Database\Seeders\CanonicalTemplates\CanonicalTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/document_engine_helpers.php';

/**
 * Template keys the platform holds NO data for in these flows (printed "Not recorded" by design). Anything else
 * printed "Not recorded" is a mapping gap and fails the test.
 */
const R9_NO_PLATFORM_DATA = [
    'beneficiary.name', 'intermediary.name', 'policy.territory', 'policy.branch', 'policy.special_conditions', 'policy.clause_references', 'policy.payment_terms',
    'policy.payment_schedule', 'policy.contact', 'policy.conditions_reference', 'policy.endorsements', 'policy.components', 'policy.renewal_terms', 'policy.cancellation_rules',
    'conditions.definitions', 'conditions.coverage_framework', 'conditions.exclusions', 'conditions.duties', 'conditions.claims', 'conditions.premium_obligations',
    'conditions.cancellation_renewal', 'conditions.disputes_complaints', 'conditions.effective_version', 'security.stock_serial', 'refund.status',
    'billing.allocation_to_obligations', 'claim.handler_contact', 'claim.next_steps', 'claim.requirements_status', 'claim.requirements_due_dates', 'claim.submission_method',
    'claim.conditional_requirements', 'claim.recoveries', 'claim.appeal', 'payee.bank', 'settlement.discharge', 'decision.conditions', 'issuer.contact', 'claim.validity_period_of_offer', 'claim.tax_withholding_where_applicable', 'claim.rejected_deferred_component',
];

/** Keys the platform holds in these flows: never "Not recorded" (the R9 mapping fixes). */
const R9_MUST_BE_FILLED = [
    'insured.name', 'product.version', 'policy.renewal_date', 'policy.exclusions_reference', 'coverage.status', 'claim.claimant', 'claim.received_at', 'claim.requirements',
    'cancellation.reason', 'cancellation.effective_at', 'cancellation.premium_balance', 'cancellation.refund_status', 'cancellation.coverage_status',
    'billing.cashier_channel', 'billing.balance', 'settlement.amount', 'settlement.gross', 'settlement.payee', 'settlement.breakdown', 'claim.coverage_check',
];

function r9Staff(array $f, array $perms, int $limit): User
{
    $u = docUser();
    $m = TenantMembership::create(['tenant_id' => $f['tenant']->id, 'user_id' => $u->id, 'role_code' => 'CLAIMS_OFFICER', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $f['tenant']->id, 'code' => 'R9_'.Str::random(5), 'permissions' => $perms, 'is_system' => false])->id);
    DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $f['carrier']->id, 'holder_type' => 'USER', 'holder_id' => $u->id,
        'authority_type' => 'CLAIM_SETTLE', 'max_amount_minor' => $limit, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

    return $u;
}

/** Customer, motor quote with coverages, insurer authorizing OpesInsure rendering, and the published canonical templates. */
function r9World(bool $demo = false, array $profile = []): array
{
    // Own disk root: Storage::fake('local') shares one directory that concurrent test runs wipe.
    $root = storage_path('framework/testing/disks/r9-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
    Http::fake(['*' => Http::response(['data' => [['status' => 'ok', 'id' => 't']]])]);
    test()->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    app(CanonicalTemplatesSeeder::class)->run();
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['carrier']->party->update(['display_name' => 'Assurances Kamsi SA']);
    $f['party']->update(['display_name' => 'Ngono Marie Claire']);
    $cov = ['coverages' => [['code' => 'RC', 'name' => 'Third-party liability', 'mandatory' => true, 'limit_minor' => 500000000, 'deductible_minor' => 0],
        ['code' => 'DR', 'name' => 'Legal defence', 'mandatory' => false, 'limit_minor' => 100000000, 'deductible_minor' => 5000000]], 'exclusions' => [['code' => 'X1', 'name' => 'Racing']]];
    $f['quote']->update(['risk_facts' => ['registration_number' => 'LT-456-CM', 'vin' => 'VF1TESTVIN0000001', 'make' => 'Toyota', 'model' => 'Corolla', 'usage' => 'PRIVATE', 'model_year' => 2019]]);
    \App\Models\QuoteOffer::whereKey($f['proposal']->quote_offer_id)->update(['coverage_snapshot' => $cov, 'tax_minor' => 5000, 'fee_minor' => 5000, 'premium_minor' => 90000]);
    docAuthorize($f, $profile);
    // The proposal snapshot as ProposalService writes it (offer terms + coverage snapshot).
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['offer_id' => $f['proposal']->quote_offer_id, 'premium_minor' => 90000, 'tax_minor' => 5000,
        'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF', 'coverage_snapshot' => $cov, 'line_code' => 'AUTO']]);
    if ($demo) {
        DB::table('carriers')->where('id', $f['carrier']->id)->update(['is_demo' => true]);
    }
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);

    return $f;
}

/** Real flow: mobile money webhook (payment reconciled -> issuance request) then desk approval (POLICY_ISSUED). */
function r9Issue(array $f): \App\Models\Policy
{
    app(\App\Application\Payments\WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => $f['payment']->amount_minor, 'currency' => $f['payment']->currency, 'status' => 'SUCCEEDED',
    ], 'sig');

    return app(PolicyIssuanceService::class)->approve(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail(),
        ['policy_number' => 'POL-R9-'.Str::upper(Str::random(6)), 'carrier_reference' => 'CR-R9'], docUser());
}

/** Captures every shell render keyed by document number. */
function r9Capture(): \ArrayObject
{
    $renders = new \ArrayObject;
    View::composer('pdf.engine-shell', function ($view) use ($renders) {
        $d = $view->getData();
        $renders[$d['documentNumber']] = $d + ['html' => null];
    });

    return $renders;
}

/** @return list<string> template keys printed "Not recorded" */
function r9NotRecorded(array $render): array
{
    $keys = [];
    foreach (array_merge($render['templateParty'], $render['templateContent'], $render['templateTables']) as $r) {
        if (($r['recorded'] ?? true) === false) {
            $keys[] = (string) $r['key'];
        }
    }

    return $keys;
}

it('R9: real issuance, claim, decision and cancellation flows issue every document from its published canonical template, fully filled', function () {
    $f = r9World();
    $renders = r9Capture();
    $policy = r9Issue($f);
    app(TenantContext::class)->set($f['tenant']->id);

    // FNOL (CLAIM_REGISTERED).
    $claim = app(ClaimLifecycleService::class)->fnol($f['tenant']->id, ['idempotency_key' => (string) Str::uuid(), 'policy_id' => $policy->id,
        'claimant_party_id' => $f['party']->id, 'loss_occurred_at' => now()->subHours(3)->toIso8601String(), 'loss_details' => ['description' => 'Rear-ended at a traffic light.'],
        'loss_location' => 'Douala, Akwa', 'estimated_loss_minor' => 25000000], $f['user']);
    // Claim decision under maker-checker (CLAIM_APPROVED).
    $claim->update(['status' => 'CARRIER_REVIEW']);
    $perms = ['claims.view', 'claims.decision.propose', 'claims.decision.approve'];
    $decision = app(ClaimDecisionService::class)->propose($claim->refresh(), ['decision' => 'APPROVE', 'reason_codes' => ['COVERED_IN_FULL'],
        'rationale' => 'Assessed against the adjuster report and policy wording.', 'heads' => [['head' => 'REPAIR', 'amount_minor' => 300_000]]], r9Staff($f, $perms, 5_000_000));
    app(ClaimDecisionService::class)->approve($decision, r9Staff($f, $perms, 5_000_000));
    expect($claim->refresh()->status)->toBe('APPROVED');

    // Customer cancellation through servicing, maker-checker (CANCELLATION_ISSUED).
    DB::table('cancellation_rule_versions')->insert(['id' => (string) Str::uuid(), 'line_code' => 'AUTO', 'version' => 1, 'status' => 'APPROVED', 'basis' => 'PRO_RATA',
        'short_rate_basis_points' => 10000, 'admin_fee_minor' => 0, 'effective_from' => '2026-01-01', 'effective_until' => null, 'created_by' => docUser()->id, 'created_at' => now(), 'updated_at' => now()]);
    $tx = app(PolicyServicingService::class)->request($policy->refresh(), ['type' => 'CANCELLATION', 'effective_at' => now()->addDays(10)->toIso8601String(),
        'reason_code' => 'CUSTOMER_REQUEST', 'initiated_by' => 'CUSTOMER'], docUser());
    app(PolicyServicingService::class)->approve($tx, docUser());

    $expected = [
        'POLICY_ISSUED' => ['INSURANCE_POLICY', 'POLICY_SCHEDULE', 'GENERAL_CONDITIONS', 'PREMIUM_RECEIPT', 'MOTOR_INSURANCE_ATTESTATION', 'MOTOR_INSURANCE_CERTIFICATE'],
        'CLAIM_REGISTERED' => ['CLAIM_ACKNOWLEDGEMENT', 'CLAIM_REFERENCE_CONFIRMATION', 'CLAIM_REQUIREMENTS_LIST'],
        'CLAIM_APPROVED' => ['CLAIM_DECISION', 'SETTLEMENT_OFFER'],
        'CANCELLATION_ISSUED' => ['CANCELLATION_TERMINATION_NOTICE', 'MOTOR_INSURANCE_CANCELLATION_CERTIFICATE'],
    ];
    $report = [];
    $gaps = [];
    $filled = [];
    foreach ($expected as $trigger => $codes) {
        $manifest = DocumentPackManifest::where(['policy_id' => $policy->id, 'trigger' => $trigger])->first();
        expect($manifest)->not->toBeNull($trigger);
        $items = collect($manifest->items)->keyBy('document_type_code');
        foreach ($codes as $code) {
            $item = $items[$code] ?? null;
            expect($item)->not->toBeNull("{$trigger} {$code}")
                ->and($item['state'])->toBe('GENERATED', "{$trigger} {$code}: ".($item['reason'] ?? ''));
            $doc = Document::findOrFail($item['document_id']);
            $template = DocumentTemplate::findOrFail($doc->document_template_id);
            $spec = DB::table('document_types')->where('canonical_code', $code)->value('canonical_spec_id');
            // Published canonical template of this DOC code (owner-approved seed), not a fallback / generic shell.
            expect($template->status)->toBe('PUBLISHED')->and($template->ownership)->toBe('PLATFORM')
                ->and($template->approved_by)->toBe(CanonicalTemplateSeeder::OWNER_APPROVER_ID)
                ->and($template->content['canonical_spec_id'] ?? null)->toBe($spec, $code)
                ->and($template->document_type_code)->toBe($code);
            // Customer language (bilingual insurer default carries the customer's EN), insurer letterhead.
            expect($doc->language)->toBe('BILINGUAL')->and($template->language)->toBe('BILINGUAL')
                ->and($doc->issuer_type)->toBe('INSURER')->and($doc->issuer_carrier_id)->toBe($f['carrier']->id);
            // Hash, verification code resolving on /verify, QR.
            $disk = Storage::disk((string) config('lifecycle.documents_disk', 'local'));
            expect($disk->exists($doc->storage_key))->toBeTrue($code.' '.$doc->storage_key.' status='.$doc->status.' files='.implode(',', array_map('basename', $disk->allFiles('documents'))).' docs='.Document::withoutGlobalScopes()->orderBy('created_at')->get()->map(fn ($x) => $x->document_number.'/'.$x->document_type_code.'/'.$x->status.'/'.$x->generation_trigger)->implode(','));
            $bytes = $disk->get($doc->storage_key);
            expect(hash('sha256', $bytes))->toBe($doc->sha256)->and($doc->content_hash_sha256)->toMatch('/^[a-f0-9]{64}$/')
                ->and($doc->verification_code)->not->toBeEmpty();
            $status = app(PublicVerificationService::class)->lookup($doc->verification_code, null, 'QR_PAGE', 'r9')['status'];
            expect($status)->not->toBe('NOT_FOUND', $code);
            $render = $renders[$doc->document_number] ?? null;
            expect($render)->not->toBeNull($code)->and($render['qr'])->not->toBeEmpty()
                ->and($render['verificationCode'])->not->toBeEmpty()->and($render['hashFragment'])->toBe(substr((string) $doc->content_hash_sha256, 0, 16))
                ->and($render['letterhead']['issuer']['name'] ?? null)->toBe('Assurances Kamsi SA')->and($render['issuerName'])->toBe('Assurances Kamsi SA')->and($render['demoRecord'])->toBeFalse()
                ->and($doc->provenance['demo_watermark'])->toBeFalse();
            // Money in FCFA, never "XAF" after an amount.
            $html = view('pdf.engine-shell', $render)->render();
            expect($html)->not->toMatch('/\d XAF/')->and($html)->not->toContain('DEMONSTRATION');
            $nr = r9NotRecorded($render);
            foreach ($nr as $k) {
                if (! in_array($k, R9_NO_PLATFORM_DATA, true)) {
                    $gaps[] = "{$spec} {$code}: {$k}";
                }
            }
            $allKeys = array_column(array_merge($render['templateParty'], $render['templateContent']), 'key');
            foreach (array_intersect(R9_MUST_BE_FILLED, $allKeys) as $k) {
                $filled[$k] = ! in_array($k, $nr, true);
            }
            $report[] = sprintf('%-20s %-8s %-42s %-24s tpl=%s v%d lang=%s verify=%s NR=[%s]', $trigger, $spec, $code, $doc->document_number, strtok((string) $template->code, '|'),
                $template->version, $doc->language, $status, implode(',', $nr));
        }
    }
    expect($gaps)->toBe([]);
    expect(array_keys(array_filter($filled, fn ($ok) => ! $ok)))->toBe([]);
    expect(array_keys($filled))->toContain('insured.name', 'claim.claimant', 'cancellation.reason', 'billing.cashier_channel', 'policy.exclusions_reference');

    // Money: the schedule prints the premium in FCFA.
    $schedule = Document::where(['policy_id' => $policy->id, 'document_type_code' => 'POLICY_SCHEDULE', 'generation_trigger' => 'POLICY_ISSUED'])->whereNotNull('pack_manifest_id')->firstOrFail();
    expect(view('pdf.engine-shell', $renders[$schedule->document_number])->render())->toContain('1 000 FCFA');

    // /verify page resolves the printed code.
    $this->get('/verify?code='.$schedule->verification_code)->assertOk()->assertSee('Assurances Kamsi SA');

    // Three real-flow PDFs for visual review.
    $out = getenv('R9_PDF_DIR') ?: storage_path('framework/testing/r9-pdf');
    File::ensureDirectoryExists($out);
    foreach (['POLICY_SCHEDULE' => $policy->id, 'MOTOR_INSURANCE_ATTESTATION' => $policy->id, 'CLAIM_ACKNOWLEDGEMENT' => $policy->id] as $code => $pid) {
        $d = Document::where(['policy_id' => $pid, 'document_type_code' => $code])->whereNotNull('document_template_id')->oldest()->firstOrFail();
        File::put($out.'/'.$code.'.pdf', Storage::disk((string) config('lifecycle.documents_disk', 'local'))->get($d->storage_key));
    }
    File::put($out.'/coverage.txt', implode("\n", $report));
});

it('R9: a single-language insurer default gives way to the customer\'s language; the demo watermark prints only on demo records', function () {
    $f = r9World(demo: true, profile: ['default_language' => 'FR', 'languages' => ['FR', 'EN']]);
    $renders = r9Capture();
    $policy = r9Issue($f);
    $schedule = Document::where(['policy_id' => $policy->id, 'document_type_code' => 'POLICY_SCHEDULE', 'generation_trigger' => 'POLICY_ISSUED'])->whereNotNull('pack_manifest_id')->firstOrFail();
    // Customer locale "en" and the insurer allows EN.
    $prof = app(DocumentEngine::class)->profileFor($policy->fresh(['proposal.offer']));
    expect($schedule->language)->toBe('EN', json_encode([$prof?->only(['default_language', 'languages', 'status']), DocumentEngine::languageFor($policy, $prof), User::where('party_id', $policy->party_id)->pluck('locale'), DocumentTemplate::where('document_type_code', 'POLICY_SCHEDULE')->get(['language', 'status', 'ownership', 'insurance_class', 'product_id', 'carrier_id', 'effective_from'])->toArray(), json_decode((string) $schedule->issuance_snapshot, true)['template'] ?? null]))->and(DocumentTemplate::find($schedule->document_template_id)->language)->toBe('EN')
        ->and($schedule->provenance['demo_watermark'])->toBeTrue()->and($renders[$schedule->document_number]['demoRecord'])->toBeTrue();
    expect(view('pdf.engine-shell', $renders[$schedule->document_number])->render())->toContain('DEMONSTRATION');

    // A French customer gets French.
    $g = r9World(profile: ['default_language' => 'EN', 'languages' => ['FR', 'EN']]);
    $g['user']->update(['locale' => 'fr']);
    $p2 = r9Issue($g);
    $s2 = Document::where(['policy_id' => $p2->id, 'document_type_code' => 'POLICY_SCHEDULE', 'generation_trigger' => 'POLICY_ISSUED'])->whereNotNull('pack_manifest_id')->firstOrFail();
    expect($s2->language)->toBe('FR')->and($s2->provenance['demo_watermark'])->toBeFalse();
});

it('R9: every document a platform trigger issues has a published canonical template in BILINGUAL, FR and EN', function () {
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    app(CanonicalTemplatesSeeder::class)->run();
    $trigger = (new ReflectionClassConstant(DocumentPackResolver::class, 'TRIGGER_DOCUMENTS'))->getValue();
    $codes = array_unique(array_merge(array_merge(...array_values($trigger)), array_merge(...array_values(DocumentEngine::PROVIDER_TRIGGERS)),
        ['INSURANCE_POLICY', 'POLICY_SCHEDULE', 'GENERAL_CONDITIONS', 'MOTOR_INSURANCE_ATTESTATION', 'MOTOR_INSURANCE_CERTIFICATE', 'MOTOR_INSURANCE_CANCELLATION_CERTIFICATE',
            'ENDORSEMENT', 'REVISED_POLICY_SCHEDULE', 'RENEWAL_CONFIRMATION']));
    $missing = [];
    foreach ($codes as $code) {
        if (! DB::table('document_types')->where('canonical_code', $code)->exists()) {
            continue; // not a catalogue code (reported by the catalogue tests)
        }
        foreach (['BILINGUAL', 'FR', 'EN'] as $lang) {
            if (! DocumentTemplate::where(['document_type_code' => $code, 'language' => $lang, 'status' => 'PUBLISHED', 'ownership' => 'PLATFORM'])->exists()) {
                $missing[] = "{$code} {$lang}";
            }
        }
    }
    expect($missing)->toBe([]);
});
