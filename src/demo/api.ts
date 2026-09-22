import * as SecureStore from "expo-secure-store";
import demo from "@/data/demo/opesinsure-cameroon-demo.v1.json";

const state = JSON.parse(JSON.stringify(demo)) as any;
const pollCount: Record<string, number> = {};
const parse = (body: BodyInit | null | undefined) =>
  typeof body === "string" ? JSON.parse(body) : {};
const accountKey = "opesinsure.demo_user_id";
const account = async () => {
  const id = await SecureStore.getItemAsync(accountKey);
  return (
    state.demo_auth.accounts.find((u: any) => u.id === id) ??
    state.demo_auth.accounts[0]
  );
};
const workspaceFor = (user: any) =>
  state.workspaces.find((w: any) => w.id === user.workspace_id);
const bootstrap = (user: any) => ({
  user: {
    id: user.id,
    full_name: user.full_name,
    email: user.email,
    phone_e164: user.phone_e164,
    locale: user.locale,
    status: "ACTIVE",
    phone_verified_at: state.meta.demo_clock,
  },
  workspaces: [workspaceFor(user)],
});
const claim = (id: string) => {
  const item = state.claims.find((c: any) => c.id === id);
  if (!item) throw new Error("Demo claim not found.");
  return {
    ...item,
    evidence: state.claim_evidence.filter((e: any) => e.claim_id === id),
    timeline: state.claim_timeline.filter((e: any) => e.claim_id === id),
  };
};
const moduleRows = (key: string) => {
  const sources = {
    clients: state.customers,
    sales: state.quotes,
    wallet: state.agent_operations.commissions,
    customers: state.customers,
    production: state.policies,
    reports: state.broker_operations.bordereaux,
    referrals: state.carrier_operations.referrals,
    products: state.catalogue.products,
    settlements: state.finance.settlements,
    partners: state.tenants,
    integrations: state.operations.webhook_deliveries,
    compliance: state.risk_and_compliance.compliance_cases,
    claims: state.claims,
    finance: state.finance.ledger_entries,
    fraud: state.risk_and_compliance.fraud_alerts,
    support: state.operations.notifications,
  };
  return (sources as any)[key] ?? [];
};

