import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { canAddWitness, witnessPayload } from "../src/lib/claimParties.ts";
import { scanFacts, scanPrefill } from "../src/lib/assetScan.ts";
import { canDecideSettlement, releasedSettlement, settlementSteps } from "../src/lib/settlement.ts";
import {
  claimActionAllowed,
  claimStage,
  claimStatusKey,
  claimTone,
  claimTracker,
  latestEvent,
  normalizeClaimStatus,
} from "../src/lib/claimStatus.ts";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

test("witness body matches MobileClaimPartyController (display_name, contact_phone, consent_given)", () => {
  assert.deepEqual(witnessPayload(" Paul Mbarga ", " +237670001122 ", true), {
    role: "WITNESS",
    display_name: "Paul Mbarga",
    contact_phone: "+237670001122",
    consent_given: true,
  });
  assert.deepEqual(witnessPayload("Paul Mbarga", "", true), { role: "WITNESS", display_name: "Paul Mbarga", contact_phone: null, consent_given: false });
  // A phone for someone else needs their consent; a name alone does not.
  assert.equal(canAddWitness("Paul Mbarga", "+237670001122", false), false);
  assert.equal(canAddWitness("Paul Mbarga", "+237670001122", true), true);
  assert.equal(canAddWitness("Paul Mbarga", "", false), true);
  assert.equal(canAddWitness("Pa", "", false), false);
});

test("parties list is unwrapped from the paginator and the screen reads display_name", () => {
  const client = read("src/api/client.ts");
  assert.match(client, /apiPage<ClaimParty>\(`\/mobile\/claims\/\$\{id\}\/parties`, page\)/);
  const screen = read("app/claim/[id]/parties.tsx");
  assert.match(screen, /p\.display_name/);
  assert.doesNotMatch(screen, /full_name|phone_e164/);
});

test("asset scan prefills from facts and OCR and confirms clean facts", () => {
  const asset = {
    id: "a1",
    type: "VEHICLE",
    status: "ACTIVE",
    version: 3,
    external_reference: "LT 123 AB",
    facts: { make: "Toyota", year: 2016 },
    documents: [{ id: "d1", ocr_data: { status: "EXTRACTED", fields: { model: "Corolla" } } }],
  };
  assert.deepEqual(scanPrefill(asset, "d1"), { registration_number: "LT 123 AB", make: "Toyota", model: "Corolla", year: "2016" });
  assert.deepEqual(scanFacts({ registration_number: " LT 123 AB ", make: "", model: "Corolla", year: "2016" }), {
    registration_number: "LT 123 AB",
    model: "Corolla",
    year: 2016,
  });
  assert.deepEqual(scanFacts({ year: "abc" }), {});
  const client = read("src/api/client.ts");
  assert.match(client, /JSON\.stringify\(\{ document_id: documentId, purpose \}\)/);
  assert.match(client, /JSON\.stringify\(\{ version, facts \}\)/);
  assert.doesNotMatch(read("app/assets/[id]/scan.tsx"), /as any/);
});

test("settlement: nothing before an offer, Accept/Reject only while OFFERED", () => {
  assert.equal(releasedSettlement({ id: null, status: "PENDING" }), null);
  assert.equal(releasedSettlement(null), null);
  const offered = { id: "s1", claim_id: "c1", status: "OFFERED", currency: "XAF", offered_minor: 300000, deductible_minor: 20000, net_minor: 280000, can_decide: true };
  assert.equal(releasedSettlement(offered), offered);
  assert.equal(canDecideSettlement(offered), true);
  for (const status of ["ACCEPTED", "DISPUTED", "DISCHARGE_SIGNED", "PAYMENT_PENDING", "PAID"]) {
    assert.equal(canDecideSettlement({ status, can_decide: false }), false, status);
  }
  assert.deepEqual(settlementSteps({ status: "OFFERED" }).map((s) => s.done), [true, false, false, false]);
  assert.deepEqual(settlementSteps({ status: "ACCEPTED" }).map((s) => s.done), [true, true, false, false]);
  assert.deepEqual(settlementSteps({ status: "PAYMENT_PENDING" }).map((s) => s.done), [true, true, true, false]);
  assert.deepEqual(settlementSteps({ status: "PAID" }).map((s) => s.done), [true, true, true, true]);
});

