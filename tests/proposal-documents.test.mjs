import test from "node:test";
import assert from "node:assert/strict";
import { base64ToBytes, bytesToHex, kycAutoAttachments, normalizeUploadMime } from "../src/lib/proposalDocuments.ts";

const NOW = Date.parse("2026-09-28T10:00:00Z");

test("picker types map onto what the server accepts", () => {
  assert.equal(normalizeUploadMime("image/jpg"), "image/jpeg");
  assert.equal(normalizeUploadMime("image/JPEG"), "image/jpeg");
  assert.equal(normalizeUploadMime("image/png"), "image/png");
  assert.equal(normalizeUploadMime("application/pdf"), "application/pdf");
  assert.equal(normalizeUploadMime(null, "carte-grise.PDF"), "application/pdf");
  assert.equal(normalizeUploadMime("application/octet-stream", "scan.jpeg"), "image/jpeg");
  assert.equal(normalizeUploadMime("image/heic", "IMG_1.heic"), null);
  assert.equal(normalizeUploadMime("image/webp"), null);
  assert.equal(normalizeUploadMime(null, "letter.docx"), null);
});

test("base64 decodes to the original bytes", () => {
  assert.equal(bytesToHex(base64ToBytes("SGVsbG8=")), Buffer.from("Hello").toString("hex"));
  assert.equal(bytesToHex(base64ToBytes("/9j/4AAQ")), Buffer.from("/9j/4AAQ", "base64").toString("hex"));
  const big = Buffer.from(Array.from({ length: 1000 }, (_, i) => i % 256));
  assert.equal(bytesToHex(base64ToBytes(big.toString("base64"))), big.toString("hex"));
});

const approvedKyc = (overrides = {}) => ({
  submission: {
    status: "APPROVED",
    expires_at: "2027-09-01",
    requirements: [{ requirement_code: "NATIONAL_ID", mandatory: true, satisfied: true, accepted_canonical_codes: ["NATIONAL_ID_CARD", "PASSPORT"] }],
    documents: [
      { id: "front", purpose: "ID_FRONT" },
      { id: "back", purpose: "ID_BACK" },
      { id: "addr", purpose: "PROOF_OF_ADDRESS" },
    ],
    ...overrides,
  },
});

test("a verified ID is linked to a missing identity requirement (both sides)", () => {
  const links = kycAutoAttachments([{ code: "NATIONAL_ID_CARD", status: "MISSING", satisfied_by: "UPLOAD" }, { code: "VEHICLE_REGISTRATION_CARD", status: "MISSING" }], approvedKyc(), NOW);
  assert.deepEqual(links, [
    { requirement_code: "NATIONAL_ID_CARD", document_id: "front" },
    { requirement_code: "NATIONAL_ID_CARD", document_id: "back" },
  ]);
});

test("nothing is linked unless verification is approved and the requirement still missing", () => {
  const reqs = [{ code: "NATIONAL_ID_CARD", status: "MISSING" }];
  assert.deepEqual(kycAutoAttachments(reqs, approvedKyc({ status: "SUBMITTED" }), NOW), []);
  assert.deepEqual(kycAutoAttachments(reqs, approvedKyc({ expires_at: "2026-01-01" }), NOW), []);
  assert.deepEqual(kycAutoAttachments([{ code: "NATIONAL_ID_CARD", status: "UPLOADED" }], approvedKyc(), NOW), []);
  assert.deepEqual(kycAutoAttachments([{ code: "NATIONAL_ID_CARD", satisfied_by: "PROPOSAL_FORM" }], approvedKyc(), NOW), []);
  assert.deepEqual(kycAutoAttachments(reqs, null, NOW), []);
});

test("rejected KYC documents are never reused", () => {
  const kyc = approvedKyc({ documents: [{ id: "front", purpose: "ID_FRONT", verification_status: "REJECTED" }, { id: "pp", purpose: "PASSPORT" }] });
  assert.deepEqual(kycAutoAttachments([{ code: "PASSPORT" }], { submission: { ...kyc.submission, requirements: [{ requirement_code: "PASSPORT", mandatory: true, satisfied: true, accepted_canonical_codes: ["PASSPORT"] }] } }, NOW), [
    { requirement_code: "PASSPORT", document_id: "pp" },
  ]);
});
