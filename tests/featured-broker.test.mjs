import test from "node:test";
import assert from "node:assert/strict";
import { featuredFirst, isFeaturedBroker, productsByLine } from "../src/lib/institutions.ts";

test("ASSUR EXPERT D&G SARL is featured by name; the server flag wins", () => {
  assert.equal(isFeaturedBroker({ name: "ASSUR EXPERT D&G SARL" }), true);
  assert.equal(isFeaturedBroker({ name: "Assur Expert D & G Sarl" }), true);
  assert.equal(isFeaturedBroker({ name: "ACACE SARL" }), false);
  assert.equal(isFeaturedBroker({ name: "ASSUR EXPERT D&G SARL", featured: false }), false);
  assert.equal(isFeaturedBroker({ name: "ACACE SARL", featured: true }), true);
});

test("featured brokers come first, the rest keep their order", () => {
  const rows = [{ name: "A" }, { name: "B" }, { name: "ASSUR EXPERT D&G SARL" }, { name: "C" }];
  assert.deepEqual(featuredFirst(rows).map((r) => r.name), ["ASSUR EXPERT D&G SARL", "A", "B", "C"]);
});

test("products group by line, sorted", () => {
  const g = productsByLine([
    { id: "1", name: "Tous risques", line_code: "MOTOR" },
    { id: "2", name: "Santé famille", line_code: "HEALTH" },
    { id: "3", name: "Au tiers", line_code: "MOTOR" },
  ]);
  assert.deepEqual(g.map((x) => x.line), ["HEALTH", "MOTOR"]);
  assert.deepEqual(g[1].products.map((p) => p.name), ["Au tiers", "Tous risques"]);
  assert.deepEqual(productsByLine(undefined), []);
});
