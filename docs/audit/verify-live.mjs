// Live verification for the mobile audit remediation (plan Phases 6 + 9).
// Node 22, no dependencies. Prints PASS / FAIL / WARN per check and exits 1 on any FAIL.
//   node docs/audit/verify-live.mjs
//   BASE=http://localhost:8000/api/v1 node docs/audit/verify-live.mjs
// NOTE: this writes data (registers one random account, OTP/step-up challenges).
const BASE = (process.env.BASE ?? process.env.API_BASE ?? "https://insurance.opesdatacenter.tech/api/v1").replace(/\/$/, "");
const DEMO_OTP_FALLBACK = "123456";
const DEMO_PASSWORD = process.env.DEMO_PASSWORD ?? "Demo@12345";
const WEB_DEMO = [
  { phone_e164: "+237600000007", role_code: "BROKER_STAFF", label: "Web demo broker staff" },
  { phone_e164: "+237600000008", role_code: "AGENT", label: "Web demo agent" },
];
const j = (o) => JSON.stringify(o);
const uuid = () => crypto.randomUUID();
// Same shape as mobile app src/api/client.ts deviceInfo(); backend normalizeDevice() flattens it.
const device = (tag) => ({ fingerprint: `verify-live-${tag}`, name: "OpesInsure android", platform: "android" });

const results = { PASS: 0, FAIL: 0, WARN: 0 };
function report(kind, name, detail = "") {
  results[kind]++;
  console.log(`${kind.padEnd(4)} ${name}${detail ? " :: " + detail : ""}`);
}
const pass = (n, d) => report("PASS", n, d);
const fail = (n, d) => report("FAIL", n, d);
const warn = (n, d) => report("WARN", n, d);
const why = (r) => `${r.status} ${r.json?.message ?? r.text.slice(0, 200)}`;

async function call(path, { method = "GET", body, token, tenant } = {}) {
  try {
    const res = await fetch(BASE + path, {
      method,
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Request-ID": uuid(),
        ...(method !== "GET" ? { "Idempotency-Key": uuid() } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(tenant ? { "X-Tenant-Id": tenant } : {}),
      },
      body: body === undefined ? undefined : j(body),
    });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch {}
    return { status: res.status, json, text };
  } catch (e) {
    return { status: 0, json: null, text: String(e?.cause?.message ?? e.message) };
  }
}
const ok2xx = (r) => r.status >= 200 && r.status < 300;
const listOf = (d) => (Array.isArray(d) ? d : Array.isArray(d?.data) ? d.data : Array.isArray(d?.items) ? d.items : null);

console.log(`BASE = ${BASE}\n`);

// ---------- 1. Public ----------
console.log("== 1. Public endpoints");
let demo = null;
{
  const r = await call("/public/demo-accounts");
  if (r.status === 200 && Array.isArray(r.json?.data?.accounts)) { demo = r.json.data; pass("GET /public/demo-accounts", `${demo.accounts.length} accounts, otp=${demo.otp}`); }
  else fail("GET /public/demo-accounts", why(r));
}
{
  const r = await call("/public/support-contacts");
  const d = r.json?.data ?? {};
  if (r.status === 200 && ["email", "phone", "whatsapp", "partner_email"].every((k) => k in d)) pass("GET /public/support-contacts", j(d));
  else fail("GET /public/support-contacts", r.status === 200 ? `missing keys in ${j(d)}` : why(r));
}
{
  const ref = `NOPE-${Date.now()}`;
  const r = await call("/public/insurance/verify", { method: "POST", body: { reference: ref } });
  if (r.status === 200 && r.json?.data?.result === "not_found") pass("POST /public/insurance/verify unknown -> not_found");
  else fail("POST /public/insurance/verify unknown -> not_found", `${why(r)} result=${r.json?.data?.result}`);
}
for (const type of ["insurer", "broker"]) {
  const r = await call(`/public/institutions?type=${type}`);
  if (r.status === 404) warn(`GET /public/institutions?type=${type}`, "404 (not deployed yet)");
  else if (r.status === 200 && listOf(r.json?.data ?? r.json)) pass(`GET /public/institutions?type=${type}`, `${listOf(r.json?.data ?? r.json).length} rows`);
  else fail(`GET /public/institutions?type=${type}`, why(r));
}

