import puppeteer from "puppeteer-core";
const BASE = "http://localhost:8089";
const OUT = process.env.OUT;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const b = await puppeteer.launch({ executablePath: "C:/Program Files/Google/Chrome/Application/chrome.exe", headless: true, args: ["--no-sandbox", "--hide-scrollbars"] });
const p = await b.newPage();
await p.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
await p.goto(BASE + "/sign-in", { waitUntil: "networkidle2", timeout: 300000 });
await p.evaluate(() => { localStorage.clear(); sessionStorage.clear(); });
await p.goto(BASE + "/sign-in", { waitUntil: "networkidle2", timeout: 300000 });
await p.waitForSelector("[role=combobox]", { timeout: 90000 });
await p.click("[role=combobox]");
await sleep(500);
(await p.$$("[role=menuitem]"))[0].click();
await p.waitForFunction(() => location.pathname !== "/sign-in", { timeout: 60000 });
await sleep(4000);
const go = async (to) => { await p.evaluate((to) => { history.pushState({}, "", to); dispatchEvent(new PopStateEvent("popstate")); }, to); await sleep(6000); };
await go("/quote/product");
const clickText = (src) => p.evaluate((src) => {
  const r = new RegExp(src, "i");
  const els = [...document.querySelectorAll("[role=button],[role=radio],[role=combobox]")].filter((e) => r.test((e.getAttribute("aria-label") || e.textContent || "").trim()));
  const e = els[0];
  if (e) { e.click(); return (e.getAttribute("aria-label") || e.textContent).slice(0, 80); }
  return null;
}, src);
const dump = () => p.evaluate(() => location.pathname + " :: " + document.body.innerText.slice(0, 400).split("\n").join(" / "));
console.log("motor:", await clickText("^(Motor insurance|Assurance auto)"));
await sleep(1500);
for (let i = 0; i < 5; i++) {
  const txt = await p.evaluate(() => document.body.innerText);
  if (/(Make|Marque)/.test(txt) && /(Model|Modèle)/.test(txt)) break;
  console.log("next:", await clickText("^(continuer|continue|suivant|next)"));
  await sleep(4500);
  console.log(await dump());
}
if (process.env.SCROLL) await p.evaluate((re) => { const el = [...document.querySelectorAll("div")].find((d) => new RegExp(re).test(d.textContent) && d.children.length === 0); el?.scrollIntoView({ block: "start" }); }, "^(Make|Marque)$");
for (const w of [360, 390, 430]) {
  await p.setViewport({ width: w, height: 1000, isMobile: true, hasTouch: true });
  await sleep(1500);
  await p.evaluate(() => { const el = [...document.querySelectorAll("div")].find((d) => /^(Make|Marque)$/.test(d.textContent.trim()) && !d.children.length); el?.scrollIntoView({ block: "center" }); });
  await sleep(600);
  await p.screenshot({ path: `${OUT}-${w}.png` });
}
if (process.env.OPEN) {
  await p.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
  await sleep(1000);
  console.log("open:", await clickText(process.env.OPEN));
  await sleep(2500);
  await p.screenshot({ path: `${OUT}-sheet.png` });
  if (process.env.PICK) {
    console.log("pick:", await p.evaluate((l) => { const e = [...document.querySelectorAll("[role=radio]")].find((x) => x.getAttribute("aria-label") === l); e?.click(); return !!e; }, process.env.PICK));
    await sleep(4000);
    await p.evaluate(() => { const el = [...document.querySelectorAll("div")].find((d) => /^(Make|Marque)$/.test(d.textContent.trim()) && !d.children.length); el?.scrollIntoView({ block: "center" }); });
    await p.screenshot({ path: `${OUT}-picked.png` });
    console.log("model:", await clickText("^(Model|Modèle)"));
    await sleep(2500);
    await p.screenshot({ path: `${OUT}-models.png` });
  }
}
await b.close();
