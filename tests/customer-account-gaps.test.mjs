import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { chunkRanges, VIDEO_CHUNK_BYTES, videoMime, withDraftEvidence, withoutDraftEvidence } from "../src/lib/evidenceUpload.ts";
import { clientStateOf, draftMissing, formState, incidentDraftInput, resumeStep, wizardRoute } from "../src/lib/claimDraft.ts";
import { isMomoPhone, normalizeMomoPhone } from "../src/lib/momoPhone.ts";
import { syncKindKey, syncPendingKey, syncResourceKey } from "../src/lib/syncLabels.ts";
import { passwordChangeProblem } from "../src/lib/passwordChange.ts";
import { profileCompletion } from "../src/lib/profileCompletion.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const en = read("src/i18n/en.ts");
const fr = read("src/i18n/fr.ts");

// --- video evidence ---------------------------------------------------------------

test("videos keep their real type: .mov is video/quicktime", () => {
  assert.equal(videoMime("video/quicktime"), "video/quicktime");
  assert.equal(videoMime(null, "IMG_0001.MOV"), "video/quicktime");
  assert.equal(videoMime("", null, "file:///cache/clip.mov?x=1"), "video/quicktime");
  assert.equal(videoMime("video/mp4", "a.mov"), "video/mp4");
  assert.equal(videoMime(null, "clip.mp4"), "video/mp4");
  assert.equal(videoMime(null), "video/mp4");
});

test("videos are read and sent in bounded byte ranges", () => {
  assert.deepEqual(chunkRanges(0), [[0, 0]]);
  assert.deepEqual(chunkRanges(10, 4), [[0, 4], [4, 8], [8, 10]]);
  const big = chunkRanges(50 * 1024 * 1024);
  assert.equal(big.length, Math.ceil((50 * 1024 * 1024) / VIDEO_CHUNK_BYTES));
  assert.ok(big.every(([s, e]) => e - s <= VIDEO_CHUNK_BYTES));
  // Base64 of one chunk stays far below the server's 10 MiB chunk cap.
  assert.ok((VIDEO_CHUNK_BYTES * 4) / 3 < 10 * 1024 * 1024);
  const upload = read("src/api/customer.ts");
  assert.match(upload, /readAsStringAsync\(uri, \{ encoding: FileSystem\.EncodingType\.Base64, position: start, length: end - start \}\)/);
  assert.doesNotMatch(upload, /mime_type: "video\/mp4"/, "never forces video/mp4");
  assert.match(upload, /mime_type,\n|mime_type,\r\n/);
});

test("draft evidence list: add replaces the same file, remove drops it", () => {
  const a = { document_id: "d1", evidence_type: "PHOTO" };
  const b = { upload_session_id: "u1", evidence_type: "VIDEO" };
  let list = withDraftEvidence([], a);
  list = withDraftEvidence(list, b);
  list = withDraftEvidence(list, { ...a, name: "again" });
  assert.equal(list.length, 2);
  assert.equal(list.find((x) => x.document_id === "d1").name, "again");
  assert.deepEqual(withoutDraftEvidence(list, "u1").map((x) => x.document_id), ["d1"]);
});

// --- claim wizard on drafts --------------------------------------------------------

test("incident step saves typed FNOL fields, raw answers and the device position on the draft", () => {
  const input = incidentDraftInput(
    { policy_id: "p1", incident_at: "2026-09-29T10:00:00+01:00", description: "Rear-ended at a light.", incident_type: "COLLISION", injuries_reported: "false", estimated_loss_minor: "150000", unknown: "x", incident_location: "" },
    { incident_type: "COLLISION", city: "Douala", empty: "" },
    { latitude: 4.05, longitude: 9.7 },
  );
  assert.equal(input.policy_id, "p1");
  assert.equal(input.injuries_reported, false);
  assert.equal(input.estimated_loss_minor, 150000);
  assert.equal(input.unknown, undefined);
  assert.equal(input.incident_location, undefined);
  assert.equal(input.latitude, 4.05);
  assert.deepEqual(input.client_state, { form: { incident_type: "COLLISION", city: "Douala" }, step: "evidence" });
  assert.equal(Object.keys(formState(Object.fromEntries(Array.from({ length: 60 }, (_, i) => [`k${i}`, "v"])))).length, 40);
});

