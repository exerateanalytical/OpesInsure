import test from "node:test";
import assert from "node:assert/strict";
import { claimRail, claimStage, claimSegment, RAIL_STEPS, DETAIL_STAGES } from "../src/lib/claimStatus.ts";

test("claimRail collapses the seven tracker steps onto four nodes", () => {
  assert.equal(RAIL_STEPS.length, 4);
  assert.deepEqual(claimRail("SUBMITTED"), ["current", "upcoming", "upcoming", "upcoming"]);
  assert.deepEqual(claimRail("ACKNOWLEDGED"), ["done", "current", "upcoming", "upcoming"]);
  assert.deepEqual(claimRail("EVIDENCE_PENDING"), ["done", "current", "upcoming", "upcoming"]);
  assert.deepEqual(claimRail("CARRIER_REVIEW"), ["done", "current", "upcoming", "upcoming"]);
  assert.deepEqual(claimRail("UNDER_REVIEW"), ["done", "current", "upcoming", "upcoming"], "legacy alias");
  assert.deepEqual(claimRail("DISPUTED"), ["done", "done", "current", "upcoming"]);
  assert.deepEqual(claimRail("APPROVED"), ["done", "done", "done", "current"]);
  assert.deepEqual(claimRail("PAYMENT_PENDING"), ["done", "done", "done", "current"]);
  assert.deepEqual(claimRail("PAID"), ["done", "done", "done", "done"]);
  assert.deepEqual(claimRail("SETTLED"), ["done", "done", "done", "done"], "legacy alias");
  assert.deepEqual(claimRail(undefined), ["upcoming", "upcoming", "upcoming", "upcoming"]);
});

test("a declined claim shows a red rejected decision node", () => {
  assert.deepEqual(claimRail("DECLINED"), ["done", "done", "rejected", "upcoming"]);
  assert.deepEqual(claimRail("REJECTED"), ["done", "done", "rejected", "upcoming"]);
});

test("claimStage indexes the five detail nodes", () => {
  assert.equal(DETAIL_STAGES.length, 5);
  assert.equal(claimStage("SUBMITTED"), 0);
  assert.equal(claimStage("ACKNOWLEDGED"), 1);
  assert.equal(claimStage("ASSESSMENT"), 2);
  assert.equal(claimStage("DECLINED"), 3);
  assert.equal(claimStage("PAYMENT_PENDING"), 4);
  assert.equal(claimStage("PAID"), 5, "every node done once paid");
  assert.equal(claimStage("garbage"), 0);
});

test("claimSegment splits in progress from completed", () => {
  assert.equal(claimSegment("SUBMITTED"), "progress");
  assert.equal(claimSegment("APPROVED"), "progress");
  assert.equal(claimSegment("DECLINED"), "completed");
  assert.equal(claimSegment("PAID"), "completed");
  assert.equal(claimSegment("CLOSED"), "completed");
});
