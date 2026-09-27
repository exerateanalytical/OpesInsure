// NAV-001: reload / deep link into a partner portal lands on the requested page after auth.
import test from "node:test";
import assert from "node:assert/strict";
import { rememberColdStartPath, restorablePath, takePendingPath } from "../src/lib/navigationContinuity.ts";

test("restores a path inside the resolved portal only", () => {
  assert.equal(restorablePath("/agent/wallet", "/agent"), "/agent/wallet");
  assert.equal(restorablePath("/agent/clients/abc?x=1", "/agent"), "/agent/clients/abc?x=1");
  assert.equal(restorablePath("/broker/commissions", "/agent"), null);
  assert.equal(restorablePath("/agent", "/agent"), null);
  assert.equal(restorablePath("/policy/1", "/(customer)/(tabs)"), null);
});

test("pending path is consumed once and ignores non-portal paths", () => {
  rememberColdStartPath("/carrier/claims");
  assert.equal(takePendingPath("/carrier"), "/carrier/claims");
  assert.equal(takePendingPath("/carrier"), null);
  rememberColdStartPath("/welcome");
  assert.equal(takePendingPath("/agent"), null);
});
