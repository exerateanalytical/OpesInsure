#!/usr/bin/env node
/**
 * Visual regression (DES-001). Compares docs/qa/shots/*.png (from
 * scripts/qa-shots.mjs) against docs/qa/baseline/*.png pixel by pixel.
 *
 *   node scripts/qa-diff.mjs            # compare; exit 1 when a screen changed more than the threshold
 *   node scripts/qa-diff.mjs --update   # accept the current shots as the new baseline
 *
 * QA_DIFF_THRESHOLD = share of differing pixels allowed (default 0.01 = 1%,
 * live data moves a little). Diff images: docs/qa/diff/<name>.png (changes in red).
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { PNG } from "pngjs";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const shots = path.join(root, "docs", "qa", "shots");
const base = path.join(root, "docs", "qa", "baseline");
const out = path.join(root, "docs", "qa", "diff");
const threshold = Number(process.env.QA_DIFF_THRESHOLD ?? 0.01);
const update = process.argv.includes("--update");
fs.mkdirSync(base, { recursive: true });
fs.mkdirSync(out, { recursive: true });

const files = fs.existsSync(shots) ? fs.readdirSync(shots).filter((f) => f.endsWith(".png")) : [];
if (update) {
  for (const f of files) fs.copyFileSync(path.join(shots, f), path.join(base, f));
  console.log(`baseline updated: ${files.length} screens`);
  process.exit(0);
}

let failed = 0;
for (const f of files) {
  const b = path.join(base, f);
  if (!fs.existsSync(b)) {
    console.log(`NEW   ${f} (no baseline; run with --update to accept)`);
    continue;
  }
  const A = PNG.sync.read(fs.readFileSync(path.join(shots, f)));
  const B = PNG.sync.read(fs.readFileSync(b));
  if (A.width !== B.width || A.height !== B.height) {
    console.log(`SIZE  ${f} ${B.width}x${B.height} -> ${A.width}x${A.height}`);
    failed++;
    continue;
  }
  const diff = new PNG({ width: A.width, height: A.height });
  let changed = 0;
  for (let i = 0; i < A.data.length; i += 4) {
    const d = Math.abs(A.data[i] - B.data[i]) + Math.abs(A.data[i + 1] - B.data[i + 1]) + Math.abs(A.data[i + 2] - B.data[i + 2]);
    const hit = d > 48; // ignore anti-aliasing noise
    if (hit) changed++;
    const g = (A.data[i] + A.data[i + 1] + A.data[i + 2]) / 3;
    diff.data[i] = hit ? 255 : g * 0.3 + 178;
    diff.data[i + 1] = hit ? 0 : g * 0.3 + 178;
    diff.data[i + 2] = hit ? 0 : g * 0.3 + 178;
    diff.data[i + 3] = 255;
  }
  const share = changed / (A.width * A.height);
  const bad = share > threshold;
  if (bad) {
    failed++;
    fs.writeFileSync(path.join(out, f), PNG.sync.write(diff));
  }
  console.log(`${bad ? "DIFF " : "ok   "} ${f} ${(share * 100).toFixed(2)}%`);
}
console.log(`${files.length} screens, ${failed} changed beyond ${(threshold * 100).toFixed(1)}%`);
process.exit(failed ? 1 : 0);
