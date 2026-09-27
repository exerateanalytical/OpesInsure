import test from "node:test";
import assert from "node:assert/strict";
import {
  activeFilterCount,
  applyFilters,
  byDate,
  byText,
  countBy,
  customPeriod,
  emptyFilters,
  filterQuery,
  inPeriod,
  matchesText,
  normalizeText,
  periodMatcher,
  periodRange,
  removeFilter,
  runList,
  sortRows,
  totals,
} from "../src/components/filters/core.ts";

const rows = [
  { id: "1", name: "Société Générale Assurances", city: "Yaoundé", status: "ACTIVE", line: "MOTOR", amount: 1000, at: "2026-09-30T22:30:00Z" },
  { id: "2", name: "ACTIVA ASSURANCES", city: "Douala", status: "EXPIRED", line: "HEALTH", amount: 2500, at: "2026-10-01T00:30:00Z" },
  { id: "3", name: "Chanas", city: "Douala", status: "ACTIVE", line: "HEALTH", amount: 400, at: null },
];
const matchers = { status: (r, v) => r.status === v, line: (r, v) => r.line === v, period: periodMatcher((r) => r.at, () => new Date("2026-10-15T10:00:00Z")) };
const hay = (r) => [r.name, r.city];
const sections = [
  { key: "status", options: [{ value: "ACTIVE", label: "Active" }, { value: "EXPIRED", label: "Expired" }] },
  { key: "line", options: [{ value: "MOTOR", label: "Motor" }, { value: "HEALTH", label: "Health" }] },
  { key: "period", single: true, kind: "period", options: [{ value: "any", label: "Any" }, { value: "this_month", label: "This month" }] },
  { key: "sort", single: true, kind: "sort", options: [{ value: "recent", label: "Recent" }, { value: "name", label: "A-Z" }] },
];

test("search is accent- and case-insensitive (French names)", () => {
  assert.equal(normalizeText("  Société   GÉNÉRALE "), "societe generale");
  assert.ok(matchesText("societe", ["Société Générale"]));
  assert.ok(matchesText("YAOUNDE", ["Yaoundé"]));
  assert.ok(matchesText("générale yaounde", ["Société Générale", "Yaoundé"]), "every word may match a different field");
  assert.ok(!matchesText("allianz", ["Société Générale"]));
  assert.ok(matchesText("", ["x"]));
  assert.deepEqual(applyFilters(rows, {}, matchers, "yaounde", hay).map((r) => r.id), ["1"]);
});

test("filters combine: OR inside a section, AND across sections and with search", () => {
  assert.deepEqual(applyFilters(rows, { status: ["ACTIVE"], line: ["HEALTH"] }, matchers).map((r) => r.id), ["3"]);
  assert.deepEqual(applyFilters(rows, { status: ["ACTIVE", "EXPIRED"] }, matchers).map((r) => r.id), ["1", "2", "3"]);
  assert.deepEqual(applyFilters(rows, { line: ["HEALTH"] }, matchers, "douala", hay).map((r) => r.id), ["2", "3"]);
  assert.deepEqual(applyFilters(rows, { line: ["HEALTH"] }, matchers, "activa", hay).map((r) => r.id), ["2"]);
  assert.deepEqual(applyFilters(rows, { status: ["ALL"] }, matchers).length, 3, "ALL is no filter");
});

test("active count ignores single sections at default; clear-all resets everything", () => {
  const v = { status: ["ACTIVE", "EXPIRED"], line: [], period: ["this_month"], sort: ["recent"] };
  assert.equal(activeFilterCount(v, sections), 3);
  const cleared = emptyFilters(sections);
  assert.deepEqual(cleared, { status: [], line: [], period: ["any"], sort: ["recent"] });
  assert.equal(activeFilterCount(cleared, sections), 0);
  assert.equal(applyFilters(rows, cleared, matchers).length, 3);
  assert.deepEqual(removeFilter(v, sections[2], "this_month").period, ["any"]);
  assert.deepEqual(removeFilter(v, sections[0], "ACTIVE").status, ["EXPIRED"]);
});

