// Logs in as every server demo persona exactly like the app does, then hits
// every screen-load GET the app makes and prints the real status/error.
const BASE = process.env.API_BASE ?? "https://insurance.opesdatacenter.tech/api/v1";
const j = (o) => JSON.stringify(o);
const uuid = () => crypto.randomUUID();

async function call(path, { method = "GET", body, token, tenant } = {}) {
  const res = await fetch(BASE + path, {
    method,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-Request-ID": uuid(),
      "Idempotency-Key": uuid(),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(tenant ? { "X-Tenant-Id": tenant } : {}),
    },
    body,
  });
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); } catch { json = null; }
  return { status: res.status, json, text };
}

const SCREEN_GETS = [
  ["session", "/auth/mobile/session"],
  ["home/policies (usePolicies)", "/policies"],
  ["claims tab", "/claims"],
  ["account/devices", "/mobile/account/devices"],
  ["account/notifications", "/mobile/account/notification-preferences"],
  ["assets", "/mobile/assets"],
  ["payments", "/mobile/payments"],
  ["wallet", "/mobile/wallet"],
  ["quotes history", "/mobile/quotes"],
  ["notifications", "/mobile/notifications"],
  ["support cases", "/mobile/support/cases"],
  ["policy service requests", "/mobile/policy-service-requests"],
  ["kyc profile", "/mobile/kyc/profile"],
  ["documents", "/mobile/documents"],
  ["sync status", "/mobile/sync/status"],
  ["workspace dashboard", "/mobile/workspace/dashboard"],
  ["agent dashboard", "/mobile/agent/dashboard"],
  ["agent clients", "/mobile/agent/clients"],
  ["agent profile", "/mobile/agent/profile"],
  ["agent renewals", "/mobile/agent/renewals"],
  ["agent commissions", "/mobile/agent/commissions"],
  ["agent offline queue", "/mobile/agent/offline-queue"],
  ["broker dashboard", "/mobile/broker/dashboard"],
  ["broker clients", "/mobile/broker/clients"],
  ["broker production", "/mobile/broker/production"],
  ["broker renewals", "/mobile/broker/renewals"],
  ["broker receivables", "/mobile/broker/receivables"],
  ["broker compliance", "/mobile/broker/compliance"],
  ["broker publications", "/mobile/broker/marketplace-publications"],
  ["carrier dashboard", "/mobile/carrier/dashboard"],
  ["carrier referrals", "/mobile/carrier/referrals"],
  ["carrier issuance", "/mobile/carrier/issuance"],
  ["carrier claims", "/mobile/carrier/claims"],
  ["carrier settlements", "/mobile/carrier/settlements"],
];

const demo = (await call("/public/demo-accounts")).json.data;
const results = {};
const ONLY = (process.env.ONLY ?? "").split(",").filter(Boolean);
for (const account of demo.accounts) {
  if (ONLY.length && !ONLY.includes(account.role_code)) continue;
  const req = await call("/auth/mobile/otp/request", { method: "POST", body: j({ phone_e164: account.phone_e164 }) });
  if (req.status !== 200) { console.log(`\n## ${account.label}: OTP request failed ${req.status} ${req.text.slice(0, 200)}`); continue; }
  const ver = await call("/auth/mobile/otp/verify", {
    method: "POST",
    body: j({ challenge_id: req.json.data.challenge_id, phone_e164: account.phone_e164, code: demo.otp,
      device: { fingerprint: "audit-" + account.role_code, name: "audit", platform: "android" } }),
  });
  if (ver.status !== 201) { console.log(`\n## ${account.label}: verify failed ${ver.status} ${ver.text.slice(0, 300)}`); continue; }
  const token = ver.json.data.access_token;
  const ws = ver.json.data.workspaces ?? [];
  const tenant = ws[0]?.tenant_id;
  console.log(`\n## ${account.label} (${account.role_code}) workspaces=${ws.map(w => w.role_code + "@" + w.tenant_id.slice(0, 8)).join(",") || "NONE"}`);
  for (const [name, path] of SCREEN_GETS) {
    const r = await call(path, { token, tenant });
    const ok = r.status >= 200 && r.status < 300;
    const msg = ok ? "" : (r.json?.message ?? r.json?.error?.message ?? r.text.slice(0, 160).replace(/\s+/g, " "));
    const count = ok && r.json?.data ? (Array.isArray(r.json.data) ? `items=${r.json.data.length}` : `keys=${Object.keys(r.json.data).slice(0, 6).join("|")}`) : "";
    console.log(`${ok ? "OK " : "ERR"} ${String(r.status).padEnd(3)} ${name.padEnd(28)} ${path.padEnd(42)} ${count} ${msg}`);
    results[`${account.role_code} ${path}`] = r.status;
  }
}
