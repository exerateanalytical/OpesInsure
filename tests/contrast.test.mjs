// A11Y-004 / BTN-003: WCAG AA contrast of the shared text and control tokens.
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const tokens = fs.readFileSync(new URL("../src/theme/tokens.ts", import.meta.url), "utf8");
const ui = fs.readFileSync(new URL("../src/components/ui.tsx", import.meta.url), "utf8");
const hex = (name) => tokens.match(new RegExp(`\\b${name}: '(#[0-9A-F]{6})'`))[1];
const lum = (h) => {
  const c = [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
const ratio = (a, b) => {
  const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
};

test("secondary/tertiary text tokens reach AA 4.5:1 on white and neutral50", () => {
  for (const fg of ["neutral500", "neutral600", "neutral700", "blue600", "blue700", "gold600", "dangerText", "successText", "warningText"])
    for (const bg of ["white", "neutral50"]) assert.ok(ratio(hex(fg), hex(bg)) >= 4.5, `${fg} on ${bg} = ${ratio(hex(fg), hex(bg)).toFixed(2)}`);
});

test("control borders reach 3:1 (WCAG 1.4.11) on white", () => {
  assert.ok(ratio(hex("neutral400"), hex("white")) >= 3);
  for (const style of ["button_secondary", "input", "chipSelect"]) {
    const block = ui.slice(ui.indexOf(`  ${style}: {`), ui.indexOf("}", ui.indexOf(`  ${style}: {`)));
    assert.match(block, /borderColor: colors\.neutral400/, `${style} border`);
  }
});

test("high-contrast mode follows the OS setting", () => {
  const c = fs.readFileSync(new URL("../src/theme/contrast.ts", import.meta.url), "utf8");
  assert.match(c, /isHighTextContrastEnabled/);
  assert.match(c, /isBoldTextEnabled/);
  assert.match(ui, /useHighContrast\(\)/);
});

test("selected chips are marked by a check icon, not colour alone", () => {
  assert.match(ui, /selected \? <Check/);
});
