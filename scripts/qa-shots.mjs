#!/usr/bin/env node
/**
 * Design QA harness. Signs in to the web preview (http://localhost:8089) as a
 * demo account, opens each screen with LIVE data, and captures it at 360, 390
 * and 430 dp wide. Then `python scripts/qa-compose.py` puts the captures next
 * to the design image so differences are visible side by side.
 *
 *   node scripts/qa-shots.mjs                 # every screen in scripts/qa-screens.json
 *   node scripts/qa-shots.mjs policy-details  # only screens whose name contains the filter
 *
 * Output: docs/qa/shots/<name>@<width>.png
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import puppeteer from "puppeteer-core";

const BASE = process.env.QA_BASE ?? "http://localhost:8089";
const API = "https://insurance.opesdatacenter.tech/api/v1";
const WIDTHS = (process.env.QA_WIDTHS ?? "360,390,430").split(",").map(Number);
const HEIGHT = Number(process.env.QA_HEIGHT ?? 2200);
const CHROME =
  process.env.QA_CHROME ??
  ["C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe"].find((p) => fs.existsSync(p));
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const outDir = path.join(root, "docs", "qa", "shots");
fs.mkdirSync(outDir, { recursive: true });

const screens = JSON.parse(fs.readFileSync(path.join(root, "scripts", "qa-screens.json"), "utf8"));
const filter = process.argv[2] ?? "";
const exact = screens.some((s) => s.name === filter);
const selected = screens.filter((s) => !filter || (exact ? s.name === filter : s.name.includes(filter)));

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function signIn(page, account = 0) {
  await page.goto(`${BASE}/sign-in`, { waitUntil: "networkidle2", timeout: 180000 });
  await page.evaluate(() => {
    try {
      localStorage.clear();
      sessionStorage.clear();
    } catch {}
  });
  await page.goto(`${BASE}/sign-in`, { waitUntil: "networkidle2", timeout: 180000 });
  await page.waitForSelector("[role=combobox]", { timeout: 60000 });
  await page.click("[role=combobox]");
  await sleep(500);
  const items = await page.$$("[role=menuitem]");
  await items[account].click();
  await page.waitForFunction(() => location.pathname !== "/sign-in", { timeout: 60000 });
  await sleep(4000);
}

/** Resolves `{policy}`, `{claim}`, … placeholders in a route from live data. */
async function resolveRoute(page, route) {
  if (!route.includes("{")) return route;
  const ids = await page.evaluate(async (API) => {
    const tok = sessionStorage.getItem("opesinsure.access_token");
    const tenant = sessionStorage.getItem("opesinsure.tenant_id");
    const get = async (p) => {
      try {
        const r = await fetch(API + p, { headers: { Authorization: `Bearer ${tok}`, Accept: "application/json", ...(tenant ? { "X-Tenant-Id": tenant } : {}) } });
        const j = await r.json();
        const d = j.data ?? j;
        return Array.isArray(d) ? d : d.items ?? d.data ?? d.policies ?? [];
      } catch {
        return [];
      }
    };
    const pub = async (p) => ((await (await fetch(API + p)).json()).data ?? []);
    const [wallet, claims, payments, proposals, quotes, cases, insurers] = await Promise.all([
      get("/mobile/wallet"),
      get("/mobile/claims"),
      get("/mobile/payments"),
      get("/mobile/proposals"),
      get("/mobile/quotes"),
      get("/mobile/support/cases"),
      pub("/public/institutions?type=insurer"),
    ]);
    const active = wallet.find((p) => p.status === "ACTIVE") ?? wallet[0];
    const withProducts = insurers.find((i) => (i.products ?? []).length > 1) ?? insurers[0];
    return {
      policy: active?.id,
      claim: claims[0]?.id,
      payment: payments[0]?.id,
      proposal: proposals[0]?.id,
      quote: quotes[0]?.id,
      ticket: cases[0]?.id,
      insurer: withProducts?.id,
    };
  }, API);
  return route.replace(/\{(\w+)\}/g, (m, k) => ids[k] ?? m);
}

async function open(page, route) {
  const target = await resolveRoute(page, route);
  await page.evaluate((to) => {
    history.pushState({}, "", to);
    dispatchEvent(new PopStateEvent("popstate"));
  }, target);
  await sleep(6500);
  return target;
}

