#!/usr/bin/env node
/**
 * Design QA harness. Signs in to the web preview (http://localhost:8089) with
 * the normal phone + one-time-code flow, opens each screen with LIVE data, and captures it at 360, 390
 * and 430 dp wide. Then `python scripts/qa-compose.py` puts the captures next
 * to the design image so differences are visible side by side.
 *
 *   node scripts/qa-shots.mjs                 # every screen in scripts/qa-screens.json
 *   node scripts/qa-shots.mjs policy-details  # only screens whose name contains the filter
 *
 * Output: docs/qa/shots/<name>@<width>.png
 *
 * Sign-in (required for signed-in screens):
 *   QA_PHONE  Cameroon mobile number(s), comma-separated; a screen's
 *             "account": N in qa-screens.json picks the N-th number (default 0).
 *   QA_OTP    the one-time code the server accepts for those numbers
 *             (single value, or comma-separated per account).
 * There is no demo account picker any more; without these vars signed-in
 * screens cannot be captured.
 *
 * Font-scale stress (A11Y-005): QA_FONT_SCALE=1.3 or 2 multiplies every text
 * node's font-size/line-height (as Android "Font size" would) and writes
 * <name>@<width>-fs<scale>.png. "clipped" then also counts line-clamped text
 * that no longer fits (numberOfLines on critical text).
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import puppeteer from "puppeteer-core";

const BASE = process.env.QA_BASE ?? "http://localhost:8089";
const API = "https://insurance.opesdatacenter.tech/api/v1";
const FONT_SCALE = Number(process.env.QA_FONT_SCALE ?? 1);
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
  const phones = (process.env.QA_PHONE ?? "").split(",").map((v) => v.trim()).filter(Boolean);
  const otps = (process.env.QA_OTP ?? "").split(",").map((v) => v.trim()).filter(Boolean);
  const phone = phones[account];
  const otp = otps[account] ?? otps[0];
  if (!phone || !otp) throw new Error(`QA_PHONE (entry ${account}) and QA_OTP must be set to capture signed-in screens`);
  const clickText = (labels) =>
    page.evaluate((labels) => {
      const el = [...document.querySelectorAll("[role=button],button,a,div")].find((e) => labels.includes((e.textContent ?? "").trim()));
      el?.click();
      return !!el;
    }, labels);
  await page.waitForSelector("input[type=tel], input[inputmode=tel], input[autocomplete=tel]", { timeout: 60000 });
  await clickText(["Use a one-time code instead", "Utiliser un code à usage unique"]);
  await sleep(500);
  await page.type("input[type=tel], input[inputmode=tel], input[autocomplete=tel]", phone);
  await clickText(["Send code", "Envoyer le code"]);
  await page.waitForFunction(() => location.pathname.includes("verify"), { timeout: 60000 });
  await page.waitForSelector("input[placeholder='000000']", { timeout: 60000 });
  await page.type("input[placeholder='000000']", otp);
  await sleep(300);
  await clickText(["Verify and continue", "Vérifier et continuer"]);
  await page.waitForFunction(() => !/sign-in|verify/.test(location.pathname), { timeout: 60000 });
  await sleep(4000);
}

/** Resolves `{policy}`, `{claim}`, … placeholders in a route from live data. */
async function resolveRoute(page, route) {
  if (!route.includes("{")) return route;
  const ids = await page.evaluate(async (API, route) => {
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
    const [wallet, claims, payments, proposals, quotes, cases, insurers, agentCommissions, agentWithdrawals] = await Promise.all([
      get("/mobile/wallet"),
      get("/mobile/claims"),
      get("/mobile/payments"),
      get("/mobile/proposals"),
      get("/mobile/quotes"),
      get("/mobile/support/cases"),
      pub("/public/institutions?type=insurer"),
      // Agent portal only (other roles get [] from the 403).
      route.includes("{commission}") ? get("/mobile/agent/commissions") : [],
      route.includes("{withdrawal}") ? get("/mobile/agent/withdrawals") : [],
    ]);
    const active = wallet.find((p) => p.status === "ACTIVE") ?? wallet[0];
    const withProducts = insurers.find((i) => (i.products ?? []).length > 1) ?? insurers[0];
    return {
      policy: active?.id,
      claim: claims[0]?.id,
      // A claim past its decision (decision / settlement screens); falls back to the first claim.
      claimDecided: (claims.find((c) => ["APPROVED", "PARTIALLY_APPROVED", "DECLINED", "SETTLED", "PAID", "CLOSED"].includes(c.status)) ?? claims[0])?.id,
      payment: payments[0]?.id,
      proposal: proposals[0]?.id,
      quote: quotes[0]?.id,
      ticket: cases[0]?.id,
      insurer: withProducts?.id,
      commission: agentCommissions[0]?.id,
      withdrawal: agentWithdrawals[0]?.id,
    };
  }, API, route);
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
        // Labels may list EN|FR alternatives separated by "|" (the signed-in account locale decides the language).
        // `via.clickPrefix` (optional) matches an aria-label that starts with the text, e.g. a RadioCard "Title. Subtitle".
        await page.evaluate((label, prefix) => document.querySelector(String(prefix ?? label).split("|").map((t) => (prefix ? `[aria-label^="${t}"]` : `[aria-label="${t}"]`)).join(","))?.click(), s.via.click, s.via.clickPrefix);
        await sleep(s.via.wait ?? 5000);
        target = await page.evaluate(() => location.pathname);
      } else target = await open(page, s.route);
      // Optional in-page steps before the shot, e.g. advance a carousel: click `[aria-label=after.click]` `after.times` times.
      // `after` may also be an array of steps run in order; `clickPrefix` matches an aria-label prefix and
      // `each: true` clicks the i-th match on the i-th time (e.g. tick several "Add to comparison" boxes).
      for (const step of s.after ? (Array.isArray(s.after) ? s.after : [s.after]) : []) {
        for (let i = 0; i < (step.times ?? 1); i++) {
          await page.evaluate(
            (label, prefix, each, n) => {
              const els = document.querySelectorAll(String(prefix ?? label).split("|").map((t) => (prefix ? `[aria-label^="${t}"]` : `[aria-label="${t}"]`)).join(","));
              (each ? els[n] : els[0])?.click();
            },
            step.click,
            step.clickPrefix,
            !!step.each,
            i,
          );
          await sleep(step.wait ?? 1200);
        }
      }
      if (FONT_SCALE !== 1) {
        await page.evaluate((k) => {
          for (const el of document.querySelectorAll("div,span")) {
            if (![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) continue;
            const cs = getComputedStyle(el);
            el.style.fontSize = `${parseFloat(cs.fontSize) * k}px`;
            if (cs.lineHeight !== "normal") el.style.lineHeight = `${parseFloat(cs.lineHeight) * k}px`;
          }
        }, FONT_SCALE);
        await sleep(800);
      }
      const file = path.join(outDir, `${s.name}@${w}${FONT_SCALE !== 1 ? `-fs${FONT_SCALE}` : ""}.png`);
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
          const clamped = cs.webkitLineClamp && cs.webkitLineClamp !== "none" && el.scrollHeight > el.clientHeight + 1;
          if ((cs.textOverflow === "ellipsis" && el.scrollWidth > el.clientWidth + 1) || clamped) { clipped++; if (process_debug) dbg.push("C " + (el.textContent || "").slice(0, 60)); }
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