export async function demoApi<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();
  const body = parse(options.body);
  if (path === "/auth/mobile/otp/request")
    return {
      challenge_id: `demo-${body.phone_e164}`,
      delivery_status: "QUEUED",
      expires_in: 300,
    } as T;
  if (path === "/auth/mobile/otp/verify") {
    const user = state.demo_auth.accounts.find(
      (u: any) => u.phone_e164 === body.phone_e164,
    );
    if (!user || body.code !== state.demo_auth.fixed_otp)
      throw new Error("Use OTP 246810 with a listed demo account.");
    await SecureStore.setItemAsync(accountKey, user.id);
    return {
      access_token: `demo-access-${user.id}`,
      refresh_token: `demo-refresh-${user.id}`,
      expires_in: 3600,
      refresh_expires_in: 2592000,
      session_id: `demo-session-${user.id}`,
      bootstrap: bootstrap(user),
    } as T;
  }
  if (path === "/auth/mobile/session") return bootstrap(await account()) as T;
  if (path === "/auth/mobile/refresh") {
    const user = await account();
    return {
      access_token: `demo-access-${user.id}`,
      refresh_token: `demo-refresh-${user.id}`,
      expires_in: 3600,
      refresh_expires_in: 2592000,
      session_id: `demo-session-${user.id}`,
    } as T;
  }
  if (path === "/auth/mobile/logout") {
    await SecureStore.deleteItemAsync(accountKey);
    return undefined as T;
  }
  if (path === "/public/insurance/verify" && method === "POST")
    return (state.public_verifications.find(
      (v: any) => v.reference === body.reference,
    ) ?? {
      reference: body.reference,
      result: "NOT_FOUND",
      verified_at: state.meta.demo_clock,
    }) as T;
  if (path === "/policies") return { data: state.policies } as T;
  if (/^\/policies\/[^/]+$/.test(path))
    return state.policies.find((p: any) => p.id === path.split("/")[2]) as T;
  if (path.endsWith("/certificate")) {
    const id = path.split("/")[2];
    return (state.certificates.find((c: any) => c.policy_id === id) ?? {
      id: "demo-certificate",
      label: "Demo certificate",
      download_url: "https://example.invalid/demo-certificate.pdf",
      expires_at: state.meta.demo_clock,
    }) as T;
  }
  if (path.endsWith("/renewal-quote"))
    return { quote: state.quotes[0], offers: state.offers } as T;
  if (path.includes("/service-requests"))
    return {
      id: `service-${Date.now()}`,
      policy_id: path.split("/")[2],
      type: body.type,
      status: "SUBMITTED",
      created_at: state.meta.demo_clock,
    } as T;
  if (path === "/claims" && method === "GET")
    return { data: state.claims } as T;
  if (path === "/claims" && method === "POST") {
    const item = {
      id: `claim-${Date.now()}`,
      claim_number: `CLM-DEMO-${state.claims.length + 1}`,
      customer_id: "customer-001",
      status: "REPORTED",
      created_at: state.meta.demo_clock,
      ...body,
    };
    state.claims.unshift(item);
    return item as T;
  }
  if (/^\/claims\/[^/]+$/.test(path)) return claim(path.split("/")[2] ?? "") as T;
  if (path.endsWith("/evidence")) {
    const id = path.split("/")[2];
    const item = {
      id: `evidence-${Date.now()}`,
      claim_id: id,
      type: "DOCUMENT",
      file_name: "uploaded-demo-evidence",
      status: "RECEIVED",
      created_at: state.meta.demo_clock,
    };
    state.claim_evidence.push(item);
    return item as T;
  }
  if (path.endsWith("/declaration")) {
    const item = state.claims.find((c: any) => c.id === path.split("/")[2]);
    item.status = "SUBMITTED";
    return claim(item.id) as T;
  }
  if (path.endsWith("/appeals")) {
    const item = state.claims.find((c: any) => c.id === path.split("/")[2]);
    item.status = "APPEAL_SUBMITTED";
    return claim(item.id) as T;
  }
  if (path === "/quotes" && method === "POST") {
    const item = {
      ...state.quotes[0],
      id: `quote-${Date.now()}`,
      party_id: body.customer_id,
      customer_id: body.customer_id,
      line_code: body.line_code,
      risk_facts: body.risk_facts,
    };
    state.quotes.unshift(item);
    return item as T;
  }
  if (path.endsWith("/rate"))
    return {
      quote:
        state.quotes.find((q: any) => q.id === path.split("/")[2]) ??
        state.quotes[0],
      offers: state.offers,
    } as T;
  if (/^\/quotes\/[^/]+$/.test(path))
    return {
      quote:
        state.quotes.find((q: any) => q.id === path.split("/")[2]) ??
        state.quotes[0],
      offers: state.offers,
    } as T;
  if (path.includes("/offers/") && path.endsWith("/accept"))
    return {
      quote_id: path.split("/")[2],
      offer_id: path.split("/")[4],
      status: "ACCEPTED",
    } as T;
  if (path === "/proposals" && method === "POST")
    return {
      ...state.proposals[0],
      id: `proposal-${Date.now()}`,
      quote_offer_id: body.quote_offer_id,
      party_id: body.party_id,
    } as T;
  if (path === "/payments" && method === "POST") {
    const item = {
      ...state.payments[0],
      id: `payment-${Date.now()}`,
      proposal_id: body.proposal_id,
      provider: body.provider,
      payer_phone_e164: body.payer_phone_e164,
      status: "CREATED",
    };
    state.payments.unshift(item);
    return item as T;
  }
  if (path.endsWith("/initiate")) {
    const item = state.payments.find((p: any) => p.id === path.split("/")[2]);
    item.status = "PENDING_CUSTOMER";
    return item as T;
  }
  if (/^\/payments\/[^/]+$/.test(path)) {
    const item = state.payments.find((p: any) => p.id === path.split("/")[2]);
    const count = (pollCount[item.id] ?? 0) + 1;
    pollCount[item.id] = count;
    if (count >= 2) item.status = "SUCCEEDED";
    return item as T;
  }
  if (path.startsWith("/mobile/purchases/"))
    return {
      status: "POLICY_ISSUED",
      payment:
        state.payments.find((p: any) => p.proposal_id === path.split("/")[3]) ??
        state.payments[1],
      policy: state.policies[0],
    } as T;
  if (path === "/mobile/account/devices") {
    const user = await account();
    return state.operations.devices.filter((d: any) => d.user_id === user.id) as T;
  }
  if (path.startsWith("/mobile/account/devices/") && method === "DELETE") {
    state.operations.devices = state.operations.devices.filter(
      (d: any) => d.id !== path.split("/").at(-1),
    );
    return undefined as T;
  }
  if (path === "/mobile/account/notification-preferences")
    return (
      body.push === undefined
        ? {
            push: true,
            sms: true,
            email: true,
            renewals: true,
            claims: true,
            payments: true,
          }
        : body
    ) as T;
  if (
    [
      "/mobile/account/profile",
      "/mobile/account/locale",
      "/mobile/account/push-tokens",
    ].includes(path)
  )
    return body as T;
  if (path === "/mobile/workspace/dashboard") {
    const user = await account();
    return (state.workspace_dashboards[user.role_code] ??
      state.workspace_dashboards.SYSTEM_ADMIN) as T;
  }
  if (path.startsWith("/mobile/workspace/modules/")) {
    const key = decodeURIComponent(path.split("/").at(-1) ?? "");
    const rows = moduleRows(key);
    const columns = rows.length ? Object.keys(rows[0]).slice(0, 5) : [];
    return {
      title: key.replaceAll("_", " ").replace(/^./, (c) => c.toUpperCase()),
      columns,
      rows,
    } as T;
  }
  throw new Error(`Demo adapter has no fixture for ${method} ${path}`);
}
