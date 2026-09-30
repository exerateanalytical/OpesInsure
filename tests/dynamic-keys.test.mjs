import test from "node:test";
import assert from "node:assert/strict";
import { en } from "../src/i18n/en.ts";
import { fr } from "../src/i18n/fr.ts";

// td() builds these keys from API codes; a missing key shows the raw code to French users.
const REPRESENTATIVE = [
  "status_ACTIVE",
  "status_PAYMENT_FAILED",
  "status_VERIFIED",
  "line_MOTOR",
  "line_HEALTH",
  "commissionStatus_PAID",
  "commissionStatus_REVERSED",
  "renewalStatus_DUE",
  "complianceStatus_OPEN",
  "memberStatus_PENDING",
  "sellReason_LICENCE_EXPIRED",
  "relationship_SPOUSE",
];

test("dynamic td() prefixes have EN and FR entries", () => {
  for (const key of REPRESENTATIVE) {
    assert.equal(typeof en[key], "string", `en missing ${key}`);
    assert.equal(typeof fr[key], "string", `fr missing ${key}`);
    assert.ok(fr[key].trim().length > 0, `fr empty ${key}`);
  }
  assert.equal(fr.line_MOTOR, "Assurance auto");
});
