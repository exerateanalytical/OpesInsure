import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { awaitingCount, currentOnly, DOCUMENT_GROUPS, documentTitle, groupKey, statusKey, statusTone } from "../src/lib/policyDocuments.ts";

const root = fileURLToPath(new URL("..", import.meta.url));
const read = (p) => readFileSync(join(root, p), "utf8");

test("documents tab has the five stage groups in order", () => {
  assert.deepEqual(DOCUMENT_GROUPS, ["POLICY_PACK", "CERTIFICATES", "SERVICING", "CLAIMS", "FINANCIAL"]);
});

test("replaced / revoked / superseded documents are never shown as current", () => {
  const docs = [
    { id: "1", status: "VALID", is_current: true },
    { id: "2", status: "REPLACED", is_current: false },
    { id: "3", status: "REVOKED", is_current: false },
  ];
  assert.deepEqual(currentOnly(docs).map((d) => d.id), ["1"]);
  assert.equal(statusTone("VALID"), "success");
  assert.equal(statusTone("SUPERSEDED"), "warning");
  assert.equal(statusTone("REVOKED"), "danger");
  assert.equal(statusTone("PENDING_SIGNATURE"), "info");
});

test("titles use the French register name in French and show the subject", () => {
  const d = { title: "Motor Insurance Attestation", title_fr: "Attestation d'assurance automobile", subject_label: "LT-123-AA" };
  assert.equal(documentTitle(d, "fr"), "Attestation d'assurance automobile · LT-123-AA");
  assert.equal(documentTitle({ ...d, subject_label: null }, "en"), "Motor Insurance Attestation");
});

test("awaiting carrier documents are counted across packs", () => {
  assert.equal(awaitingCount({ packs: [{ items: [{ state: "AWAITING_CARRIER_DOCUMENT" }, { state: "GENERATED" }] }, { items: [{ state: "AWAITING_CARRIER_DOCUMENT" }] }] }), 2);
});

test("every group, status and language label exists in EN and FR", () => {
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  const keys = [
    ...DOCUMENT_GROUPS.map(groupKey),
    ...["DRAFT", "GENERATED", "PENDING_SIGNATURE", "ISSUED", "VALID", "SUPERSEDED", "REPLACED", "REVOKED", "EXPIRED", "CANCELLED"].map(statusKey),
    "docLanguage_FR", "docLanguage_EN", "docLanguage_BILINGUAL", "docDownloadPack", "docYourUploadsHint",
  ];
  for (const k of keys) {
    assert.match(en, new RegExp(`\\b${k}:`), `en missing ${k}`);
    assert.match(fr, new RegExp(`\\b${k}:`), `fr missing ${k}`);
  }
});

test("policy detail renders the documents section from the document engine endpoint", () => {
  assert.match(read("src/components/policies/PolicyDetailView.tsx"), /<PolicyDocumentsSection policyId=\{id\} \/>/);
  assert.match(read("src/api/policyDocuments.ts"), /\/mobile\/policies\/\$\{policyId\}\/documents/);
});
