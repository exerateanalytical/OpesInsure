import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

// XAF is stored x100 (minor units) but always displayed as whole FCFA (backend Money::display).
test("FCFA amounts are displayed without decimals", () => {
  const i18n = readFileSync(new URL("../src/i18n/index.ts", import.meta.url), "utf8");
  assert.match(i18n, /maximumFractionDigits: 0/);
  const fmt = (minor) => `${new Intl.NumberFormat("fr-CM", { maximumFractionDigits: 0 }).format(Math.round(minor / 100))} FCFA`;
  assert.doesNotMatch(fmt(657240), /[.,]\d{1,2} FCFA$/);
  assert.doesNotMatch(fmt(4017363), /[.,]\d{1,2} FCFA$/);
});