test("a draft is submittable only with policy, incident date and a 10+ character description; resume opens the right step", () => {
  assert.equal(draftMissing({ policy_id: null }), "policy");
  assert.equal(draftMissing({ policy_id: "p", payload: { incident_at: "x", description: "short" } }), "incident");
  const ready = { id: "d", policy_id: "p", payload: { incident_at: "x", description: "Long enough text", client_state: { step: "review" } } };
  assert.equal(draftMissing(ready), null);
  assert.equal(resumeStep(ready), "review");
  assert.equal(resumeStep({ ...ready, payload: { ...ready.payload, client_state: {} } }), "evidence");
  assert.equal(resumeStep({ policy_id: "p", payload: { client_state: { step: "review" } } }), "incident");
  assert.deepEqual(clientStateOf({ payload: { client_state: { step: "bogus", form: { a: "1" } } } }), { form: { a: "1" } });
  assert.deepEqual(wizardRoute("evidence", "d", "p", true), { pathname: "/claim/new/evidence", params: { draftId: "d", policyId: "p", from: "review" } });
});

test("the wizard files the claim only at step 4, through the draft, and edits never create a second claim", () => {
  const incident = read("app/claim/new/incident.tsx");
  const review = read("app/claim/new/review.tsx");
  const choose = read("app/claim/new.tsx");
  const evidence = read("app/claim/new/evidence.tsx");
  for (const src of [incident, review, choose, evidence]) assert.doesNotMatch(src, /ClaimsApi\.create\(/);
  assert.match(incident, /updateClaimDraft|createClaimDraft/);
  assert.match(choose, /updateClaimDraft\(editing/);
  assert.match(evidence, /uploadEvidenceFile/);
  assert.doesNotMatch(evidence, /attachClaimEvidence|uploadClaimEvidence/);
  assert.match(review, /submitClaimDraft\(id\)/);
  assert.doesNotMatch(review, /submitDeclaration/);
  assert.match(review, /if \(!agreed \|\| busy \|\| missing\) return;/);
  assert.match(read("src/api/customer.ts"), /drafts\/\$\{id\}\/submit`[\s\S]{0,80}declaration_confirmed: true/);
  // Save draft goes back to My claims, where drafts can be resumed or discarded.
  const claims = read("app/(customer)/(tabs)/claims.tsx");
  assert.match(claims, /ClaimDraftCard/);
  assert.match(claims, /resumeStep\(d\)/);
});

test("claims list pages beyond the first page", () => {
  const claims = read("app/(customer)/(tabs)/claims.tsx");
  assert.match(claims, /usePagedList<Claim>\(\(page\) => CustomerApi\.claimsPage\(page\)\)/);
  assert.match(claims, /onEndReached/);
  assert.match(claims, /<LoadMore/);
  assert.match(claims, /loadAll\(\)/);
});

test("policy detail and application lookups use server filters, not the first pages", () => {
  const detail = read("src/components/policies/PolicyDetailView.tsx");
  assert.match(detail, /CustomerApi\.policyPayments\(policy\.id, page\)/);
  assert.match(detail, /CustomerApi\.claimsPage\(page, policyId\)/);
  assert.match(read("src/api/customer.ts"), /\/mobile\/payments\?policy_id=/);
  const lookup = read("src/api/proposalLookup.ts");
  assert.match(lookup, /\/mobile\/proposals\?quote_offer_id=/);
  assert.match(read("src/hooks/useQuoteFlow.ts"), /findApplicationForOffer\(acceptedId\)/);
  assert.match(read("src/store/insurance.ts"), /findApplicationForOffer\(selectedOffer\.id\)/);
  assert.doesNotMatch(read("src/store/insurance.ts"), /ProposalsApi\.list\(1\)/);
  // Proposal rows bring their checklist: no per-row checklist call when required_documents is present.
  assert.match(read("app/proposals/index.tsx"), /!Array\.isArray\(p\.required_documents\)/);
});

// --- leftovers ----------------------------------------------------------------------

test("Mobile Money number: local 6XXXXXXXX is accepted and sent as +237", () => {
  assert.equal(normalizeMomoPhone("670000000"), "+237670000000");
  assert.equal(normalizeMomoPhone("6 70 00 00 00"), "+237670000000");
  assert.equal(normalizeMomoPhone("237670000000"), "+237670000000");
  assert.equal(normalizeMomoPhone("00237670000000"), "+237670000000");
  assert.equal(normalizeMomoPhone("+237 670-000-000"), "+237670000000");
  assert.equal(isMomoPhone("670000000"), true);
  assert.equal(isMomoPhone("67000000"), false);
  assert.equal(isMomoPhone("+33612345678"), false);
  const checkout = read("app/checkout.tsx");
  assert.match(checkout, /request\(provider, payerPhone\)/);
  assert.match(checkout, /if \(!phoneEdited && defaultPhone\) setPhone\(defaultPhone\)/);
});

test("sync centre labels are catalogue keys (EN + FR), with a pluralised count", () => {
  assert.equal(syncResourceKey("Claim incident draft"), "syncResource_claim_incident_draft");
  assert.equal(syncResourceKey("Enregistrement client consenti par l'agent"), "syncResource_enregistrement_client_consenti_par_l_agent");
  assert.equal(syncKindKey("draft"), "syncKind_DRAFT");
  assert.equal(syncPendingKey(1), "syncPendingOne");
  assert.equal(syncPendingKey(3), "syncPendingMany");
  for (const k of ["syncResource_claim_incident_draft", "syncKind_DRAFT", "syncKind_MUTATION", "syncKind_UPLOAD", "syncPendingOne", "syncPendingMany", "syncErrorGeneric"]) {
    assert.match(en, new RegExp(`\\n  ${k}:`), k);
    assert.match(fr, new RegExp(`\\n  ${k}:`), k);
  }
  const sync = read("app/sync/index.tsx");
  assert.doesNotMatch(sync, /\{item\.resource\}|\{item\.kind\}|\{item\.error_code\}/);
});

test("information request: comment needs 10+ characters and an empty submit is disabled", () => {
  const info = read("app/claim/[id]/information.tsx");
  assert.match(info, /const MIN_COMMENT = 10;/);
  assert.match(info, /disabled=\{!!uploading \|\| !commentOk\}/);
  assert.doesNotMatch(info, /if \(comment\.trim\(\)\)\s*await CustomerApi\.createSupportCase/);
});

test("terms are bundled in EN and FR with a draft-pending-review notice", () => {
  const terms = read("app/terms.tsx");
  assert.doesNotMatch(terms, /OpesInsure is a digital insurance marketplace/);
  assert.match(terms, /termsDraftNotice/);
  for (let i = 1; i <= 7; i++) for (const part of ["Title", "Body"]) {
    assert.match(en, new RegExp(`\\n  termsS${i}${part}:`));
    assert.match(fr, new RegExp(`\\n  termsS${i}${part}:`));
  }
  assert.match(fr, /termsS6Body: ".*loi n° 2024\/017/);
});

// --- account -----------------------------------------------------------------------

test("password change rules mirror the server", () => {
  assert.deepEqual(passwordChangeProblem("", "newpassword", "newpassword"), { field: "current", copy: "requiredToContinue" });
  assert.equal(passwordChangeProblem("old", "short", "short").copy, "passwordMin");
  assert.equal(passwordChangeProblem("samesame1", "samesame1", "samesame1").copy, "pwSameAsCurrent");
  assert.equal(passwordChangeProblem("old", "newpassword", "newpasswordx").copy, "passwordMismatch");
  assert.equal(passwordChangeProblem("old", "x".repeat(129), "x".repeat(129)).copy, "pwTooLong");
  assert.equal(passwordChangeProblem("old", "newpassword", "newpassword"), null);
  assert.match(read("src/api/customer.ts"), /"\/me\/password", \{\s*method: "PUT"/);
  assert.match(read("app/account/security.tsx"), /\/account\/change-password/);
});

test("phone verification is reachable and completes the phone step", () => {
  const verify = read("app/account/verify-phone.tsx");
  assert.match(verify, /requestPhoneVerification\(channel\)/);
  assert.match(verify, /confirmPhoneVerification\(challengeId, code\)/);
  assert.match(verify, /await hydrate\(\)/);
  assert.match(read("app/account/profile.tsx"), /\/account\/verify-phone/);
  const profile = { date_of_birth: "1990-01-01", address_line1: "Rue 1", city: "Douala" };
  const kyc = { identifiers: [], submission: { status: "APPROVED", expires_at: "2099-01-01", requirements: [], documents: [{}] } };
  const before = profileCompletion({ user: { phone_e164: "+237670000000", phone_verified_at: null, email: null }, profile, kyc });
  const phone = before.steps.find((s) => s.key === "phone");
  assert.equal(phone.state, "todo");
  assert.equal(phone.href, "/account/verify-phone");
  // Truly complete: verified phone + approved verification reaches 100 % (an approved case proves the ID).
  const after = profileCompletion({ user: { phone_e164: "+237670000000", phone_verified: true, email: null }, profile, kyc });
  assert.equal(after.percent, 100);
  assert.equal(after.complete, true);
});