const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ["--no-sandbox", "--hide-scrollbars"] });
const report = [];
try {
  const page = await browser.newPage();
  await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
  let account = -1;
  for (const s of selected) {
    const acc = s.account ?? 0;
    if (acc !== account || s.signedOut) {
      if (s.signedOut) {
        await page.goto(`${BASE}/`, { waitUntil: "networkidle2", timeout: 180000 });
        await page.evaluate(() => {
          localStorage.clear();
          sessionStorage.clear();
        });
        account = -1;
      } else {
        await signIn(page, acc);
        account = acc;
      }
    }
    const errors = [];
    const onErr = (m) => m.type() === "error" && errors.push(m.text().slice(0, 200));
    page.on("console", onErr);
    for (const w of WIDTHS) {
      await page.setViewport({ width: w, height: HEIGHT, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
      let target = s.route;
      if (s.signedOut) await page.goto(`${BASE}${s.route}`, { waitUntil: "networkidle2", timeout: 180000 }), await sleep(5000);
      else if (s.via) {
        // Optional UI path: open `via.route`, click the element labelled `via.click`, capture where it lands.
        await open(page, s.via.route);
        // `via.clickPrefix` (optional) matches an aria-label that starts with the text, e.g. a RadioCard "Title. Subtitle".
        await page.evaluate((label, prefix) => document.querySelector(prefix ? `[aria-label^="${prefix}"]` : `[aria-label="${label}"]`)?.click(), s.via.click, s.via.clickPrefix);
        await sleep(s.via.wait ?? 5000);
        target = await page.evaluate(() => location.pathname);
      } else target = await open(page, s.route);
      if (s.after) {
        // Optional in-page step before the shot, e.g. advance a carousel: click `[aria-label=after.click]` `after.times` times.
        for (let i = 0; i < (s.after.times ?? 1); i++) {
          await page.evaluate((label) => document.querySelector(`[aria-label="${label}"]`)?.click(), s.after.click);
          await sleep(s.after.wait ?? 1200);
        }
      }
      const file = path.join(outDir, `${s.name}@${w}.png`);
      await page.screenshot({ path: file });
      const overflow = await page.evaluate((process_debug) => {
        // Elements wider than the viewport or text clipped with an ellipsis.
        const vw = innerWidth;
        let wide = 0;
        let clipped = 0;
        const dbg = [];
        for (const el of document.querySelectorAll("div,span")) {
          const r = el.getBoundingClientRect();
          // Off-screen pages of a horizontal pager/strip are not visible overflow: skip when a clipping ancestor sits inside the viewport.
          let hidden = false;
          for (let a = el.parentElement; a && !hidden; a = a.parentElement) {
            const ox = getComputedStyle(a).overflowX;
            if ((ox === "auto" || ox === "scroll" || ox === "hidden") && a.getBoundingClientRect().right <= vw + 1) hidden = true;
          }
          if (!hidden && r.width > 0 && r.right > vw + 1) { wide++; if (process_debug) dbg.push("W " + el.getAttribute("aria-label") + " " + (el.textContent || "").slice(0, 40) + " r=" + Math.round(r.right)); }
          const cs = getComputedStyle(el);
          if (cs.textOverflow === "ellipsis" && el.scrollWidth > el.clientWidth + 1) { clipped++; if (process_debug) dbg.push("C " + (el.textContent || "").slice(0, 60)); }
        }
        return { wide, clipped, dbg };
      }, !!process.env.QA_DEBUG);
      if (process.env.QA_DEBUG) for (const d of overflow.dbg.slice(0, 30)) process.stdout.write(`  ${d}
`);
      delete overflow.dbg;
      report.push({ name: s.name, width: w, route: target, path: new URL(page.url()).pathname, ...overflow });
      process.stdout.write(`${s.name}@${w} -> ${new URL(page.url()).pathname} overflow=${overflow.wide} clipped=${overflow.clipped}\n`);
    }
    page.off("console", onErr);
    if (errors.length) report.push({ name: s.name, consoleErrors: [...new Set(errors)].slice(0, 5) });
  }
} finally {
  await browser.close();
}
fs.writeFileSync(path.join(root, "docs", "qa", "report.json"), JSON.stringify(report, null, 2));
