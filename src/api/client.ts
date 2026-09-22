import * as Crypto from "expo-crypto";
import * as SecureStore from "expo-secure-store";
import { Platform } from "react-native";
import { demoApi } from "@/demo/api";

const configuredUrl = process.env.EXPO_PUBLIC_API_BASE_URL;
const API_URL = configuredUrl ?? (__DEV__ ? "http://10.0.2.2:8000/api/v1" : "");
const keys = {
  access: "opesinsure.access_token",
  refresh: "opesinsure.refresh_token",
  tenant: "opesinsure.tenant_id",
  device: "opesinsure.device_id",
  payment: "opesinsure.pending_payment_id",
};
export type ApiFieldErrors = Record<string, string[]>;
export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public fields?: ApiFieldErrors,
    public retryAfter?: number,
  ) {
    super(message);
    this.name = "ApiError";
  }
}
export class ApiConfigurationError extends Error {}
type Envelope<T> = { data: T; meta?: Record<string, unknown> };
type Options = RequestInit & {
  idempotent?: boolean;
  timeoutMs?: number;
  anonymous?: boolean;
  retryAuth?: boolean;
};
let refreshPromise: Promise<boolean> | null = null;

export const TokenVault = {
  async save(access: string, refresh: string) {
    await Promise.all([
      SecureStore.setItemAsync(keys.access, access),
      SecureStore.setItemAsync(keys.refresh, refresh),
    ]);
  },
  async access() {
    return SecureStore.getItemAsync(keys.access);
  },
  async refresh() {
    return SecureStore.getItemAsync(keys.refresh);
  },
  async setTenant(id: string) {
    await SecureStore.setItemAsync(keys.tenant, id);
  },
  async tenant() {
    return SecureStore.getItemAsync(keys.tenant);
  },
  async clear() {
    await Promise.all([
      SecureStore.deleteItemAsync(keys.access),
      SecureStore.deleteItemAsync(keys.refresh),
      SecureStore.deleteItemAsync(keys.tenant),
    ]);
  },
  async setPendingPayment(id: string) {
    await SecureStore.setItemAsync(keys.payment, id);
  },
  async pendingPayment() {
    return SecureStore.getItemAsync(keys.payment);
  },
  async clearPendingPayment() {
    await SecureStore.deleteItemAsync(keys.payment);
  },
  async deviceId() {
    let id = await SecureStore.getItemAsync(keys.device);
    if (!id) {
      id = Crypto.randomUUID();
      await SecureStore.setItemAsync(keys.device, id);
    }
    return id;
  },
};
function toError(status: number, payload: any, response: Response) {
  const first = payload?.errors?.[0];
  return new ApiError(
    status,
    first?.code ?? payload?.code ?? "REQUEST_FAILED",
    first?.detail ?? payload?.message ?? "The request could not be completed.",
    first?.meta?.fields ?? (status === 422 ? payload?.errors : undefined),
    Number(response.headers.get("Retry-After") || 0) || undefined,
  );
}
async function rotate() {
  if (refreshPromise) return refreshPromise;
  refreshPromise = (async () => {
    const refresh = await TokenVault.refresh();
    if (!refresh || !API_URL) return false;
    try {
      const response = await fetch(`${API_URL}/auth/mobile/refresh`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          refresh_token: refresh,
          device_fingerprint: await TokenVault.deviceId(),
        }),
      });
      if (!response.ok) return false;
      const payload: Envelope<AuthTokens> = await response.json();
      await TokenVault.save(
        payload.data.access_token,
        payload.data.refresh_token,
      );
      return true;
    } catch {
      return false;
    } finally {
      refreshPromise = null;
    }
  })();
  return refreshPromise;
}
export async function api<T>(path: string, options: Options = {}): Promise<T> {
  if (process.env.EXPO_PUBLIC_DEMO_MODE === "true")
    return demoApi<T>(path, options);
  if (!API_URL)
    throw new ApiConfigurationError(
      "EXPO_PUBLIC_API_BASE_URL is required for production builds.",
    );
  const controller = new AbortController();
  const timer = setTimeout(
    () => controller.abort(),
    options.timeoutMs ?? 15000,
  );
  try {
    const [token, tenant] = await Promise.all([
      TokenVault.access(),
      TokenVault.tenant(),
    ]);
    const multipart =
      typeof FormData !== "undefined" && options.body instanceof FormData;
    const response = await fetch(`${API_URL}${path}`, {
      ...options,
      signal: controller.signal,
      headers: {
        Accept: "application/json",
        ...(!multipart ? { "Content-Type": "application/json" } : {}),
        "X-Request-ID": Crypto.randomUUID(),
        ...(options.idempotent
          ? { "Idempotency-Key": Crypto.randomUUID() }
          : {}),
        ...(!options.anonymous && token
          ? { Authorization: `Bearer ${token}` }
          : {}),
        ...(!options.anonymous && tenant ? { "X-Tenant-Id": tenant } : {}),
        ...options.headers,
      },
    });
    if (
      response.status === 401 &&
      !options.anonymous &&
      (options.retryAuth ?? true) &&
      (await rotate())
    )
      return api<T>(path, { ...options, retryAuth: false });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw toError(response.status, payload, response);
    return (payload as Envelope<T>).data;
  } catch (error) {
    if (error instanceof ApiError || error instanceof ApiConfigurationError)
      throw error;
    if ((error as Error).name === "AbortError")
      throw new ApiError(
        408,
        "REQUEST_TIMEOUT",
        "The request timed out. Check your connection and try again.",
      );
    throw new ApiError(
      0,
      "NETWORK_UNAVAILABLE",
      "No secure connection could be established.",
    );
  } finally {
    clearTimeout(timer);
  }
}