const OTP = demo?.otp || DEMO_OTP_FALLBACK;

// ---------- 2. Logins ----------
console.log("\n== 2. Logins (OTP + password) for every persona");
async function otpLogin(phone, tag) {
  const req = await call("/auth/mobile/otp/request", { method: "POST", body: { phone, phone_e164: phone } });
  if (!ok2xx(req)) return { err: `otp/request ${why(req)}` };
  const challenge = req.json?.data?.challenge_id;
  const ver = await call("/auth/mobile/otp/verify", { method: "POST", body: { challenge_id: challenge, phone, phone_e164: phone, code: OTP, device: device(tag) } });
  if (!ok2xx(ver) || !ver.json?.data?.access_token) return { err: `otp/verify ${why(ver)}` };
  return { session: ver.json.data };
}
async function passwordLogin(phone, password, tag) {
  return call("/auth/mobile/password-login", { method: "POST", body: { phone, phone_e164: phone, password, device: device(tag) } });
}

const personas = [...(demo?.accounts ?? []).map((a) => ({ ...a })), ...WEB_DEMO.filter((w) => !(demo?.accounts ?? []).some((a) => a.phone_e164 === w.phone_e164))];
for (const p of personas) {
  const tag = p.phone_e164.replace(/\D/g, "");
  const o = await otpLogin(p.phone_e164, tag);
  if (o.err) { fail(`OTP login ${p.role_code} ${p.phone_e164}`, o.err); }
  else {
    p.session = o.session;
    p.token = o.session.access_token;
    const ws = (o.session.workspaces ?? []).find((w) => w.role_code === p.role_code) ?? o.session.workspaces?.[0];
    p.workspace = ws;
    p.tenant = ws?.tenant_id;
    pass(`OTP login ${p.role_code} ${p.phone_e164}`, `workspaces=${(o.session.workspaces ?? []).map((w) => w.role_code).join(",") || "none"}`);
    if (!ws) fail(`workspace present ${p.role_code}`, "login returned no ACTIVE workspace");
  }
  const pw = await passwordLogin(p.phone_e164, DEMO_PASSWORD, tag);
  if (pw.status === 404 || pw.status === 405) warn(`password login ${p.role_code}`, `${pw.status} (endpoint not deployed)`);
  else if (ok2xx(pw) && pw.json?.data?.access_token) pass(`password login ${p.role_code} ${p.phone_e164}`);
  else warn(`password login ${p.role_code} ${p.phone_e164}`, why(pw));
}

// ---------- 3. Portal endpoints ----------
console.log("\n== 3. Portal endpoints per persona");
const PORTAL = {
  CUSTOMER: ["/policies", "/claims", "/mobile/claims", "/mobile/notifications"],
  AGENT: ["/mobile/agent/dashboard", "/mobile/agent/clients", "/mobile/agent/commissions", "/mobile/agent/withdrawals", "/mobile/notifications"],
  BROKER_STAFF: ["/mobile/broker/dashboard", "/mobile/broker/clients", "/mobile/broker/receivables", "/mobile/notifications"],
  CARRIER_STAFF: ["/mobile/carrier/dashboard", "/mobile/carrier/referrals", "/mobile/carrier/issuance", "/mobile/carrier/claims", "/mobile/carrier/settlements", "/mobile/notifications"],
};
const hasPerm = (ws, perm) => (ws?.permissions ?? []).some((x) => x === perm || x === "*");
for (const p of personas) {
  if (!p.token) { fail(`portal ${p.role_code} ${p.phone_e164}`, "skipped: no session"); continue; }
  const paths = [...(PORTAL[p.role_code] ?? [])];
  const isWorkspaceRole = !PORTAL[p.role_code];
  if (isWorkspaceRole || hasPerm(p.workspace, "workspace.read")) paths.push("/mobile/workspace/dashboard");
  for (const path of paths) {
    const r = await call(path, { token: p.token, tenant: p.tenant });
    const name = `${p.role_code} ${p.phone_e164} GET ${path}`;
    if (r.status !== 200) { fail(name, why(r)); continue; }
    if (path === "/mobile/workspace/dashboard") {
      const d = r.json?.data ?? {};
      if (typeof d.label === "string" && d.label) pass(name, `label="${d.label}"`); else fail(name, "200 but no `label` in response");
    } else pass(name);
    if (path === "/mobile/carrier/referrals") p.referrals = listOf(r.json?.data ?? r.json) ?? [];
  }
}

