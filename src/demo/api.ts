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
  const enrichPolicy = (policy: any) => ({
    ...policy,
    carrier_name: "Demo Cameroon Assurance",
    product_name: "Automobile Responsabilité Civile",
    documents:
      state.policy_documents?.filter((d: any) => d.policy_id === policy.id) ??
      [],
    delivery:
      state.sticker_deliveries?.find((d: any) => d.policy_id === policy.id) ??
      null,
  });
  const supportCase = (id: string) =>
    state.support_cases.find((x: any) => x.id === id);
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
  if (path.startsWith("/mobile/runtime/bootstrap"))
    return {
      release: {
        minimum_version: "1.0.0",
        force_update: false,
        store_url: null,
      },
      maintenance: { active: false, message: null, ends_at: null },
      services: [
        { key: "LARAVEL_API", status: "OPERATIONAL" },
        { key: "MTN_MOMO", status: "OPERATIONAL" },
        { key: "ORANGE_MONEY", status: "DEGRADED", message: "Demo delayed webhook scenario" },
        { key: "CARRIER_GATEWAY", status: "OPERATIONAL" },
        { key: "SMS", status: "OPERATIONAL" },
      ],
      security: { step_up_ttl_seconds: 300, device_risk_action: "ALLOW" },
    } as T;
  if (path === "/mobile/runtime/telemetry") return undefined as T;
  if (path === "/mobile/security/step-up/request")
    return {
      challenge_id: `step-up-${Date.now()}`,
      delivery_hint: "Demo OTP · 246810",
      expires_in: 300,
    } as T;
  if (path === "/mobile/security/step-up/verify") {
    if (body.code !== state.demo_auth.fixed_otp)
      throw new Error("Use demo step-up code 246810.");
    return {
      grant_token: `demo-step-up-${body.purpose}`,
      purpose: body.purpose,
      expires_at: new Date(Date.now() + 300000).toISOString(),
    } as T;
  }
  if (path === "/mobile/security/device-attestation/nonce")
    return {
      nonce: `demo-device-nonce-${Date.now()}`,
      expires_at: new Date(Date.now() + 300000).toISOString(),
    } as T;
  if (path === "/mobile/security/device-attestation/assess")
    return {
      assessment_id: `demo-assessment-${Date.now()}`,
      action: body.provider === "UNAVAILABLE_MANAGED_RUNTIME" ? "LIMIT" : "ALLOW",
      reasons:
        body.provider === "UNAVAILABLE_MANAGED_RUNTIME"
          ? ["NATIVE_ATTESTATION_NOT_AVAILABLE_IN_DEMO"]
          : [],
      expires_at: new Date(Date.now() + 3600000).toISOString(),
    } as T;
  if (path === "/mobile/sync/status")
    return {
      server_time: state.meta.demo_clock,
      minimum_client_version: "1.0.0",
    } as T;
  if (path === "/mobile/sync/operations" && method === "POST")
    return {
      operation_id: body.id,
      status: "APPLIED",
      server_version: (body.payload?.version ?? 0) + 1,
      synchronized_at: state.meta.demo_clock,
    } as T;
  if (path === "/public/insurance/verify" && method === "POST")
    return (state.public_verifications.find(
      (v: any) => v.reference === body.reference,
    ) ?? {
      reference: body.reference,
      result: "NOT_FOUND",
      verified_at: state.meta.demo_clock,
    }) as T;
  if (path === "/mobile/broker/dashboard")
    return state.broker_mobile.dashboard as T;
  if (path === "/mobile/broker/clients")
    return state.broker_mobile.clients as T;
  if (/^\/mobile\/broker\/clients\/[^/]+$/.test(path))
    return state.broker_mobile.clients.find(
      (x: any) => x.id === path.split("/")[4],
    ) as T;
  if (path === "/mobile/broker/production")
    return state.broker_mobile.production as T;
  if (path === "/mobile/broker/renewals")
    return state.broker_mobile.renewals as T;
  if (path === "/mobile/broker/receivables")
    return state.broker_mobile.receivables as T;
  if (path === "/mobile/broker/compliance")
    return state.broker_mobile.compliance as T;
  if (path === "/mobile/broker/marketplace-publications")
    return state.broker_mobile.publications as T;
  if (/^\/mobile\/broker\/marketplace-publications\/[^/]+$/.test(path)) {
    const item = state.broker_mobile.publications.find(
      (x: any) => x.id === path.split("/")[4],
    );
    item.status = body.enabled ? "PENDING_APPROVAL" : "UNPUBLISHED";
    return item as T;
  }
  if (path === "/mobile/carrier/dashboard")
    return state.carrier_mobile.dashboard as T;
  if (path === "/mobile/carrier/referrals")
    return state.carrier_mobile.referrals as T;
  if (
    path.endsWith("/decision") &&
    path.startsWith("/mobile/carrier/referrals/")
  ) {
    const item = state.carrier_mobile.referrals.find(
      (x: any) => x.id === path.split("/")[4],
    );
    item.status =
      body.decision === "APPROVE"
        ? "APPROVED"
        : body.decision === "DECLINE"
          ? "DECLINED"
          : "INFORMATION_REQUESTED";
    item.decision_note = body.note;
    return item as T;
  }
  if (/^\/mobile\/carrier\/referrals\/[^/]+$/.test(path))
    return state.carrier_mobile.referrals.find(
      (x: any) => x.id === path.split("/")[4],
    ) as T;
  if (path === "/mobile/carrier/issuance")
    return state.carrier_mobile.issuance as T;
  if (path === "/mobile/carrier/claims")
    return state.carrier_mobile.claims as T;
  if (path === "/mobile/carrier/settlements")
    return state.carrier_mobile.settlements as T;
  if (path === "/mobile/agent/profile") {
    if (method === "PATCH") Object.assign(state.agent_mobile.profile, body);
    return state.agent_mobile.profile as T;
  }
  if (path === "/mobile/agent/dashboard")
    return state.agent_mobile.dashboard as T;
  if (path === "/mobile/agent/clients" && method === "GET")
    return state.agent_mobile.clients as T;
  if (path === "/mobile/agent/clients" && method === "POST") {
    const existing = state.agent_mobile.clients.find(
      (x: any) => x.phone_e164 === body.phone_e164,
    );
    if (existing) return { ...existing, duplicate_detected: true } as T;
    const item = {
      id: `agent-client-${Date.now()}`,
      kyc_status: "NOT_STARTED",
      origin_locked: true,
      active_policies: 0,
      renewal_due_at: null,
      ...body,
    };
    state.agent_mobile.clients.unshift(item);
    return item as T;
  }
  if (/^\/mobile\/agent\/clients\/[^/]+$/.test(path))
    return state.agent_mobile.clients.find(
      (x: any) => x.id === path.split("/")[4],
    ) as T;
  if (path === "/mobile/agent/sales" && method === "POST") {
    const client = state.agent_mobile.clients.find(
      (x: any) => x.id === body.customer_id,
    );
    const item = {
      id: `agent-sale-${Date.now()}`,
      customer_name: client?.full_name ?? "Client",
      status: "QUOTE_READY",
      premium_minor: 7080000,
      currency: "XAF",
      payment_status: "NOT_REQUESTED",
      commission_minor: 420000,
      created_at: state.meta.demo_clock,
      ...body,
    };
    state.agent_mobile.sales.unshift(item);
    return item as T;
  }
  if (
    path.endsWith("/payment-request") &&
    path.startsWith("/mobile/agent/sales/")
  ) {
    const item = state.agent_mobile.sales.find(
      (x: any) => x.id === path.split("/")[4],
    );
    item.payment_status = "PENDING_CLIENT";
    item.status = "PAYMENT_REQUESTED";
    return item as T;
  }
  if (/^\/mobile\/agent\/sales\/[^/]+$/.test(path))
    return state.agent_mobile.sales.find(
      (x: any) => x.id === path.split("/")[4],
    ) as T;
  if (path === "/mobile/agent/renewals")
    return state.agent_mobile.renewals as T;
  if (path === "/mobile/agent/commissions")
    return state.agent_operations.commissions as T;
  if (path === "/mobile/agent/withdrawals" && method === "GET")
    return state.agent_operations.withdrawals as T;
  if (path === "/mobile/agent/withdrawals" && method === "POST") {
    const item = {
      id: `withdrawal-${Date.now()}`,
      status: "PENDING_APPROVAL",
      requested_at: state.meta.demo_clock,
      ...body,
    };
    state.agent_operations.withdrawals.unshift(item);
    return item as T;
  }
  if (path === "/mobile/agent/offline-queue")
    return state.agent_mobile.offline_queue as T;
  if (
    path.endsWith("/retry") &&
    path.startsWith("/mobile/agent/offline-queue/")
  ) {
    const item = state.agent_mobile.offline_queue.find(
      (x: any) => x.id === path.split("/")[4],
    );
    item.status = "QUEUED";
    delete item.error;
    return item as T;
  }
  if (path === "/mobile/claims/emergency-assistance")
    return {
      id: `assistance-${Date.now()}`,
      status: "DISPATCH_REQUESTED",
      reference: "OI-HELP-DEMO-001",
    } as T;
  if (/^\/mobile\/claims\/[^/]+\/incident$/.test(path)) {
    const item = state.claim_incidents.find(
      (x: any) => x.claim_id === path.split("/")[3],
    );
    if (method === "PUT") Object.assign(item, body);
    return item as T;
  }
  if (/^\/mobile\/claims\/[^/]+\/parties$/.test(path)) {
    const claimId = path.split("/")[3];
    if (method === "GET")
      return state.claim_parties.filter(
        (x: any) => x.claim_id === claimId,
      ) as T;
    const item = { id: `party-${Date.now()}`, claim_id: claimId, ...body };
    state.claim_parties.push(item);
    return item as T;
  }
  if (/^\/mobile\/claims\/[^/]+\/evidence-requirements$/.test(path))
    return state.claim_evidence_requirements.filter(
      (x: any) => x.claim_id === path.split("/")[3],
    ) as T;
  if (/^\/mobile\/claims\/[^/]+\/inspection$/.test(path))
    return state.claim_inspections.find(
      (x: any) => x.claim_id === path.split("/")[3],
    ) as T;
  if (path.endsWith("/inspection/reschedule")) {
    const item = state.claim_inspections.find(
      (x: any) => x.claim_id === path.split("/")[3],
    );
    item.appointment_at = body.appointment_at;
    item.status = "RESCHEDULED";
    return item as T;
  }
  if (/^\/mobile\/claims\/[^/]+\/repair$/.test(path))
    return state.claim_repairs.find(
      (x: any) => x.claim_id === path.split("/")[3],
    ) as T;
  if (/^\/mobile\/claims\/[^/]+\/settlement$/.test(path))
    return state.claim_settlements.find(
      (x: any) => x.claim_id === path.split("/")[3],
    ) as T;
  if (path.endsWith("/settlement/decision")) {
    const item = state.claim_settlements.find(
      (x: any) => x.claim_id === path.split("/")[3],
    );
    item.status =
      body.decision === "ACCEPT" ? "ACCEPTED" : "REJECTED_BY_CUSTOMER";
    if (body.decision === "ACCEPT") item.payment_status = "QUEUED";
    return item as T;
  }
  if (path === "/mobile/quotes") return state.quote_history as T;
  if (/^\/mobile\/quotes\/[^/]+$/.test(path) && method === "GET") {
    const id = path.split("/")[3];
    return {
      summary: state.quote_history.find((q: any) => q.id === id),
      quote: state.quotes.find((q: any) => q.id === id) ?? state.quotes[0],
      offers: state.offers.filter(
        (o: any) => o.quote_id === id || id === "quote-001",
      ),
    } as T;
  }
  if (path.endsWith("/resume"))
    return { quote_id: path.split("/")[3], next_path: "/quote/offers" } as T;
  if (/^\/mobile\/quotes\/[^/]+$/.test(path) && method === "DELETE") {
    state.quote_history = state.quote_history.filter(
      (q: any) => q.id !== path.split("/")[3],
    );
    return undefined as T;
  }
  if (path.startsWith("/mobile/documents")) {
    const clean = path.split("?")[0] ?? path;
    const parts = clean.split("/");
    if (parts.length === 3) return state.secure_documents as T;
    const doc =
      state.secure_documents.find((d: any) => d.id === parts[3]) ??
      (() => {
        const p = state.policy_documents.find((d: any) => d.id === parts[3]);
        return p
          ? {
              ...p,
              owner_type: "POLICY",
              owner_id: p.policy_id,
              mime_type: "application/pdf",
              status: "AVAILABLE",
              issued_at: state.meta.demo_clock,
              share_reference: p.id,
            }
          : undefined;
      })();
    if (clean.endsWith("/access"))
      return {
        ...doc,
        signed_url: "https://example.invalid/secure/demo-document.pdf",
      } as T;
    return doc as T;
  }
  if (path.startsWith("/mobile/policy-service-requests")) {
    const clean = path.split("?")[0] ?? path;
    const parts = clean.split("/");
    if (parts.length === 3 && method === "GET")
      return state.policy_service_cases as T;
    if (parts.length === 3 && method === "POST") {
      const item = {
        id: `service-${Date.now()}`,
        status: "SUBMITTED",
        created_at: state.meta.demo_clock,
        updated_at: state.meta.demo_clock,
        timeline: [
          {
            id: "event-created",
            label: "Request submitted",
            occurred_at: state.meta.demo_clock,
          },
        ],
        ...body,
      };
      state.policy_service_cases.unshift(item);
      return item as T;
    }
    const item = state.policy_service_cases.find((x: any) => x.id === parts[3]);
    if (clean.endsWith("/messages")) {
      item.timeline.push({
        id: `event-${Date.now()}`,
        label: "Customer message",
        description: body.message,
        occurred_at: state.meta.demo_clock,
      });
      return item as T;
    }
    return item as T;
  }
  if (path === "/mobile/notifications")
    return state.customer_notifications as T;
  if (path === "/mobile/notifications/read-all") {
    state.customer_notifications.forEach((n: any) => (n.read = true));
    return { updated: state.customer_notifications.length } as T;
  }
  if (path.endsWith("/read") && path.startsWith("/mobile/notifications/")) {
    const n = state.customer_notifications.find(
      (x: any) => x.id === path.split("/")[3],
    );
    n.read = true;
    return n as T;
  }
  if (/^\/mobile\/notifications\/[^/]+$/.test(path))
    return state.customer_notifications.find(
      (x: any) => x.id === path.split("/")[3],
    ) as T;
  if (path === "/mobile/support/cases" && method === "GET")
    return state.support_cases as T;
  if (path === "/mobile/support/cases" && method === "POST") {
    const item = {
      id: `support-${Date.now()}`,
      reference: `OI-SUP-${state.support_cases.length + 1}`,
      status: "OPEN",
      priority: "NORMAL",
      created_at: state.meta.demo_clock,
      updated_at: state.meta.demo_clock,
      messages: [],
      attachments: [],
      ...body,
    };
    state.support_cases.unshift(item);
    return item as T;
  }
  if (/^\/mobile\/support\/cases\/[^/]+$/.test(path))
    return supportCase(path.split("/")[4] ?? "") as T;
  if (path.endsWith("/messages") && path.startsWith("/mobile/support/cases/")) {
    const item = supportCase(path.split("/")[4] ?? "");
    item.messages.push({
      id: `message-${Date.now()}`,
      sender: "CUSTOMER",
      body: body.body,
      created_at: state.meta.demo_clock,
    });
    return item as T;
  }
  if (
    path.endsWith("/attachments") &&
    path.startsWith("/mobile/support/cases/")
  ) {
    const item = supportCase(path.split("/")[4] ?? "");
    item.attachments.push({
      id: `attachment-${Date.now()}`,
      file_name: "customer-attachment.pdf",
      status: "RECEIVED",
    });
    return item as T;
  }
  if (path === "/mobile/kyc/profile") {
    if (method === "PATCH")
      state.kyc_profiles[0] = { ...state.kyc_profiles[0], ...body };
    return state.kyc_profiles[0] as T;
  }
  if (path === "/mobile/kyc/documents")
    return { id: `kyc-document-${Date.now()}`, status: "RECEIVED" } as T;
  if (path === "/mobile/kyc/submission") {
    state.kyc_profiles[0].status = "PENDING_REVIEW";
    return state.kyc_profiles[0] as T;
  }
  if (path === "/mobile/assets" && method === "GET")
    return state.risk_assets as T;
  if (path === "/mobile/assets" && method === "POST") {
    const item = {
      id: `asset-${Date.now()}`,
      type: "VEHICLE",
      status: "DRAFT",
      ...body,
    };
    state.risk_assets.unshift(item);
    return item as T;
  }
  if (/^\/mobile\/assets\/[^/]+$/.test(path))
    return state.risk_assets.find((a: any) => a.id === path.split("/")[3]) as T;
  if (path.endsWith("/documents")) {
    const id = path.split("/")[3];
    const doc = {
      id: `asset-document-${Date.now()}`,
      asset_id: id,
      type: "REGISTRATION_CARD",
      file_name: "registration-card.jpg",
      status: "SCANNED",
      extracted_fields: {
        registration_number: "LT 245 AB",
        make: "TOYOTA",
        model: "Corolla",
        year: "2019",
      },
    };
    state.asset_documents.push(doc);
    return doc as T;
  }
  if (path.endsWith("/scan"))
    return state.asset_documents.find(
      (d: any) => d.asset_id === path.split("/")[3],
    ) as T;
  if (path.includes("/scan/") && path.endsWith("/confirm")) {
    const asset = state.risk_assets.find(
      (a: any) => a.id === path.split("/")[3],
    );
    Object.assign(asset, body.fields, { status: "VERIFIED" });
    return asset as T;
  }
  if (/^\/proposals\/[^/]+\/disclosure$/.test(path))
    return state.disclosure_sessions[0] as T;
  if (path.endsWith("/disclosure/answers")) {
    state.disclosure_sessions[0].questions =
      state.disclosure_sessions[0].questions.map((q: any) => ({
        ...q,
        answer: body.answers[q.id],
      }));
    state.disclosure_sessions[0].status = "IN_PROGRESS";
    return state.disclosure_sessions[0] as T;
  }
  if (path.endsWith("/disclosure/submit")) {
    state.disclosure_sessions[0].status =
      state.disclosure_sessions[0].questions.some(
        (q: any) => q.id === "commercial_use" && q.answer === true,
      )
        ? "REFERRED"
        : "APPROVED";
    return state.disclosure_sessions[0] as T;
  }
  if (path.endsWith("/terms"))
    return { accepted: body.accepted, accepted_at: state.meta.demo_clock } as T;
  if (path === "/mobile/payments") return state.payments as T;
  if (/^\/mobile\/payments\/[^/]+$/.test(path))
    return state.payments.find((p: any) => p.id === path.split("/")[3]) as T;
  if (path.endsWith("/retry")) {
    const p = state.payments.find((x: any) => x.id === path.split("/")[3]);
    p.status = "PENDING_CUSTOMER";
    return p as T;
  }
  if (path.endsWith("/receipt"))
    return state.payment_receipts.find(
      (r: any) => r.payment_id === path.split("/")[3],
    ) as T;
  if (path.endsWith("/refunds")) {
    const item = {
      id: `refund-${Date.now()}`,
      payment_id: path.split("/")[3],
      reason: body.reason,
      status: "REQUESTED",
      created_at: state.meta.demo_clock,
    };
    state.refund_requests.push(item);
    return item as T;
  }
  if (path === "/mobile/wallet") return state.policies.map(enrichPolicy) as T;
  if (/^\/mobile\/wallet\/policies\/[^/]+$/.test(path))
    return enrichPolicy(
      state.policies.find((p: any) => p.id === path.split("/")[4]),
    ) as T;
  if (/^\/mobile\/deliveries\/[^/]+$/.test(path))
    return state.sticker_deliveries.find(
      (d: any) => d.id === path.split("/")[3],
    ) as T;
  if (path.endsWith("/address")) {
    const d = state.sticker_deliveries.find(
      (x: any) => x.id === path.split("/")[3],
    );
    Object.assign(d, body);
    return d as T;
  }
  if (path.endsWith("/confirm")) {
    const d = state.sticker_deliveries.find(
      (x: any) => x.id === path.split("/")[3],
    );
    d.status = body.otp === "246810" ? "DELIVERED" : d.status;
    return d as T;
  }
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
    state.claim_incidents.push({
      claim_id: item.id,
      incident_type: "COLLISION",
      police_report_number: null,
      latitude: null,
      longitude: null,
      injuries_reported: false,
      vehicle_drivable: true,
      towing_required: false,
      declaration_confirmed: false,
    });
    state.claim_evidence_requirements.push(
      {
        claim_id: item.id,
        key: "vehicle_wide",
        label: "Wide-angle vehicle photos",
        required: true,
        status: "MISSING",
        guidance: "Capture all four sides in daylight.",
      },
      {
        claim_id: item.id,
        key: "damage_closeup",
        label: "Damage close-ups",
        required: true,
        status: "MISSING",
        guidance: "Include surrounding undamaged panels.",
      },
    );
    return item as T;
  }
  if (/^\/claims\/[^/]+$/.test(path))
    return claim(path.split("/")[2] ?? "") as T;
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
    return state.operations.devices.filter(
      (d: any) => d.user_id === user.id,
    ) as T;
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