test("date presets use Africa/Douala month boundaries", () => {
  // 2026-09-30T22:30Z is 23:30 on 30 Sep in Douala; 2026-10-01T00:30Z is 01:30 on 1 Oct.
  const now = new Date("2026-10-15T10:00:00Z");
  const r = periodRange("this_month", now);
  assert.equal(r.from.toISOString(), "2026-09-30T23:00:00.000Z");
  assert.equal(r.to.toISOString(), "2026-10-31T23:00:00.000Z");
  assert.ok(!inPeriod("2026-09-30T22:30:00Z", "this_month", now));
  assert.ok(inPeriod("2026-10-01T00:30:00Z", "this_month", now));
  assert.ok(inPeriod("2026-09-30T22:30:00Z", "last_month", now));
  assert.ok(!inPeriod(null, "this_month", now), "undated rows only match 'any'");
  assert.ok(inPeriod(null, "any", now));
  assert.deepEqual(applyFilters(rows, { period: ["this_month"] }, matchers).map((x) => x.id), ["2"]);
  // Just after midnight local on 1 Oct (23:30Z on 30 Sep) it is already October in Douala.
  const early = new Date("2026-09-30T23:30:00Z");
  assert.equal(periodRange("this_month", early).from.toISOString(), "2026-09-30T23:00:00.000Z");
  assert.equal(periodRange("today", early).from.toISOString(), "2026-09-30T23:00:00.000Z");
  // January rolls back to December of the previous year.
  const jan = periodRange("last_month", new Date("2027-01-10T12:00:00Z"));
  assert.equal(jan.from.toISOString(), "2026-11-30T23:00:00.000Z");
  assert.equal(jan.to.toISOString(), "2026-12-31T23:00:00.000Z");
  const week = periodRange("7d", now);
  assert.equal(week.from.toISOString(), "2026-10-08T23:00:00.000Z");
});

test("custom range is inclusive of both local days", () => {
  const v = customPeriod("2026-09-30", "2026-09-30");
  assert.ok(inPeriod("2026-09-30T22:30:00Z", v));
  assert.ok(!inPeriod("2026-10-01T00:30:00Z", v));
  const swapped = periodRange(customPeriod("2026-10-05", "2026-10-01"));
  assert.ok(swapped.from < swapped.to);
  assert.equal(periodRange("custom:.."), null);
});

test("sort is stable and totals follow the filtered rows", () => {
  const sorters = { name: byText((r) => r.name), recent: byDate((r) => r.at) };
  assert.deepEqual(sortRows(rows, "name", sorters).map((r) => r.id), ["2", "3", "1"]);
  assert.deepEqual(sortRows(rows, "recent", sorters).map((r) => r.id), ["2", "1", "3"], "undated last");
  const visible = runList(rows, { values: { line: ["HEALTH"], sort: ["name"] }, matchers, sorters });
  assert.deepEqual(visible.map((r) => r.id), ["2", "3"]);
  assert.deepEqual(totals(visible, (r) => r.amount), { total: 2900, count: 2 });
  assert.deepEqual(totals(visible, (r) => r.amount, (r) => r.status === "ACTIVE"), { total: 400, count: 1 });
  assert.deepEqual(countBy(rows, (r) => r.city), { Yaoundé: 1, Douala: 2 });
});

test("server query carries filters, skips defaults and expands periods", () => {
  assert.equal(filterQuery({}, ""), "");
  const q = new URLSearchParams(filterQuery({ status: ["A", "B"], sort: ["recent"], period: ["any"] }, " x ", sections).slice(1));
  assert.deepEqual(q.getAll("status"), ["A", "B"]);
  assert.equal(q.get("q"), "x");
  assert.equal(q.has("sort"), false);
  assert.equal(q.has("period_from"), false);
  const p = new URLSearchParams(filterQuery({ period: ["today"] }, "", sections).slice(1));
  assert.ok(p.get("period_from") && p.get("period_to"));
});