test("claim statuses cover INVESTIGATING and REOPENED from ClaimMachine::BLUEPRINT", () => {
  assert.equal(normalizeClaimStatus("INVESTIGATING"), "INVESTIGATING");
  assert.equal(normalizeClaimStatus("REOPENED"), "REOPENED");
  assert.equal(normalizeClaimStatus("UNDER_ASSESSMENT"), "ASSESSMENT");
  assert.equal(normalizeClaimStatus("APPEALED"), "DISPUTED");
  assert.equal(claimStatusKey("INVESTIGATING"), "claimStatus_INVESTIGATING");
  assert.equal(claimStatusKey("REOPENED"), "claimStatus_REOPENED");
  assert.equal(claimStage("INVESTIGATING"), 2);
  assert.equal(claimStage("REOPENED"), 1);
  assert.equal(claimTracker("INVESTIGATING")[3], "current");
  assert.equal(claimTracker("REOPENED")[2], "current");
  assert.equal(claimTone("REOPENED"), "warning");
  for (const status of ["INVESTIGATING", "REOPENED"]) {
    assert.equal(claimActionAllowed("evidence", status), true, `${status} evidence`);
    assert.equal(claimActionAllowed("message", status), true, `${status} message`);
  }
});

test("withdraw is offered only for ClaimMachine::WITHDRAWABLE statuses", () => {
  for (const status of ["SUBMITTED", "ACKNOWLEDGED", "EVIDENCE_PENDING"]) assert.equal(claimActionAllowed("withdraw", status), true, status);
  for (const status of ["ASSESSMENT", "APPROVED", "PAID", "CLOSED"]) assert.equal(claimActionAllowed("withdraw", status), false, status);
  assert.match(read("src/api/client.ts"), /`\/mobile\/claims\/\$\{id\}\/withdraw`/);
});

test("the claim status date is the latest timeline event, not the first", () => {
  const events = [
    { to_status: "SUBMITTED", occurred_at: "2026-09-01T10:00:00+01:00" },
    { to_status: "ACKNOWLEDGED", occurred_at: "2026-09-03T09:00:00+01:00" },
    { to_status: "ASSESSMENT", occurred_at: "2026-09-10T08:30:00+01:00" },
  ];
  assert.equal(latestEvent(events)?.to_status, "ASSESSMENT");
  assert.equal(latestEvent([...events].reverse())?.to_status, "ASSESSMENT");
  assert.equal(latestEvent([]), undefined);
  assert.doesNotMatch(read("app/claim/[id].tsx"), /timeline\.data \?\? \[\]\)\[0\]/);
});

test("services use the svcType_ keys that exist; support escalates the same case", () => {
  for (const file of ["app/services/index.tsx", "app/services/[id].tsx"]) {
    const src = read(file);
    assert.doesNotMatch(src, /serviceType_/, file);
    assert.match(src, /svcType_/, file);
  }
  const support = read("app/support/[id].tsx");
  assert.match(support, /SupportApi\.escalate\(id\)/);
  assert.doesNotMatch(support, /createSupportCase/);
  assert.match(read("src/api/client.ts"), /`\/mobile\/support\/cases\/\$\{id\}\/escalate`/);
});

test("delivery screen tolerates a missing timeline and the address change sends {address, version}", () => {
  assert.match(read("app/delivery/[id].tsx"), /\(d\.timeline \?\? \[\]\)/);
  assert.match(read("src/api/client.ts"), /JSON\.stringify\(version \? \{ address, version \} : \{ address \}\)/);
});

test("every key added for these screens exists in EN and FR", async () => {
  const { en } = await import("../src/i18n/en.ts");
  const { fr } = await import("../src/i18n/fr.ts");
  const keys = [
    "partiesConsent", "partyRole_DRIVER", "partyRole_PASSENGER", "partyRole_THIRD_PARTY", "partyRole_WITNESS", "partyRole_OTHER",
    "deliveryStatus_CREATED", "deliveryStatus_IN_TRANSIT", "deliveryStatus_DELIVERED", "deliveryStatus_CANCELLED",
    "settlementStatus_OFFERED", "settlementStatus_DISPUTED", "settleAwaitingAnswer",
    "claimStatus_INVESTIGATING", "claimStatus_REOPENED", "claimStatusMsg_INVESTIGATING", "claimStatusMsg_REOPENED",
    "claimWithdraw", "claimWithdrawConfirm", "priority_URGENT", "status_NOT_SCHEDULED", "status_RESCHEDULED", "status_NOT_STARTED",
    "status_PENDING_DECISION", "attachmentStatus_RECEIVED", "evidence_DAMAGE_PHOTO", "evidence_DRIVER_LICENCE",
    "evidence_THIRD_PARTY_DETAILS", "evidence_PROOF_OF_OWNERSHIP", "evidence_TRAVEL_DOCUMENTS", "evidence_RECEIPTS", "evidence_INVOICES",
    "inspNotScheduledBody", "tomorrow", "supportEscalated", "emViewRequest",
  ];
  for (const key of keys) {
    assert.ok(en[key], `en ${key}`);
    assert.ok(fr[key], `fr ${key}`);
  }
});