// ---------- 4. Security ----------
console.log("\n== 4. Security (customer token on partner endpoints)");
const customer = personas.find((p) => p.role_code === "CUSTOMER" && p.token);
const FAKE = uuid();
const PARTNER_PATHS = [
  "/mobile/carrier/dashboard", "/mobile/carrier/referrals", `/mobile/carrier/referrals/${FAKE}`, "/mobile/carrier/issuance", "/mobile/carrier/claims",
  "/mobile/carrier/settlements", `/mobile/carrier/settlements/${FAKE}`, "/mobile/carrier/bordereaux", `/mobile/carrier/bordereaux/${FAKE}`,
  "/mobile/broker/dashboard", "/mobile/broker/receivables", "/mobile/broker/commission-accruals", "/mobile/broker/statements", `/mobile/broker/statements/${FAKE}`,
  "/mobile/broker/clients", `/mobile/broker/clients/${FAKE}`, "/mobile/broker/production", "/mobile/broker/renewals", "/mobile/broker/compliance", "/mobile/broker/marketplace-publications",
  "/mobile/workspace/dashboard", "/mobile/workspace/modules/policies",
];
if (!customer) fail("security checks", "no customer session");
else {
  for (const path of PARTNER_PATHS) {
    const r = await call(path, { token: customer.token, tenant: customer.tenant });
    const name = `customer -> GET ${path} expect 403`;
    if (r.status === 403) pass(name);
    else if (r.status === 404 && path.includes(FAKE)) warn(name, "404 (model binding ran before permission check; no data leaked)");
    else fail(name, why(r));
  }
  for (const [path, body] of [[`/mobile/carrier/referrals/${FAKE}/decision`, { decision: "APPROVE", note: "verify-live probe" }], [`/mobile/broker/marketplace-publications/${FAKE}`, { published: false }]]) {
    const method = path.includes("marketplace") ? "PATCH" : "POST";
    const r = await call(path, { method, body, token: customer.token, tenant: customer.tenant });
    if (r.status === 403) pass(`customer -> ${method} ${path} expect 403`);
    else if (r.status === 404) warn(`customer -> ${method} ${path} expect 403`, "404");
    else fail(`customer -> ${method} ${path} expect 403`, why(r));
  }
}
for (const c of personas.filter((p) => p.role_code === "CARRIER_STAFF" && p.referrals)) {
  const keys = ["carrier_id", "carrier_name", "carrier"];
  const vals = new Set(c.referrals.map((row) => j(keys.map((k) => row[k] ?? row.offer?.[k] ?? null))));
  const hasField = c.referrals.some((row) => keys.some((k) => (row[k] ?? row.offer?.[k]) != null));
  if (c.referrals.length === 0) warn(`carrier ${c.phone_e164} referrals scoped to own carrier`, "no referrals to compare");
  else if (!hasField) warn(`carrier ${c.phone_e164} referrals scoped to own carrier`, `${c.referrals.length} rows but payload has no carrier_id/carrier_name field; scoping cannot be checked from the API`);
  else if (vals.size === 1) pass(`carrier ${c.phone_e164} referrals scoped to own carrier`, `${c.referrals.length} rows, ${[...vals][0]}`);
  else fail(`carrier ${c.phone_e164} referrals scoped to own carrier`, `mixed carriers: ${[...vals].join(" | ")}`);
}

