<?php

declare(strict_types=1);

/**
 * Launch 2026-10-02 (R3): end-to-end replay of the commercial AGENT web workspace (/account agent pages) over HTTP,
 * with the real AGENT role (RoleCatalogue::AGENT_PERMISSIONS), the exact payloads the pages send and the field names
 * the pages render: lead → client → KYC with document → needs → quote → send → proposal → documents → information
 * response → payment request → policy → endorsement request → cancellation preview → claim assist → sticker assign →
 * commission view. Every step is then replayed by another agent of the same tenant and refused.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{InsuranceLine, Policy, Proposal, Quote, QuoteOffer, StickerStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function r3H($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

/** Asserts every key a page reads is present on the row (value may be null). */
function r3Keys(array $row, array $keys, string $where): void
{
    foreach ($keys as $k) {
        expect(array_key_exists($k, $row))->toBeTrue("{$where}: missing field '{$k}' (has: ".implode(',', array_keys($row)).')');
    }
}

function r3Agent($tenant = null, string $phone = '+237680031001'): array
{
    $a = $tenant ? makeMobileAgentFixtureInTenant($tenant, $phone) : makeMobileAgentFixture($phone);
    $a['role']->update(['permissions' => RoleCatalogue::AGENT_PERMISSIONS]);

    return $a;
}