export type DeviceSession = {
  id: string;
  name: string;
  platform: string;
  last_seen_at: string;
  current: boolean;
};
export type NotificationPreferences = {
  push: boolean;
  sms: boolean;
  email: boolean;
  renewals: boolean;
  claims: boolean;
  payments: boolean;
};
export const AccountApi = {
  updateProfile: (payload: { full_name: string; email: string | null }) =>
    api<SessionBootstrap["user"]>("/mobile/account/profile", {
      method: "PATCH",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  setLocale: (locale: "en" | "fr") =>
    api<{ locale: "en" | "fr" }>("/mobile/account/locale", {
      method: "PUT",
      body: JSON.stringify({ locale }),
      idempotent: true,
    }),
  devices: () => api<DeviceSession[]>("/mobile/account/devices"),
  revokeDevice: (id: string) =>
    api<void>(`/mobile/account/devices/${id}`, {
      method: "DELETE",
      idempotent: true,
    }),
  notificationPreferences: () =>
    api<NotificationPreferences>("/mobile/account/notification-preferences"),
  saveNotificationPreferences: (payload: NotificationPreferences) =>
    api<NotificationPreferences>("/mobile/account/notification-preferences", {
      method: "PUT",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  registerPush: (token: string) =>
    api<void>("/mobile/account/push-tokens", {
      method: "POST",
      body: JSON.stringify({ token, platform: Platform.OS }),
      idempotent: true,
    }),
};
export type Workspace = {
  membership_id: string;
  tenant_id: string;
  tenant_name: string;
  tenant_type: string;
  role_code: string;
  permissions: string[];
  customer_id: string | null;
};
export type WorkspaceMetric = {
  key: string;
  label: string;
  value: string;
  tone?: "neutral" | "success" | "warning" | "danger";
};
export type WorkspaceModule = {
  key: string;
  label: string;
  description: string;
  permission: string;
};
export type WorkspaceDashboard = {
  title: string;
  subtitle: string;
  metrics: WorkspaceMetric[];
  modules: WorkspaceModule[];
};
export type WorkspaceModuleData = {
  title: string;
  columns: string[];
  rows: Record<string, string | number | null>[];
  next_cursor?: string | null;
};
export const WorkspaceApi = {
  dashboard: () => api<WorkspaceDashboard>("/mobile/workspace/dashboard"),
  module: (key: string) =>
    api<WorkspaceModuleData>(
      `/mobile/workspace/modules/${encodeURIComponent(key)}`,
    ),
};
export type SessionBootstrap = {
  user: {
    id: string;
    full_name: string;
    email: string | null;
    phone_e164: string;
    locale: "en" | "fr";
    status: string;
    phone_verified_at: string | null;
  };
  workspaces: Workspace[];
};
export type AuthTokens = {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  refresh_expires_in: number;
  session_id: string;
  bootstrap: SessionBootstrap;
};
export const AuthApi = {
  requestOtp: (phone_e164: string) =>
    api<{
      challenge_id: string;
      delivery_status: "QUEUED";
      expires_in: number;
    }>("/auth/mobile/otp/request", {
      method: "POST",
      body: JSON.stringify({ phone_e164 }),
      anonymous: true,
      idempotent: true,
    }),
  verifyOtp: async (challenge_id: string, phone_e164: string, code: string) => {
    const data = await api<AuthTokens>("/auth/mobile/otp/verify", {
      method: "POST",
      body: JSON.stringify({
        challenge_id,
        phone_e164,
        code,
        device: {
          fingerprint: await TokenVault.deviceId(),
          name: `OpesInsure ${Platform.OS}`,
          platform: Platform.OS,
        },
      }),
      anonymous: true,
      idempotent: true,
    });
    await TokenVault.save(data.access_token, data.refresh_token);
    return data;
  },
  session: () => api<SessionBootstrap>("/auth/mobile/session"),
  logout: async () => {
    const refresh_token = await TokenVault.refresh();
    try {
      await api("/auth/mobile/logout", {
        method: "POST",
        body: JSON.stringify({ refresh_token }),
      });
    } finally {
      await TokenVault.clear();
    }
  },
};

export type QuoteOffer = {
  id: string;
  quote_id: string;
  carrier_id: string;
  product_id: string;
  premium_minor: number;
  tax_minor: number;
  fee_minor: number;
  total_minor: number;
  currency: "XAF";
  status: string;
  valid_until: string;
  coverage_snapshot: Record<string, unknown>;
  ranking_reasons: string[];
  carrier?: { party?: { display_name?: string } };
  product?: { name?: string };
};
export type Quote = {
  id: string;
  party_id: string;
  line_code: string;
  status: string;
  currency: "XAF";
  risk_facts: Record<string, unknown>;
  expires_at: string;
  version: number;
};
export type QuoteResult = { quote: Quote; offers: QuoteOffer[] };
export type Proposal = {
  id: string;
  proposal_number: string;
  status: string;
  terms_snapshot: {
    premium_minor: number;
    tax_minor: number;
    fee_minor: number;
    total_minor: number;
    currency: "XAF";
  };
  disclosure_schema?: { questions?: unknown[] };
};
export type Payment = {
  id: string;
  proposal_id: string;
  provider: string;
  payer_phone_e164: string;
  amount_minor: number;
  currency: "XAF";
  status: string;
  expires_at: string;
  attempts?: unknown[];
};
export type Policy = {
  id: string;
  policy_number: string;
  status: string;
  coverage_starts_at: string;
  coverage_ends_at: string;
  carrier_id: string;
  certificates?: unknown[];
};
export type PolicyCertificate = {
  id: string;
  label: string;
  download_url: string;
  expires_at: string;
};
export type PolicyServiceRequest = {
  id: string;
  policy_id: string;
  type: string;
  status: string;
  created_at: string;
};
export const PolicyApi = {
  certificate: (id: string) =>
    api<PolicyCertificate>(`/policies/${id}/certificate`),
  renewalQuote: (id: string) =>
    api<QuoteResult>(`/policies/${id}/renewal-quote`, {
      method: "POST",
      idempotent: true,
    }),
  service: (
    id: string,
    payload: { type: "ENDORSEMENT" | "CANCELLATION_REVIEW"; reason: string },
  ) =>
    api<PolicyServiceRequest>(`/policies/${id}/service-requests`, {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
};
export type PurchaseStatus = {
  status:
    | "PAYMENT_PENDING"
    | "PAYMENT_PROCESSING"
    | "PAYMENT_FAILED"
    | "ISSUANCE_PENDING"
    | "POLICY_ISSUED";
  payment: Payment;
  policy: Policy | null;
};
export type PublicVerification = {
  reference: string;
  result: "VALID" | "EXPIRED" | "CANCELLED" | "NOT_FOUND";
  carrier_name?: string;
  product_class?: string;
  coverage_starts_at?: string;
  coverage_ends_at?: string;
  verified_at: string;
};
export const PublicApi = {
  verify: (reference: string) =>
    api<PublicVerification>("/public/insurance/verify", {
      method: "POST",
      body: JSON.stringify({ reference }),
      anonymous: true,
      idempotent: true,
    }),
};
export type ClaimEvidence = {
  id: string;
  type: string;
  file_name: string;
  status: string;
  created_at: string;
};
export type ClaimEvent = {
  id: string;
  type: string;
  label: string;
  occurred_at: string;
  description?: string;
};
export type Claim = {
  id: string;
  claim_number: string;
  policy_id: string;
  status: string;
  incident_at: string;
  incident_location: string;
  description: string;
  created_at: string;
  policy?: Policy;
  evidence?: ClaimEvidence[];
  timeline?: ClaimEvent[];
};
export const ClaimsApi = {
  list: () => api<any>("/claims"),
  show: (id: string) => api<Claim>(`/claims/${id}`),
  create: (payload: {
    policy_id: string;
    incident_at: string;
    incident_location: string;
    description: string;
  }) =>
    api<Claim>("/claims", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  uploadEvidence: (id: string, form: FormData) =>
    api<ClaimEvidence>(`/claims/${id}/evidence`, {
      method: "POST",
      body: form,
      idempotent: true,
      timeoutMs: 45000,
    }),
  submitDeclaration: (id: string) =>
    api<Claim>(`/claims/${id}/declaration`, {
      method: "POST",
      idempotent: true,
    }),
  appeal: (id: string, reason: string) =>
    api<Claim>(`/claims/${id}/appeals`, {
      method: "POST",
      body: JSON.stringify({ reason }),
      idempotent: true,
    }),
};
export type ClaimIncidentDetails = {
  claim_id: string;
  incident_type: string;
  police_report_number?: string | null;
  latitude?: number | null;
  longitude?: number | null;
  injuries_reported: boolean;
  vehicle_drivable: boolean;
  towing_required: boolean;
  declaration_confirmed: boolean;
};
export type ClaimParty = {
  id: string;
  claim_id: string;
  role: "DRIVER" | "THIRD_PARTY" | "WITNESS" | "PASSENGER";
  full_name: string;
  phone_e164?: string;
  vehicle_registration?: string;
  insurer_name?: string;
};
export type EvidenceRequirement = {
  key: string;
  label: string;
  required: boolean;
  status: "MISSING" | "UPLOADED" | "VERIFIED" | "REJECTED";
  guidance: string;
};
export type ClaimInspection = {
  id: string;
  claim_id: string;
  status: string;
  appointment_at: string;
  location: string;
  surveyor_name?: string | null;
  contact_phone?: string | null;
  notes?: string | null;
};
export type ClaimRepair = {
  claim_id: string;
  status: string;
  garage_name?: string | null;
  estimate_minor?: number | null;
  approved_minor?: number | null;
  deductible_minor?: number | null;
  authorization_reference?: string | null;
};
export type ClaimSettlement = {
  id: string;
  claim_id: string;
  status: string;
  offered_minor: number;
  deductible_minor: number;
  net_minor: number;
  currency: "XAF";
  payment_status: string;
  payment_reference?: string | null;
  decision_deadline: string;
  terms: string;
};
export const ClaimsCompletionApi = {
  incident: (id: string) =>
    api<ClaimIncidentDetails>(`/mobile/claims/${id}/incident`),
  saveIncident: (id: string, payload: Partial<ClaimIncidentDetails>) =>
    api<ClaimIncidentDetails>(`/mobile/claims/${id}/incident`, {
      method: "PUT",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  parties: (id: string) => api<ClaimParty[]>(`/mobile/claims/${id}/parties`),
  addParty: (id: string, payload: Omit<ClaimParty, "id" | "claim_id">) =>
    api<ClaimParty>(`/mobile/claims/${id}/parties`, {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  evidenceRequirements: (id: string) =>
    api<EvidenceRequirement[]>(`/mobile/claims/${id}/evidence-requirements`),
  inspection: (id: string) =>
    api<ClaimInspection>(`/mobile/claims/${id}/inspection`),
  rescheduleInspection: (id: string, appointment_at: string) =>
    api<ClaimInspection>(`/mobile/claims/${id}/inspection/reschedule`, {
      method: "POST",
      body: JSON.stringify({ appointment_at }),
      idempotent: true,
    }),
  repair: (id: string) => api<ClaimRepair>(`/mobile/claims/${id}/repair`),
  settlement: (id: string) =>
    api<ClaimSettlement>(`/mobile/claims/${id}/settlement`),
  decideSettlement: (id: string, decision: "ACCEPT" | "REJECT") =>
    api<ClaimSettlement>(`/mobile/claims/${id}/settlement/decision`, {
      method: "POST",
      body: JSON.stringify({ decision }),
      idempotent: true,
    }),
  requestEmergencyAssistance: (payload: {
    policy_id: string;
    service: "MEDICAL" | "POLICE" | "TOWING";
    location: string;
    callback_phone: string;
  }) =>
    api<{ id: string; status: string; reference: string }>(
      "/mobile/claims/emergency-assistance",
      { method: "POST", body: JSON.stringify(payload), idempotent: true },
    ),
};
export type AgentProfile = {
  id: string;
  status: "DRAFT" | "PENDING_REVIEW" | "ACTIVE" | "SUSPENDED";
  agent_code: string;
  full_name: string;
  national_id_number?: string;
  momo_phone_e164: string;
  mandate_expires_at?: string | null;
  compliance_items: { label: string; status: string }[];
};
export type AgentClient = {
  id: string;
  full_name: string;
  phone_e164: string;
  city: string;
  kyc_status: string;
  origin_locked: boolean;
  active_policies: number;
  renewal_due_at?: string | null;
};
export type AgentSale = {
  id: string;
  customer_id: string;
  customer_name: string;
  product: string;
  status: string;
  premium_minor: number;
  currency: "XAF";
  payment_phone_e164: string;
  payment_status: string;
  commission_minor: number;
  created_at: string;
};
export type AgentCommission = {
  id: string;
  policy_id?: string;
  status: string;
  amount_minor: number;
  currency: "XAF";
  available_at?: string;
  reason?: string;
};
export type AgentWithdrawal = {
  id: string;
  provider: string;
  amount_minor: number;
  status: string;
  requested_at: string;
  destination_phone: string;
};
export type AgentRenewal = {
  id: string;
  customer_id: string;
  customer_name: string;
  policy_number: string;
  expires_at: string;
  days_remaining: number;
  status: string;
};
export type OfflineFieldItem = {
  id: string;
  type: string;
  local_reference: string;
  status: "QUEUED" | "SYNCING" | "FAILED" | "SYNCED";
  updated_at: string;
  error?: string;
};
export const AgentApi = {
  profile: () => api<AgentProfile>("/mobile/agent/profile"),
  submitProfile: (payload: Partial<AgentProfile>) =>
    api<AgentProfile>("/mobile/agent/profile", {
      method: "PATCH",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  dashboard: () =>
    api<{
      metrics: { label: string; value: string; tone?: string }[];
      recent_sales: AgentSale[];
    }>("/mobile/agent/dashboard"),
  clients: () => api<AgentClient[]>("/mobile/agent/clients"),
  client: (id: string) => api<AgentClient>(`/mobile/agent/clients/${id}`),
  createClient: (payload: {
    full_name: string;
    phone_e164: string;
    city: string;
    consent_reference: string;
  }) =>
    api<AgentClient>("/mobile/agent/clients", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  createSale: (payload: {
    customer_id: string;
    product: string;
    payment_phone_e164: string;
  }) =>
    api<AgentSale>("/mobile/agent/sales", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  sale: (id: string) => api<AgentSale>(`/mobile/agent/sales/${id}`),
  requestPayment: (id: string) =>
    api<AgentSale>(`/mobile/agent/sales/${id}/payment-request`, {
      method: "POST",
      idempotent: true,
    }),
  renewals: () => api<AgentRenewal[]>("/mobile/agent/renewals"),
  commissions: () => api<AgentCommission[]>("/mobile/agent/commissions"),
  withdrawals: () => api<AgentWithdrawal[]>("/mobile/agent/withdrawals"),
  requestWithdrawal: (payload: {
    amount_minor: number;
    provider: "mtn_momo" | "orange_money";
    destination_phone: string;
  }) =>
    api<AgentWithdrawal>("/mobile/agent/withdrawals", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  offlineQueue: () => api<OfflineFieldItem[]>("/mobile/agent/offline-queue"),
  retryOffline: (id: string) =>
    api<OfflineFieldItem>(`/mobile/agent/offline-queue/${id}/retry`, {
      method: "POST",
      idempotent: true,
    }),
};
export const InsuranceApi = {
  createQuote: (payload: {
    customer_id: string;
    line_code: string;
    channel: "B2C";
    risk_asset_id?: string;
    risk_facts: Record<string, unknown>;
  }) =>
    api<Quote>("/quotes", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  quote: (id: string) => api<QuoteResult>(`/quotes/${id}`),
  rateQuote: (id: string) =>
    api<QuoteResult>(`/quotes/${id}/rate`, {
      method: "POST",
      idempotent: true,
    }),
  acceptOffer: (quoteId: string, offerId: string) =>
    api<{ quote_id: string; offer_id: string; status: "ACCEPTED" }>(
      `/quotes/${quoteId}/offers/${offerId}/accept`,
      { method: "POST", idempotent: true },
    ),
  createProposal: (payload: { quote_offer_id: string; party_id: string }) =>
    api<Proposal>("/proposals", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  proposal: (id: string) => api<Proposal>(`/proposals/${id}`),
  createPayment: (payload: {
    proposal_id: string;
    provider: "mtn_momo" | "orange_money";
    payer_phone_e164: string;
    idempotency_key: string;
  }) =>
    api<Payment>("/payments", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  initiatePayment: (id: string) =>
    api<Payment>(`/payments/${id}/initiate`, {
      method: "POST",
      idempotent: true,
    }),
  payment: (id: string) => api<Payment>(`/payments/${id}`),
  purchaseStatus: (proposalId: string) =>
    api<PurchaseStatus>(`/mobile/purchases/${proposalId}/status`),
  policies: () => api<any>("/policies"),
  policy: (id: string) => api<Policy>(`/policies/${id}`),
};

export type KycProfile = {
  status: string;
  legal_name: string;
  date_of_birth?: string;
  national_id_number?: string;
  city?: string;
  rejection_reason?: string | null;
};
export type RiskAsset = {
  id: string;
  type: string;
  label: string;
  registration_number?: string;
  make?: string;
  model?: string;
  year?: number;
  status: string;
  documents?: AssetDocument[];
};
export type AssetDocument = {
  id: string;
  asset_id: string;
  type: string;
  file_name: string;
  status: string;
  extracted_fields?: Record<string, string>;
};
export type DisclosureSession = {
  id: string;
  proposal_id: string;
  status: string;
  questions: {
    id: string;
    label: string;
    type: "boolean" | "text";
    required: boolean;
    answer?: boolean | string;
  }[];
  referral_reason?: string | null;
};
export type PaymentReceipt = {
  id: string;
  payment_id: string;
  receipt_number: string;
  issued_at: string;
  amount_minor: number;
  currency: "XAF";
  download_url: string;
};
export type RefundRequest = {
  id: string;
  payment_id: string;
  reason: string;
  status: string;
  created_at: string;
};
export type WalletPolicy = Policy & {
  carrier_name?: string;
  product_name?: string;
  documents?: PolicyCertificate[];
  delivery?: StickerDelivery | null;
};
export type StickerDelivery = {
  id: string;
  policy_id: string;
  status: string;
  recipient_name: string;
  phone_e164: string;
  address_line: string;
  city: string;
  eta?: string;
  tracking_code: string;
  timeline: { label: string; occurred_at: string; complete: boolean }[];
};
export const KycApi = {
  profile: () => api<KycProfile>("/mobile/kyc/profile"),
  saveProfile: (payload: Partial<KycProfile>) =>
    api<KycProfile>("/mobile/kyc/profile", {
      method: "PATCH",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  uploadDocument: (form: FormData) =>
    api<{ id: string; status: string }>("/mobile/kyc/documents", {
      method: "POST",
      body: form,
      timeoutMs: 45000,
      idempotent: true,
    }),
  submit: () =>
    api<KycProfile>("/mobile/kyc/submission", {
      method: "POST",
      idempotent: true,
    }),
};
export const AssetsApi = {
  list: () => api<RiskAsset[]>("/mobile/assets"),
  show: (id: string) => api<RiskAsset>(`/mobile/assets/${id}`),
  create: (payload: Partial<RiskAsset>) =>
    api<RiskAsset>("/mobile/assets", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  uploadDocument: (id: string, form: FormData) =>
    api<AssetDocument>(`/mobile/assets/${id}/documents`, {
      method: "POST",
      body: form,
      timeoutMs: 45000,
      idempotent: true,
    }),
  scan: (id: string) =>
    api<AssetDocument>(`/mobile/assets/${id}/scan`, {
      method: "POST",
      idempotent: true,
    }),
  confirmScan: (
    id: string,
    documentId: string,
    fields: Record<string, string>,
  ) =>
    api<RiskAsset>(`/mobile/assets/${id}/scan/${documentId}/confirm`, {
      method: "POST",
      body: JSON.stringify({ fields }),
      idempotent: true,
    }),
};
export const DisclosureApi = {
  session: (proposalId: string) =>
    api<DisclosureSession>(`/proposals/${proposalId}/disclosure`),
  saveAnswers: (
    proposalId: string,
    answers: Record<string, boolean | string>,
  ) =>
    api<DisclosureSession>(`/proposals/${proposalId}/disclosure/answers`, {
      method: "PUT",
      body: JSON.stringify({ answers }),
      idempotent: true,
    }),
  submit: (proposalId: string) =>
    api<DisclosureSession>(`/proposals/${proposalId}/disclosure/submit`, {
      method: "POST",
      idempotent: true,
    }),
  acceptTerms: (proposalId: string, accepted: boolean) =>
    api<{ accepted: boolean; accepted_at: string }>(
      `/proposals/${proposalId}/terms`,
      { method: "POST", body: JSON.stringify({ accepted }), idempotent: true },
    ),
};
export const PaymentsApi = {
  list: () => api<Payment[]>("/mobile/payments"),
  show: (id: string) => api<Payment>(`/mobile/payments/${id}`),
  retry: (id: string) =>
    api<Payment>(`/mobile/payments/${id}/retry`, {
      method: "POST",
      idempotent: true,
    }),
  receipt: (id: string) =>
    api<PaymentReceipt>(`/mobile/payments/${id}/receipt`),
  refund: (id: string, reason: string) =>
    api<RefundRequest>(`/mobile/payments/${id}/refunds`, {
      method: "POST",
      body: JSON.stringify({ reason }),
      idempotent: true,
    }),
};
export const WalletApi = {
  list: () => api<WalletPolicy[]>("/mobile/wallet"),
  policy: (id: string) => api<WalletPolicy>(`/mobile/wallet/policies/${id}`),
  delivery: (id: string) => api<StickerDelivery>(`/mobile/deliveries/${id}`),
  updateAddress: (
    id: string,
    payload: {
      recipient_name: string;
      phone_e164: string;
      address_line: string;
      city: string;
    },
  ) =>
    api<StickerDelivery>(`/mobile/deliveries/${id}/address`, {
      method: "PUT",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  confirmDelivery: (id: string, otp: string) =>
    api<StickerDelivery>(`/mobile/deliveries/${id}/confirm`, {
      method: "POST",
      body: JSON.stringify({ otp }),
      idempotent: true,
    }),
};

export type CustomerQuoteSummary = Quote & {
  product_name?: string;
  vehicle_label?: string;
  lowest_total_minor?: number;
  offer_count: number;
  can_resume: boolean;
};
export type SecureDocument = {
  id: string;
  owner_type: "POLICY" | "PAYMENT" | "CLAIM" | "SERVICE_REQUEST";
  owner_id: string;
  label: string;
  mime_type: string;
  status: string;
  issued_at: string;
  expires_at?: string | null;
  signed_url?: string | null;
  share_reference: string;
};
export type PolicyServiceCase = {
  id: string;
  policy_id: string;
  type: string;
  reason: string;
  status: string;
  created_at: string;
  updated_at: string;
  timeline: {
    id: string;
    label: string;
    occurred_at: string;
    description?: string;
  }[];
  requested_documents?: string[];
};
export type CustomerNotification = {
  id: string;
  type: string;
  title: string;
  body: string;
  read: boolean;
  created_at: string;
  path?: string | null;
  severity: "INFO" | "SUCCESS" | "WARNING" | "CRITICAL";
};
export type SupportCase = {
  id: string;
  reference: string;
  category: string;
  subject: string;
  description: string;
  status: string;
  priority: string;
  created_at: string;
  updated_at: string;
  messages: {
    id: string;
    sender: "CUSTOMER" | "SUPPORT";
    body: string;
    created_at: string;
  }[];
  attachments?: { id: string; file_name: string; status: string }[];
};
export const QuotesApi = {
  history: () => api<CustomerQuoteSummary[]>("/mobile/quotes"),
  show: (id: string) =>
    api<QuoteResult & { summary: CustomerQuoteSummary }>(
      `/mobile/quotes/${id}`,
    ),
  resume: (id: string) =>
    api<{ quote_id: string; next_path: string }>(
      `/mobile/quotes/${id}/resume`,
      { method: "POST", idempotent: true },
    ),
  discard: (id: string) =>
    api<void>(`/mobile/quotes/${id}`, { method: "DELETE", idempotent: true }),
};
export const DocumentsApi = {
  list: (ownerType?: string, ownerId?: string) =>
    api<SecureDocument[]>(
      `/mobile/documents${ownerType && ownerId ? `?owner_type=${encodeURIComponent(ownerType)}&owner_id=${encodeURIComponent(ownerId)}` : ""}`,
    ),
  show: (id: string) => api<SecureDocument>(`/mobile/documents/${id}`),
  access: (id: string) =>
    api<SecureDocument>(`/mobile/documents/${id}/access`, {
      method: "POST",
      idempotent: true,
    }),
};
export const PolicyServicesApi = {
  list: (policyId?: string) =>
    api<PolicyServiceCase[]>(
      `/mobile/policy-service-requests${policyId ? `?policy_id=${encodeURIComponent(policyId)}` : ""}`,
    ),
  show: (id: string) =>
    api<PolicyServiceCase>(`/mobile/policy-service-requests/${id}`),
  create: (payload: { policy_id: string; type: string; reason: string }) =>
    api<PolicyServiceCase>("/mobile/policy-service-requests", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  addMessage: (id: string, message: string) =>
    api<PolicyServiceCase>(`/mobile/policy-service-requests/${id}/messages`, {
      method: "POST",
      body: JSON.stringify({ message }),
      idempotent: true,
    }),
};
export const NotificationsApi = {
  list: () => api<CustomerNotification[]>("/mobile/notifications"),
  show: (id: string) =>
    api<CustomerNotification>(`/mobile/notifications/${id}`),
  markRead: (id: string) =>
    api<CustomerNotification>(`/mobile/notifications/${id}/read`, {
      method: "POST",
      idempotent: true,
    }),
  markAllRead: () =>
    api<{ updated: number }>("/mobile/notifications/read-all", {
      method: "POST",
      idempotent: true,
    }),
};
export const SupportApi = {
  list: () => api<SupportCase[]>("/mobile/support/cases"),
  show: (id: string) => api<SupportCase>(`/mobile/support/cases/${id}`),
  create: (payload: {
    category: string;
    subject: string;
    description: string;
  }) =>
    api<SupportCase>("/mobile/support/cases", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  reply: (id: string, body: string) =>
    api<SupportCase>(`/mobile/support/cases/${id}/messages`, {
      method: "POST",
      body: JSON.stringify({ body }),
      idempotent: true,
    }),
  upload: (id: string, form: FormData) =>
    api<SupportCase>(`/mobile/support/cases/${id}/attachments`, {
      method: "POST",
      body: form,
      timeoutMs: 45000,
      idempotent: true,
    }),
};
