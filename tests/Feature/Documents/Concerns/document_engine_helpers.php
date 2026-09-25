<?php

declare(strict_types=1);

use App\Models\DocumentIssuanceProfile;
use App\Models\DocumentTemplate;
use App\Models\Policy;
use App\Models\User;
use App\Application\Documents\Engine\DocumentTemplateService;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

/* Shared document-engine fixtures (DocumentEngineTest, CanonicalDocumentSecurityTest). */
if (! function_exists('docUser')) {
function docUser(): User
{
    return User::create(['full_name' => 'Doc Staff '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

/** An issued in-force policy of the given line with optional risk facts. */
function docPolicy(string $line = 'AUTO', array $facts = [], ?array $fixture = null): array
{
    $f = $fixture ?? makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    // Fixture only: published versions are frozen (REQ-PRD-001 trigger), so re-line it as a draft and republish.
    $status = $f['product']->status;
    $f['product']->update(['status' => 'DRAFT']);
    $f['product']->update(['line_code' => $line]);
    $f['product']->update(['status' => $status]);
    // Canonical spec DOC-036 (motor attestation): VIN/chassis, make, model and use are required fields.
    $vehicle = ['vin' => 'VF1TESTVIN0000001', 'make' => 'Toyota', 'model' => 'Corolla', 'usage' => 'PRIVATE'];
    if (isset($facts['registration_number'])) {
        $facts += $vehicle;
    }
    if (isset($facts['vehicles'])) {
        $facts['vehicles'] = array_map(fn ($v) => $v + $vehicle, $facts['vehicles']);
    }
    $f['quote']->update(['line_code' => $line, 'risk_facts' => $facts]);
    \App\Models\QuoteOffer::whereKey($f['proposal']->quote_offer_id)->update(['tax_minor' => 0, 'fee_minor' => 0]);
    $policy = Policy::create([
        'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDay(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => ['line_code' => $line, 'coverage_snapshot' => ['coverages' => [['code' => 'RC', 'name' => 'Third-party liability', 'limit_minor' => 500000000, 'deductible_minor' => 0]]]], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now(),
    ]);
    $f['policy'] = $policy;

    return $f;
}

function docAuthorize(array $f, array $overrides = []): DocumentIssuanceProfile
{
    return DocumentIssuanceProfile::create(array_merge(['carrier_id' => $f['carrier']->id, 'issuance_mode' => 'OPES_GENERATED', 'opes_rendering_authorized' => true, 'authorization_reference' => 'AUTH-1', 'default_language' => 'BILINGUAL'], $overrides));
}

/** Published template shortcut for fixtures (the workflow itself is tested separately). */
function docTemplate(string $code, array $o = []): DocumentTemplate
{
    $author = $o['created_by'] ?? docUser()->id;
    $d = array_merge(['document_type_code' => $code, 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL', 'content' => ['sections' => [['heading_en' => 'Terms', 'heading_fr' => 'Conditions', 'body_en' => 'Policy {policy_number}', 'body_fr' => 'Police {policy_number}']]]], $o);
    $svc = app(DocumentTemplateService::class);
    $t = DocumentTemplate::create([
        'code' => DocumentTemplateService::lineage($d), 'document_type_code' => $code, 'ownership' => $d['ownership'], 'carrier_id' => $d['carrier_id'] ?? null,
        'broker_tenant_id' => $d['broker_tenant_id'] ?? null, 'product_id' => $d['product_id'] ?? null, 'insurance_class' => $d['insurance_class'] ?? null,
        'language' => $d['language'], 'version' => $d['version'] ?? 1, 'status' => 'PUBLISHED', 'title_en' => $code, 'title_fr' => $code, 'content' => $d['content'],
        'content_hash' => 'x', 'effective_from' => now()->subYear()->toDateString(), 'created_by' => $author,
    ]);
    $t->update(['content_hash' => $svc->hash($t)]);

    return $t->refresh();
}

function docTemplates(array $codes, array $o = []): void
{
    foreach ($codes as $c) {
        docTemplate($c, $o);
    }
}

function docItems($manifest, ?string $code = null): array
{
    return array_values(array_filter($manifest->items, fn ($i) => $code === null || $i['document_type_code'] === $code));
}

}
