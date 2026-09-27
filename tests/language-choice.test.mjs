import test from "node:test";
import assert from "node:assert/strict";
import { normalizeLanguage, deviceLanguageFrom, resolveLanguage } from "../src/lib/languageChoice.ts";

test("normalizeLanguage maps tags to en/fr", () => {
  assert.equal(normalizeLanguage("fr-CM"), "fr");
  assert.equal(normalizeLanguage("FR"), "fr");
  assert.equal(normalizeLanguage("en_US"), "en");
  assert.equal(normalizeLanguage("de-DE"), null);
  assert.equal(normalizeLanguage(""), null);
  assert.equal(normalizeLanguage(undefined), null);
});

test("device language takes the first supported tag, else English", () => {
  assert.equal(deviceLanguageFrom(["fr-FR", "en"]), "fr");
  assert.equal(deviceLanguageFrom(["de", "fr-CA"]), "fr");
  assert.equal(deviceLanguageFrom(["es", "de"]), "en");
  assert.equal(deviceLanguageFrom([]), "en");
});

test("explicit in-app choice beats the device language", () => {
  assert.equal(resolveLanguage(null, ["en-US"]), "en");
  assert.equal(resolveLanguage("fr", ["en-US"]), "fr");
  assert.equal(resolveLanguage("en", ["fr-FR"]), "en");
  assert.equal(resolveLanguage("xx", ["fr-FR"]), "fr");
});
