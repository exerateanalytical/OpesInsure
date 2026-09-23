// Customer journey exactly as the app sequences it:
// quote -> rate -> accept offer -> proposal -> disclosure -> terms -> payment -> initiate -> purchase status -> wallet -> claims.
const BASE = process.env.API_BASE ?? "https://insurance.opesdatacenter.tech/api/v1";
const j = (o) => JSON.stringify(o);
const uuid = () => crypto.randomUUID();
let token, tenant;
async function call(path, { method = "GET", body } = {}) {
  const res = await fetch(BASE + path, { method, headers: { Accept: "application/json", "Content-Type": "application/json", "X-Request-ID": uuid(), "Idempotency-Key": uuid(), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(tenant ? { "X-Tenant-Id": tenant } : {}) }, body });
  const text = await res.text(); let json = null; try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text };
}
function step(name, r, okStatuses = [200, 201, 202]) {
  const ok = okStatuses.includes(r.status);
  console.log(`${ok ? "OK " : "ERR"} ${r.status} ${name}${ok ? "" : " :: " + (r.json?.message ?? r.text.slice(0, 300))}`);
  if (!ok) { process.exit(1); }
  return r.json?.data;
}
const demo = (await call("/public/demo-accounts")).json.data;
const phone = demo.accounts.find((a) => a.role_code === "CUSTOMER").phone_e164;
const req = step("otp request", await call("/auth/mobile/otp/request", { method: "POST", body: j({ phone_e164: phone }) }));
const auth = step("otp verify", await call("/auth/mobile/otp/verify", { method: "POST", body: j({ challenge_id: req.challenge_id, phone_e164: phone, code: demo.otp, device: { fingerprint: "journey", name: "journey", platform: "android" } }) }));
token = auth.access_token; tenant = auth.workspaces[0].tenant_id;
const customerId = auth.workspaces[0].customer_id;
console.log("   workspace customer_id:", customerId);

const quote = step("POST /quotes (MOTOR)", await call("/quotes", { method: "POST", body: j({ customer_id: customerId, line_code: "MOTOR", channel: "B2C", risk_facts: { registration_number: "LT 900 ZZ", fiscal_power: 8, usage_type: "PRIVATE", zone: "CAMEROON" } }) }));
const rated = step("POST /quotes/{id}/rate", await call(`/quotes/${quote.id}/rate`, { method: "POST" }));
console.log(`   offers: ${rated.offers.length} -> ${rated.offers.map((o) => `${o.carrier?.party?.display_name ?? "?"} / ${o.product?.name ?? "?"} = ${o.total_minor / 100} FCFA`).join(" | ")}`);
const offer = rated.offers[0];
step("accept offer", await call(`/quotes/${quote.id}/offers/${offer.id}/accept`, { method: "POST" }));
const proposal = step("POST /proposals", await call("/proposals", { method: "POST", body: j({ quote_offer_id: offer.id, party_id: rated.quote.party_id }) }));
const session = step("GET disclosure session", await call(`/proposals/${proposal.id}/disclosure`));
console.log(`   questions: ${session.questions.map((q) => q.id).join(",")}`);
step("PUT disclosure answers", await call(`/proposals/${proposal.id}/disclosure/answers`, { method: "PUT", body: j({ answers: Object.fromEntries(session.questions.map((q) => [q.id, q.id === "licence_valid"])) }) }));
step("POST disclosure submit", await call(`/proposals/${proposal.id}/disclosure/submit`, { method: "POST" }));
const terms = step("POST terms accepted", await call(`/proposals/${proposal.id}/terms`, { method: "POST", body: j({ accepted: true }) }));
console.log("   proposal status after terms:", terms.status);
const payment = step("POST /payments (mtn_momo)", await call("/payments", { method: "POST", body: j({ proposal_id: proposal.id, provider: "mtn_momo", payer_phone_e164: phone, idempotency_key: uuid() + uuid() }) }));
const initiated = step("POST /payments/{id}/initiate", await call(`/payments/${payment.id}/initiate`, { method: "POST" }));
console.log("   payment status:", initiated.status);
let status;
for (let i = 0; i < 8; i++) {
  status = step(`GET purchase status (poll ${i + 1})`, await call(`/mobile/purchases/${proposal.id}/status`));
  console.log("   ->", status.status, status.payment?.status, status.policy?.policy_number ?? "");
  if (status.policy) break;
  await new Promise((r) => setTimeout(r, 6000));
}
if (!status.policy) { console.log("ERR policy never issued"); process.exit(1); }
const wallet = step("GET /mobile/wallet", await call("/mobile/wallet"));
console.log("   wallet policies:", (wallet.data ?? wallet).length);
const policy = step("GET /policies/{id}", await call(`/policies/${status.policy.id}`));
const claims = step("GET /mobile/claims", await call("/mobile/claims"));
console.log("   claims:", (claims.data ?? claims).length, (claims.data ?? claims).map((c) => `${c.claim_number}:${c.status}:${c.incident_at ? "incident_at ok" : "NO incident_at"}`).join(" | "));
const fnol = step("POST /mobile/claims (FNOL)", await call("/mobile/claims", { method: "POST", body: j({ policy_id: status.policy.id, incident_at: new Date(Date.now() - 3600e3).toISOString(), incident_location: "Akwa, Douala", description: "Minor collision in a car park, scratched rear door." }) }));
step("GET claim incident", await call(`/mobile/claims/${fnol.id}/incident`));
step("PUT claim incident", await call(`/mobile/claims/${fnol.id}/incident`, { method: "PUT", body: j({ incident_type: "COLLISION", declaration_confirmed: true }) }));
step("GET evidence requirements", await call(`/mobile/claims/${fnol.id}/evidence-requirements`));
step("GET claim timeline", await call(`/mobile/claims/${fnol.id}/timeline`));
step("GET notifications", await call("/mobile/notifications"));
const svc = step("POST service request", await call("/mobile/policy-service-requests", { method: "POST", body: j({ policy_id: status.policy.id, type: "ENDORSEMENT", reason: "Please add my spouse as a named driver." }) }));
step("GET service request", await call(`/mobile/policy-service-requests/${svc.id}`));
const sup = step("POST support case", await call("/mobile/support/cases", { method: "POST", body: j({ category: "BILLING", subject: "Receipt request", description: "Please send me the receipt for my last payment." }) }));
step("POST support reply", await call(`/mobile/support/cases/${sup.id}/messages`, { method: "POST", body: j({ body: "Thanks!" }) }));
const renewal = step("POST renewal quote (existing policy)", await call(`/policies/${(wallet.data ?? wallet).find((p) => p.policy_number === "POL-2025-004417")?.id ?? status.policy.id}/renewal-quote`, { method: "POST" }));
console.log("   renewal offers:", renewal.offers.length);
console.log("\nJOURNEY COMPLETE");
