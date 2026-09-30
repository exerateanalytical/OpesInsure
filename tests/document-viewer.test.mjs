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

test("pdf.js is bundled (no CDN), patched for CVE-2024-4367 and run without eval", async () => {
  const dv = await import("../src/lib/documentViewer.ts");
  const [major, minor, patch] = dv.PDFJS_VERSION.split(".").map(Number);
  assert.ok(major > 4 || (major === 4 && (minor > 2 || (minor === 2 && patch >= 67))), dv.PDFJS_VERSION);
  const html = dv.viewerHtml("JVBERi0xLjQK", { loadingLabel: "Chargement <…>" });
  const has = (re) => re.test(html);
  assert.ok(!has(/cdnjs|<script[^>]+src=/i), "no CDN / external script");
  assert.ok(has(/isEvalSupported:false/), "isEvalSupported:false");
  assert.ok(has(/Content-Security-Policy" content="default-src 'none'/), "CSP");
  assert.ok(html.includes("Chargement &#60;…&#62;"), "localized, escaped loading label");
  // Only the two real closing tags: the bundled library cannot break out of its <script>.
  assert.equal(html.match(/<\/script/gi).length, 2);
  assert.doesNotMatch(read("src/lib/documentViewer.ts"), /cdnjs/);
  assert.doesNotMatch(read("app/documents/view.tsx"), /originWhitelist=\{\["\*"\]\}/);
});

test("url: viewer sources are limited to the API host", async () => {
  const { parseViewerSource, isAllowedDocumentUrl, viewerAllowsNavigation } = await import("../src/lib/documentViewer.ts");
  const api = "https://insurance.opesdatacenter.tech/api/v1";
  assert.deepEqual(parseViewerSource("url:https://insurance.opesdatacenter.tech/api/v1/documents/1/download", api), {
    kind: "url",
    url: "https://insurance.opesdatacenter.tech/api/v1/documents/1/download",
  });
  assert.equal(parseViewerSource("url:https://evil.example/x.pdf", api), null);
  assert.equal(parseViewerSource("url:https://insurance.opesdatacenter.tech.evil.example/x.pdf", api), null);
  assert.equal(parseViewerSource("url:http://insurance.opesdatacenter.tech/x.pdf", api), null);
  assert.equal(parseViewerSource("url:https://user:pw@insurance.opesdatacenter.tech/x.pdf", api), null);
  assert.equal(parseViewerSource("url:https://insurance.opesdatacenter.tech/x.pdf", ""), null);
  assert.equal(isAllowedDocumentUrl("http://localhost:8000/api/v1/d/1", "http://localhost:8000/api/v1"), true);
  assert.deepEqual(parseViewerSource("quote:42", api), { kind: "quote", quoteId: "42" });
  assert.equal(viewerAllowsNavigation("https://insurance.opesdatacenter.tech/"), true);
  assert.equal(viewerAllowsNavigation("https://evil.example/"), false);
  assert.equal(viewerAllowsNavigation("https://insurance.opesdatacenter.tech/phish"), false);
});