// ---------- 5. Rate limit removed ----------
console.log("\n== 5. OTP rate limit removed (25 requests)");
{
  const phone = customer?.phone_e164 ?? demo?.accounts?.[0]?.phone_e164 ?? "+237600000100";
  const bad = [];
  for (let i = 0; i < 25; i++) {
    const r = await call("/auth/mobile/otp/request", { method: "POST", body: { phone, phone_e164: phone } });
    if (!ok2xx(r)) bad.push(`#${i + 1}:${r.status}`);
  }
  if (bad.length === 0) pass(`25 x POST /auth/mobile/otp/request ${phone}`, "all 2xx");
  else fail(`25 x POST /auth/mobile/otp/request ${phone}`, `non-2xx: ${bad.join(" ")}`);
}

// ---------- 6. Registration ----------
console.log("\n== 6. Registration (phone + password, no email)");
{
  const phone = "+2376" + String(Math.floor(Math.random() * 1e8)).padStart(8, "0");
  const password = `Verify@${Math.floor(Math.random() * 1e6)}x`;
  const tag = `reg-${phone.slice(-8)}`;
  const reg = await call("/public/accounts", { method: "POST", body: { phone, phone_e164: phone, password, password_confirmation: password, locale: "en", device_fingerprint: `verify-live-${tag}`, device_name: "OpesInsure android", platform: "android", device: device(tag) } });
  const d = reg.json?.data ?? {};
  if (ok2xx(reg) && d.access_token && d.verification_required === false) pass(`POST /public/accounts ${phone}`, "signed in immediately (verification lifted)");
  else if (ok2xx(reg) && d.verification_required) fail(`POST /public/accounts ${phone}`, `verification still required (channel=${d.verification_channel})`);
  else fail(`POST /public/accounts ${phone}`, why(reg));
  if (ok2xx(reg)) {
    const pw = await passwordLogin(phone, password, tag);
    if (ok2xx(pw) && pw.json?.data?.access_token) pass("password login with new account");
    else fail("password login with new account", why(pw));
    const fg = await call("/auth/mobile/password/forgot", { method: "POST", body: { phone, phone_e164: phone } });
    if (ok2xx(fg)) pass("POST /auth/mobile/password/forgot", `challenge=${fg.json?.data?.challenge_id ?? "?"}`);
    else fail("POST /auth/mobile/password/forgot", why(fg));
  }
}

// ---------- 7. Step-up ----------
console.log("\n== 7. Step-up with demo code");
if (!customer) fail("step-up", "no customer session");
else {
  const purpose = "PAYMENT_REFUND_REQUEST";
  const rq = await call("/mobile/security/step-up/request", { method: "POST", body: { purpose }, token: customer.token, tenant: customer.tenant });
  if (!ok2xx(rq) || !rq.json?.data?.challenge_id) fail("POST /mobile/security/step-up/request", why(rq));
  else {
    pass("POST /mobile/security/step-up/request", `challenge=${rq.json.data.challenge_id}`);
    const vf = await call("/mobile/security/step-up/verify", { method: "POST", body: { challenge_id: rq.json.data.challenge_id, purpose, code: OTP }, token: customer.token, tenant: customer.tenant });
    if (ok2xx(vf) && vf.json?.data?.grant_token) pass(`POST /mobile/security/step-up/verify code ${OTP}`, `grant expires ${vf.json.data.expires_at}`);
    else fail(`POST /mobile/security/step-up/verify code ${OTP}`, why(vf));
  }
}

console.log(`\nSUMMARY: ${results.PASS} passed, ${results.FAIL} failed, ${results.WARN} warnings`);
process.exit(results.FAIL > 0 ? 1 : 0);