it('replays the full agent journey from the /account pages and refuses every step to another agent', function () {
    // The whole journey runs in well under a minute; per-user write throttles are covered elsewhere.
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    $a = r3Agent();
    $t = $a['tenant'];
    $b = r3Agent($t, '+237680031002');
    $line = InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => ['en' => 'Motor', 'fr' => 'Automobile'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    \Illuminate\Support\Facades\DB::table('disclosure_schema_versions')->insert(['id' => (string) Str::uuid(), 'insurance_line_id' => $line->id, 'version' => 1, 'status' => 'APPROVED',
        'questions' => json_encode([['code' => 'prior_claims', 'label' => ['en' => 'Claims in the last 3 years?', 'fr' => 'Sinistres ?'], 'type' => 'boolean', 'required' => true]]),
        'schema_hash' => str_repeat('b', 64), 'effective_from' => '2026-01-01', 'created_by' => $b['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    Passport::actingAs($a['user']);

    // ---- /account/leads: lead → client ---------------------------------------------------------------------------
    $lead = $this->postJson('/api/v1/mobile/partner/agent/leads', ['full_name' => 'Launch Journey Client', 'phone_e164' => '+237690031001', 'product_interest' => 'MOTOR'], r3H($t))->assertStatus(201)->json('data');
    r3Keys($lead, ['id', 'full_name', 'status', 'updated_at'], 'lead');
    $conv = $this->postJson("/api/v1/mobile/partner/agent/leads/{$lead['id']}/convert", ['consent_confirmed' => true], r3H($t))->assertStatus(201)->json('data');
    $client = $conv['client'];
    $cid = $client['id'];
    $leadRow = collect($this->getJson('/api/v1/mobile/partner/agent/leads', r3H($t))->assertOk()->json('data'))->firstWhere('id', $lead['id']);
    expect($leadRow['converted_customer_id'] ?? null)->toBe($cid); // customers/{id}/activities finds the diary through it

    // /account/customers/{id}
    $c = $this->getJson("/api/v1/mobile/agent/clients/{$cid}", r3H($t))->assertOk()->json('data');
    r3Keys($c, ['id', 'full_name', 'phone_e164', 'party_id', 'kyc_status'], 'client');

    // ---- /account/customers/{id}/kyc: capture a document, attach it, submit ------------------------------------------
    $this->getJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc", r3H($t))->assertOk()->assertJsonPath('data.submission', null);
    $png = base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\0", 64));
    $doc = $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/documents", ['category' => 'KYC', 'mime_type' => 'image/png', 'file_base64' => $png], r3H($t))->assertStatus(201)->json('data');
    $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc/documents", ['document_id' => $doc['id'], 'purpose' => 'IDENTITY'], r3H($t))->assertStatus(201);
    $kyc = $this->getJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc", r3H($t))->assertOk()->json('data.submission');
    r3Keys($kyc, ['status', 'kyc_level', 'submitted_at', 'reviewed_at', 'expires_at', 'remediation_reason', 'requirements', 'documents'], 'kyc submission');
    r3Keys($kyc['documents'][0], ['purpose', 'category', 'scan_status', 'verification_status'], 'kyc document');
    foreach ($kyc['requirements'] as $req) {
        r3Keys($req, ['requirement_code', 'mandatory', 'satisfied'], 'kyc requirement');
    }
    \App\Models\Document::whereKey($doc['id'])->update(['scan_status' => 'CLEAN']); // the malware scanner ran
    $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc/submit", ['notes' => 'Captured in branch'], r3H($t))->assertSuccessful();
    expect(strtoupper((string) $this->getJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc", r3H($t))->json('data.submission.status')))->not->toBe('DRAFT');

    // ---- /account/agent/needs: saves the recommendation on the lead diary ----------------------------------------
    $this->postJson("/api/v1/mobile/partner/agent/leads/{$lead['id']}/activities", ['entry_type' => 'NOTE', 'body' => 'Needs: Motor, Home'], r3H($t))->assertStatus(201);
    $act = $this->getJson("/api/v1/mobile/partner/agent/leads/{$lead['id']}/activities", r3H($t))->assertOk()->json('data');
    r3Keys($act[0], ['entry_type', 'body', 'created_at', 'follow_up_at'], 'lead activity');

    // ---- /account/agent/products ----------------------------------------------------------------------------------
    $chain = makeMobileFinanceProposalChain($t);
    $src = QuoteOffer::findOrFail($chain['proposal']->quote_offer_id);
    $this->seed(\Database\Seeders\DocumentCatalogueSeeder::class);
    \Illuminate\Support\Facades\DB::table('product_document_requirements')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $src->product_id, 'kind' => 'PRODUCT_TYPE', 'product_type_code' => 'MOTOR_TPL',
        'variant_code' => '', 'status' => 'ACTIVE', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson('/api/v1/distribution/catalogue?include_blocked=1', r3H($t))->assertOk();
    $this->getJson("/api/v1/catalogue/products/{$src->product_id}", r3H($t))->assertOk()->assertJsonStructure(['data' => ['name', 'code', 'line_code']]);

    // ---- quote for the client (/account/buy), then /account/agent/quotes/{id} ----------------------------------------
    $quoteId = $this->postJson('/api/v1/quotes', ['customer_id' => $cid, 'line_code' => 'AUTO', 'channel' => 'AGENT', 'risk_facts' => ['registration_number' => 'LT310AB', 'fiscal_power' => 7, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON']], r3H($t))->assertStatus(202)->json('data.id');
    $offer = makeMobileTestQuoteOffer(Quote::findOrFail($quoteId), $src->carrier_id, $src->product_id, $src->tariff_version_id, ['total_minor' => 150000, 'premium_minor' => 150000, 'comparison_rank' => 1]);
    Quote::whereKey($quoteId)->update(['lifecycle_state' => 'CALCULATED', 'status' => 'RATED']);
    $q = $this->getJson("/api/v1/quotes/{$quoteId}", r3H($t))->assertOk()->json('data');
    r3Keys($q['quote'], ['quote_number', 'line_code', 'lifecycle_state', 'status', 'created_at', 'expires_at', 'party_id'], 'quote');
    r3Keys($q['offers'][0], ['id', 'total_minor', 'status', 'comparison_rank', 'carrier', 'product'], 'quote offer');
    expect($q['offers'][0]['carrier']['party']['display_name'] ?? null)->not->toBeNull();
    $row = collect($this->getJson('/api/v1/mobile/partner/agent/quotes', r3H($t))->assertOk()->json('data'))->firstWhere('id', $quoteId);
    r3Keys($row, ['id', 'customer_id', 'customer_name', 'line_code', 'status', 'offers', 'best_premium_minor', 'created_at', 'expires_at', 'assisted', 'payment_status'], 'agent quote row');
    expect($row['customer_id'])->toBe($cid)->and($row['offers'])->toBeGreaterThan(0);

    // AGT-026 send
    $sent = $this->postJson("/api/v1/quotes/{$quoteId}/send", ['channel' => 'LINK'], r3H($t))->assertStatus(201)->json('data');
    r3Keys($sent, ['link_path', 'expires_at'], 'quote send');

    // ---- proposal (review page POST /proposals), AGT-031/030/033 ---------------------------------------------------
    $this->postJson("/api/v1/quotes/{$quoteId}/offers/{$offer->id}/accept", [], r3H($t))->assertSuccessful();
    $pid = $this->postJson('/api/v1/proposals', ['quote_offer_id' => $offer->id, 'party_id' => $client['party_id']], r3H($t))->assertStatus(201)->json('data.id');
    $p = $this->getJson("/api/v1/proposals/{$pid}", r3H($t))->assertOk()->json('data');
    r3Keys($p, ['proposal_number', 'status', 'blueprint_state', 'created_at', 'offer', 'underwriting_case', 'available_transitions', 'policy_id', 'quote_offer_id'], 'proposal');
    expect($p['offer']['quote_id'])->toBe($quoteId)->and($p['offer']['total_minor'])->toBe(150000);
    $ck = $this->getJson("/api/v1/proposals/{$pid}/checklist", r3H($t))->assertOk()->json('data');
    r3Keys($ck, ['status', 'questions', 'answers', 'required_documents', 'declarations', 'attested_at', 'blocking', 'information_request'], 'proposal checklist');
    $prow = collect($this->getJson('/api/v1/mobile/partner/agent/proposals', r3H($t))->assertOk()->json('data'))->firstWhere('id', $pid);
    r3Keys($prow, ['id', 'customer_id', 'customer_name', 'proposal_number', 'line_code', 'status', 'carrier_name', 'total_minor', 'created_at', 'submitted_at', 'decided_at'], 'agent proposal row');
    expect($prow['customer_id'])->toBe($cid);

    // AGT-030: the documents page stores the file as the client's document, then attaches it to the requirement.
    $pdoc = $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/documents", ['category' => 'PROPOSAL', 'mime_type' => 'image/png', 'file_base64' => base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\1", 64))], r3H($t))->assertStatus(201)->json('data');
    $req = collect($ck['required_documents'])->firstWhere('satisfied_by', 'UPLOAD')['code'];
    foreach ($ck['required_documents'] as $d) {
        r3Keys($d, ['code', 'mandatory', 'status'], 'proposal required document');
    }
    $this->postJson("/api/v1/proposals/{$pid}/documents", ['document_id' => $pdoc['id'], 'requirement_code' => $req], r3H($t))->assertStatus(201);
    \App\Models\Document::whereKey($pdoc['id'])->update(['scan_status' => 'CLEAN']);
    // The shared builder (/account/quotes/{id}/review) answers and attests; AGT-031 then submits.
    $this->putJson("/api/v1/proposals/{$pid}/disclosures", ['answers' => ['prior_claims' => false]], r3H($t))->assertOk();
    $this->postJson("/api/v1/proposals/{$pid}/disclosures/attest", [], r3H($t))->assertOk();
    $ck2 = $this->getJson("/api/v1/proposals/{$pid}/checklist", r3H($t))->assertOk()->json('data');
    expect($ck2['blocking'])->toBe([])->and($ck2['available_transitions'])->toContain('submit'); // the review page shows Submit only then
    $this->postJson("/api/v1/proposals/{$pid}/submit", [], r3H($t))->assertSuccessful();

    // AGT-033: the underwriter asked for information; the agent answers from the information page.
    Proposal::whereKey($pid)->update(['status' => 'INFORMATION_REQUIRED', 'information_request' => ['id' => (string) Str::uuid(), 'items' => [['description' => 'Copy of the driving licence']], 'message' => 'Please send the licence', 'requested_at' => now()->toIso8601String(), 'responded_at' => null]]);
    $ir = $this->getJson("/api/v1/proposals/{$pid}/checklist", r3H($t))->assertOk()->json('data.information_request');
    r3Keys($ir, ['items', 'message', 'requested_at', 'responded_at'], 'information request');
    $this->postJson("/api/v1/proposals/{$pid}/resubmit", ['response' => 'Licence attached to the proposal documents.'], r3H($t))->assertSuccessful();
    expect(Proposal::find($pid)->status)->not->toBe('INFORMATION_REQUIRED');

    // ---- /account/book/payments: payment request -------------------------------------------------------------------
    $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", [], r3H($t))->assertOk()->assertJsonPath('data.payment_status', 'CUSTOMER_PROMPTED');

    // ---- policy issued → /account/book/policies/{id} -------------------------------------------------------------
    Proposal::whereKey($pid)->update(['status' => 'APPROVED']);
    $policy = makeMobileTestPolicy(Proposal::findOrFail($pid), $t, $src->carrier_id, $client['party_id'], ['policy_number' => 'POL-R3-0001', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear()]);
    $pol = $this->getJson("/api/v1/mobile/partner/agent/policies/{$policy->id}", r3H($t))->assertOk()->json('data');
    r3Keys($pol, ['id', 'policy_number', 'status', 'customer_id', 'customer_name', 'carrier_name', 'line_code'], 'policy detail');
    $polRow = collect($this->getJson('/api/v1/mobile/partner/agent/policies', r3H($t))->assertOk()->json('data'))->firstWhere('id', $policy->id);
    r3Keys($polRow, ['id', 'policy_number', 'status', 'customer_id', 'customer_name', 'carrier_id', 'carrier_name', 'line_code', 'issued_at'], 'agent policy row');

    // AGT-042 endorsement request
    $sr = $this->postJson("/api/v1/mobile/partner/agent/policies/{$policy->id}/service-requests", ['type' => 'ENDORSEMENT', 'reason' => 'Add a second driver'], r3H($t))->assertStatus(201)->json('data');
    r3Keys($sr, ['id', 'transaction_number', 'status'], 'service request');

    // AGT-046 cancellation preview (the page previews, it does not need to file)
    \Illuminate\Support\Facades\DB::table('cancellation_rule_versions')->insert(['id' => (string) Str::uuid(), 'line_code' => 'AUTO', 'version' => 1, 'status' => 'APPROVED', 'basis' => 'PRO_RATA',
        'short_rate_basis_points' => 10000, 'admin_fee_minor' => 0, 'effective_from' => now()->subYear()->toDateString(), 'effective_until' => null, 'created_by' => $a['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    $pv = $this->postJson("/api/v1/mobile/partner/agent/policies/{$policy->id}/cancellations/preview", ['effective_at' => now()->addDay()->toDateString(), 'initiated_by' => 'INSURED'], r3H($t))->assertOk()->json('data');
    r3Keys($pv, ['refund_minor', 'basis'], 'cancellation preview');

    // ---- /account/customers/{id}/claim: assisted FNOL --------------------------------------------------------------
    $claim = $this->postJson('/api/v1/mobile/partner/agent/claims', ['policy_id' => $policy->id, 'claimant_party_id' => $client['party_id'], 'loss_occurred_at' => now()->subDay()->toIso8601String(),
        'loss_details' => ['description' => 'Rear-ended at a junction'], 'loss_location' => 'Douala', 'estimated_loss_minor' => 25000000, 'idempotency_key' => (string) Str::uuid()], r3H($t))->assertStatus(201)->json('data');
    r3Keys($claim, ['id', 'claim_number'], 'assisted claim');
    $this->getJson("/api/v1/mobile/partner/agent/claims/{$claim['id']}", r3H($t))->assertOk();

    // ---- /account/stickers: assign a sticker from the agent's own stock -------------------------------------------
    StickerStock::create(['serial_number' => 'STK-R3-0001', 'carrier_id' => $src->carrier_id, 'batch_number' => 'B-R3', 'status' => 'IN_STOCK', 'custodian_tenant_id' => $t->id, 'custody_level' => 'AGENT', 'custodian_user_id' => $a['user']->id]);
    $stock = $this->getJson('/api/v1/mobile/partner/agent/stickers', r3H($t))->assertOk()->json('data.stock');
    r3Keys($stock[0], ['serial_number', 'carrier_id', 'carrier_name', 'batch_number', 'status'], 'sticker stock');
    $this->postJson("/api/v1/mobile/partner/agent/policies/{$policy->id}/sticker", ['serial_number' => 'STK-R3-0001'], r3H($t))->assertStatus(201)->assertJsonPath('data.serial_number', 'STK-R3-0001');

    // ---- /account/commissions -------------------------------------------------------------------------------------
    $acc = makeMobileTestCommissionAccrual($t, $a['partner'], $policy);
    makeMobileTestCommissionAccrual($t, $b['partner'], $policy);
    $com = $this->getJson('/api/v1/mobile/agent/commissions', r3H($t))->assertOk()->json('data');
    expect(collect($com)->pluck('id')->all())->toBe([$acc->id]);
    r3Keys($com[0], ['status', 'amount_minor'], 'commission row');
    $this->getJson('/api/v1/mobile/agent/withdrawals', r3H($t))->assertOk();
    expect(collect($this->getJson('/api/v1/mobile/agent/dashboard', r3H($t))->assertOk()->json('data.metrics'))->pluck('label')->all())->toContain('Clients'); // /account/agent reads m('Clients')

    // ================= another agent of the same tenant: every step refused =====================================
    StickerStock::create(['serial_number' => 'STK-R3-0002', 'carrier_id' => $src->carrier_id, 'batch_number' => 'B-R3', 'status' => 'IN_STOCK', 'custodian_tenant_id' => $t->id, 'custody_level' => 'AGENT', 'custodian_user_id' => $b['user']->id]);
    Passport::actingAs($b['user']);
    $H = fn () => r3H($t);
    $this->patchJson("/api/v1/mobile/partner/agent/leads/{$lead['id']}", ['status' => 'LOST'], $H())->assertNotFound();
    $this->postJson("/api/v1/mobile/partner/agent/leads/{$lead['id']}/convert", ['consent_confirmed' => true], $H())->assertNotFound();
    $this->postJson("/api/v1/mobile/partner/agent/leads/{$lead['id']}/activities", ['entry_type' => 'NOTE', 'body' => 'x'], $H())->assertNotFound();
    $this->getJson("/api/v1/mobile/agent/clients/{$cid}", $H())->assertForbidden();
    $this->getJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc", $H())->assertForbidden();
    $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/documents", ['category' => 'KYC', 'mime_type' => 'image/png', 'file_base64' => $png], $H())->assertForbidden();
    $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc/submit", [], $H())->assertForbidden();
    $this->getJson("/api/v1/quotes/{$quoteId}", $H())->assertForbidden();
    $this->postJson("/api/v1/quotes/{$quoteId}/send", ['channel' => 'LINK'], $H())->assertForbidden();
    $this->postJson("/api/v1/quotes/{$quoteId}/decline", ['reason_code' => 'NO_RESPONSE'], $H())->assertForbidden();
    $this->postJson('/api/v1/proposals', ['quote_offer_id' => $offer->id, 'party_id' => $client['party_id']], $H())->assertForbidden();
    $this->getJson("/api/v1/proposals/{$pid}", $H())->assertForbidden();
    $this->getJson("/api/v1/proposals/{$pid}/checklist", $H())->assertForbidden();
    $this->postJson("/api/v1/proposals/{$pid}/documents", ['document_id' => $pdoc['id'], 'requirement_code' => $req], $H())->assertForbidden();
    $this->postJson("/api/v1/proposals/{$pid}/resubmit", ['response' => 'x'], $H())->assertForbidden();
    $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", [], $H())->assertNotFound();
    $this->getJson("/api/v1/mobile/partner/agent/policies/{$policy->id}", $H())->assertNotFound();
    $this->postJson("/api/v1/mobile/partner/agent/policies/{$policy->id}/service-requests", ['type' => 'ENDORSEMENT', 'reason' => 'Add a second driver'], $H())->assertNotFound();
    $this->getJson("/api/v1/mobile/partner/agent/service-requests/{$sr['id']}", $H())->assertNotFound();
    $this->postJson("/api/v1/mobile/partner/agent/policies/{$policy->id}/cancellations/preview", ['effective_at' => now()->addDay()->toDateString(), 'initiated_by' => 'INSURED'], $H())->assertNotFound();
    $this->postJson('/api/v1/mobile/partner/agent/claims', ['policy_id' => $policy->id, 'claimant_party_id' => $client['party_id'], 'loss_occurred_at' => now()->subDay()->toIso8601String(),
        'loss_details' => ['description' => 'Rear-ended at a junction'], 'idempotency_key' => (string) Str::uuid()], $H())
        ->assertStatus(422)->assertJsonValidationErrors("claimant_party_id"); // "This customer is not in your book."
    $this->getJson("/api/v1/mobile/partner/agent/claims/{$claim['id']}", $H())->assertNotFound();
    $this->postJson("/api/v1/mobile/partner/agent/policies/{$policy->id}/sticker", ['serial_number' => 'STK-R3-0002'], $H())->assertNotFound();
    expect(collect($this->getJson('/api/v1/mobile/agent/commissions', $H())->assertOk()->json('data'))->pluck('id')->all())->not->toContain($acc->id);
    foreach (['quotes', 'proposals', 'policies', 'claims', 'leads'] as $list) {
        expect(collect($this->getJson("/api/v1/mobile/partner/agent/{$list}", $H())->assertOk()->json('data'))->pluck('id')->all())->toBe([], $list);
    }
});

it('wires the agent pages to what the API actually returns', function () {
    $u = 'ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01';
    // Proposal-form requirements (satisfied_by PROPOSAL_FORM) are produced by the proposal, not uploaded: POST /documents refuses them.
    $this->get("/account/agent/proposals/{$u}/documents")->assertOk()->assertSee("d.satisfied_by !== 'UPLOAD'", false)->assertSee('Produced from the proposal form');
    $this->get("/account/agent/proposals/{$u}/documents?lang=fr")->assertOk()->assertSee('Produit à partir du formulaire de proposition');
    // Motor policies carry line_code AUTO (StickerCustodyService::MOTOR_LINES), not only MOTOR.
    $this->get('/account/stickers')->assertOk()->assertSee("['AUTO', 'AUTOMOBILE', 'MOTOR']", false);
    // Links go to the servicing pages that exist, not to the book list.
    $this->get("/account/agent/proposals/{$u}")->assertOk()->assertSee("'/account/book/policies/' + encodeURIComponent(p.policy_id)", false)->assertSee("'/account/book/payments'", false);
    $this->get("/account/customers/{$u}/activities")->assertOk()->assertSee("'/account/book/claims/' + encodeURIComponent(x.id)", false);
});
