import test from "node:test";
import assert from "node:assert/strict";
import { addDays, canChooseStart, clampStart, doualaToday } from "../src/lib/coverStart.ts";

test("Douala calendar day (UTC+1) rolls over at 23:00 UTC", () => {
  assert.equal(doualaToday(Date.parse("2026-09-29T22:59:00Z")), "2026-09-29");
  assert.equal(doualaToday(Date.parse("2026-09-29T23:00:00Z")), "2026-09-30");
});

test("adding days crosses months and years", () => {
  assert.equal(addDays("2026-09-29", 3), "2026-10-02");
  assert.equal(addDays("2026-12-31", 1), "2027-01-01");
  assert.equal(addDays("2026-03-01", -1), "2026-02-28");
});

test("the chosen start stays between today and the product's advance limit", () => {
  const now = Date.parse("2026-09-29T10:00:00Z");
  assert.equal(clampStart("2026-09-01", 90, now), "2026-09-29");
  assert.equal(clampStart("2026-10-15", 90, now), "2026-10-15");
  assert.equal(clampStart("2027-06-01", 90, now), "2026-12-28");
});

test("only products allowing SPECIFIED_DATE offer the choice", () => {
  assert.equal(canChooseStart(["IMMEDIATE", "SPECIFIED_DATE"]), true);
  assert.equal(canChooseStart(["IMMEDIATE"]), false);
  assert.equal(canChooseStart(null), false);
});
