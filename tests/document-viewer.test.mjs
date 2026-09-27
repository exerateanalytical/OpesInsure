import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { cacheKey, detectKind, imageMime, pageIndicator } from "../src/lib/documentViewer.ts";

const root = fileURLToPath(new URL("..", import.meta.url));
const read = (p) => readFileSync(join(root, p), "utf8");

test("payload kind is detected from magic bytes before MIME", () => {
  assert.equal(detectKind("JVBERi0xLjcK", "text/html"), "pdf");
  assert.equal(detectKind("iVBORw0KGgo"), "image");
  assert.equal(detectKind("/9j/4AAQ"), "image");
  assert.equal(detectKind("PGh0bWw+", "text/html"), "html");
  assert.equal(imageMime("iVBORw0KGgo"), "image/png");
});

test("offline cache key ignores signature query strings", () => {
  const a = cacheKey({ kind: "url", url: "https://x.test/r/1.pdf?signature=a&expires=1" });
  const b = cacheKey({ kind: "url", url: "https://x.test/r/1.pdf?signature=b&expires=2" });
  assert.equal(a, b);
  assert.notEqual(a, cacheKey({ kind: "quote", quoteId: "1" }));
});

test("page indicator reads 3 / 7", () => {
  assert.equal(pageIndicator(3, 7), "3 / 7");
  assert.equal(pageIndicator(0, 0), "");
});

test("viewer renders PDFs natively and keeps the WebView only as a guarded fallback", () => {
  const view = read("app/documents/view.tsx");
  assert.match(view, /loadNativePdf\(\)/);
  assert.match(view, /useNative && NativePdf/);
  assert.match(view, /createElement\("iframe"/);
  const loader = read("src/components/documents/nativePdf.native.ts");
  assert.match(loader, /ReactNativeBlobUtil/);
  assert.match(loader, /try \{[\s\S]*require\("react-native-pdf"\)/);
  assert.doesNotMatch(read("src/components/documents/nativePdf.web.ts"), /react-native-pdf|react-native-blob-util/);
  for (const f of ["src/i18n/en.ts", "src/i18n/fr.ts"]) assert.match(read(f), /docViewerPageOf/);
});
