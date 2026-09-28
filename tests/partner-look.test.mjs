import test from "node:test";
import assert from "node:assert/strict";
import { partnerLookFor } from "../src/lib/partnerLook.ts";

test("partner portals are detected by their route prefix", () => {
  assert.equal(partnerLookFor("/broker"), "broker");
  assert.equal(partnerLookFor("/broker/policies/12"), "broker");
  assert.equal(partnerLookFor("/carrier/referrals"), "carrier");
  assert.equal(partnerLookFor("/agent/clients"), "agent");
});

test("customer and shared routes keep the customer look", () => {
  for (const p of ["/", "/policy/1", "/search", "/brokers", "/agents-directory", "/carriers", null, undefined, ""]) {
    assert.equal(partnerLookFor(p), null, String(p));
  }
});
