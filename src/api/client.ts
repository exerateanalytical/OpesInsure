import * as Crypto from "expo-crypto";
import * as SecureStore from "expo-secure-store";
import { Platform } from "react-native";
import { OfflineOperation } from "@/offline/types";
import { environmentConfig } from "@/config/environment";
import { DEVICE_ONLY } from "@/security/secureJson";
import { PageResult, unwrapPage } from "@/lib/purchase";
export type { PageResult } from "@/lib/purchase";

// environmentConfig.apiBaseUrl already falls back to the production host in
// production builds, so an OTA published without env cannot brick the fleet.
const API_URL = environmentConfig.apiBaseUrl || (__DEV__ ? "http://10.0.2.2:8000/api/v1" : "");
const keys = {
  access: "opesinsure.access_token",
  refresh: "opesinsure.refresh_token",
  tenant: "opesinsure.tenant_id",
  device: "opesinsure.device_id",
  payment: "opesinsure.pending_payment_id",
  stepUp: "opesinsure.step_up_grant",
};
export type ApiFieldErrors = Record<string, string[]>;
/** Registered by src/i18n (which imports this module, not the reverse) so
 * known backend codes (STALE_RECORD, DUPLICATE_SUBMISSION, ...) carry an
 * EN/FR message in the user's language. */
type ErrorLocalizer = (code: string, status?: number) => string | null;
let localizeError: ErrorLocalizer | null = null;
export const setApiErrorLocalizer = (fn: ErrorLocalizer) => {
  localizeError = fn;
};
/** Localized copy for an error code, or null when it has no specific copy. */
export const localizeErrorCode = (code: string, status?: number) => localizeError?.(code, status) ?? null;
export class ApiError extends Error {
  /** The server's own (untranslated) message, kept for support/diagnostics. */
  public detail: string;
  constructor(
    public status: number,
    public code: string,
    message: string,
    public fields?: ApiFieldErrors,
    public retryAfter?: number,
  ) {
    super(localizeError?.(code, status) ?? message);
    this.detail = message;
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
  stepUpPurpose?: string;
  /** Reuse a caller-owned key (e.g. one per payment attempt). */
  idempotencyKey?: string;
  /** Return the whole JSON body instead of `data` (for list meta). */
  envelope?: boolean;
  /** Extra same-key attempts after a network/timeout failure (default 1). */
  networkRetries?: number;
};
let refreshPromise: Promise<boolean> | null = null;
const sessionExpiredListeners = new Set<() => void>();
export const onSessionExpired = (listener: () => void) => {
  sessionExpiredListeners.add(listener);
  return () => sessionExpiredListeners.delete(listener);
};

export const TokenVault = {
  async save(access: string, refresh: string) {
    // Device-only: tokens never migrate through encrypted backups or a
    // device transfer (existing items are rewritten at the next rotation).
    await Promise.all([
      SecureStore.setItemAsync(keys.access, access, DEVICE_ONLY),
      SecureStore.setItemAsync(keys.refresh, refresh, DEVICE_ONLY),
    ]);
  },
  async access() {
    return SecureStore.getItemAsync(keys.access);
  },
  async refresh() {
    return SecureStore.getItemAsync(keys.refresh);
  },
  async setTenant(id: string) {
    await SecureStore.setItemAsync(keys.tenant, id, DEVICE_ONLY);
  },
  async tenant() {
    return SecureStore.getItemAsync(keys.tenant);
  },
  async clear() {
    await Promise.all([
      SecureStore.deleteItemAsync(keys.access),
      SecureStore.deleteItemAsync(keys.refresh),
      SecureStore.deleteItemAsync(keys.tenant),
      SecureStore.deleteItemAsync(keys.stepUp),
    ]);
  },
  async setPendingPayment(id: string) {
    await SecureStore.setItemAsync(keys.payment, id, DEVICE_ONLY);
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
      await SecureStore.setItemAsync(keys.device, id, DEVICE_ONLY);
    }
    return id;
  },
};
export type StepUpGrant = {
  grant_token: string;
  purpose: string;
  expires_at: string;
};
export const StepUpVault = {
  async save(grant: StepUpGrant) {
    await SecureStore.setItemAsync(keys.stepUp, JSON.stringify(grant), {
      keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
    });
  },
  async valid(purpose: string) {
    const raw = await SecureStore.getItemAsync(keys.stepUp);
    if (!raw) return null;
    try {
      const grant = JSON.parse(raw) as StepUpGrant;
      if (grant.purpose !== purpose || Date.parse(grant.expires_at) <= Date.now()) {
        await SecureStore.deleteItemAsync(keys.stepUp);
        return null;
      }
      return grant;
    } catch {
      await SecureStore.deleteItemAsync(keys.stepUp);
      return null;
    }
  },
  clear: () => SecureStore.deleteItemAsync(keys.stepUp),
};
function toError(status: number, payload: any, response: Response) {
  const first = payload?.errors?.[0];
  return new ApiError(
    status,
    first?.code ?? payload?.code ?? "REQUEST_FAILED",
    first?.detail ??
      payload?.message ??
      (status === 429
        ? "Too many attempts. Please wait a moment and try again."
        : "The request could not be completed."),
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
/** Transient failures worth one automatic, same-key retry. */
const RETRYABLE_STATUS = new Set([0, 408, 502, 504]);
const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * One Idempotency-Key per user operation: it is resolved ONCE here (or
 * supplied by the caller via `idempotencyKey`) and reused verbatim for the
 * automatic transient-failure retry and for the retry after a 401 token
 * rotation. Previously each attempt minted a fresh key, so a timeout
 * followed by a retry could create a duplicate payment or claim.
 */
export async function api<T>(path: string, options: Options = {}): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();
  const idempotencyKey =
    options.idempotencyKey ??
    (options.idempotent ? Crypto.randomUUID() : undefined);
  const resolved: Options = { ...options, idempotencyKey };
  // Retrying a write is only safe when the server can de-duplicate it.
  const canRetry = method === "GET" || !!idempotencyKey;
  const attempts = canRetry ? 1 + (options.networkRetries ?? 1) : 1;
  let lastError: unknown;
  for (let attempt = 0; attempt < attempts; attempt++) {
    try {
      return await attemptRequest<T>(path, resolved);
    } catch (error) {
      lastError = error;
      const retryable =
        error instanceof ApiError && RETRYABLE_STATUS.has(error.status);
      if (!retryable || attempt === attempts - 1) throw error;
      await sleep(600 * (attempt + 1));
    }
  }
  throw lastError;
}

async function attemptRequest<T>(path: string, options: Options): Promise<T> {
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
    const stepUp = options.stepUpPurpose
      ? await StepUpVault.valid(options.stepUpPurpose)
      : null;
    if (options.stepUpPurpose && !stepUp)
      throw new ApiError(
        401,
        "STEP_UP_REQUIRED",
        "Verify this sensitive action before continuing.",
      );
    const multipart =
      typeof FormData !== "undefined" && options.body instanceof FormData;
    const {
      idempotent: _idempotent,
      idempotencyKey,
      envelope: _envelope,
      networkRetries: _networkRetries,
      timeoutMs: _timeoutMs,
      anonymous: _anonymous,
      retryAuth: _retryAuth,
      stepUpPurpose: _stepUpPurpose,
      ...init
    } = options;
    const response = await fetch(`${API_URL}${path}`, {
      ...init,
      signal: controller.signal,
      headers: {
        Accept: "application/json",
        ...(!multipart ? { "Content-Type": "application/json" } : {}),
        "X-Request-ID": Crypto.randomUUID(),
        ...(idempotencyKey ? { "Idempotency-Key": idempotencyKey } : {}),
        ...(!options.anonymous && token
          ? { Authorization: `Bearer ${token}` }
          : {}),
        ...(!options.anonymous && tenant ? { "X-Tenant-Id": tenant } : {}),
        ...(stepUp ? { "X-Step-Up-Grant": stepUp.grant_token } : {}),
        ...options.headers,
      },
    });
    if (response.status === 401 && !options.anonymous) {
      if ((options.retryAuth ?? true) && (await rotate()))
        // Same options object -> same Idempotency-Key on the replay.
        return attemptRequest<T>(path, { ...options, retryAuth: false });
      await TokenVault.clear();
      sessionExpiredListeners.forEach((listener) => listener());
      throw new ApiError(
        401,
        "SESSION_EXPIRED",
        "Your secure session has expired.",
      );
    }
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw toError(response.status, payload, response);
    return (options.envelope ? payload : (payload as Envelope<T>).data) as T;
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

/** GET a list endpoint and normalize every pagination shape it has used. */
export async function apiPage<T>(path: string, page = 1): Promise<PageResult<T>> {
  const sep = path.includes("?") ? "&" : "?";
  const payload = await api<unknown>(`${path}${sep}page=${page}`, {
    envelope: true,
  });
  return unwrapPage<T>(payload);
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
export type CustomerProfile = {
  party_id?: string;
  full_name?: string;
  date_of_birth: string | null;
  occupation: string | null;
  address_line1: string | null;
  city: string | null;
  region: string | null;
  country_code?: string | null;
  beneficiaries: { name: string; relationship: string; share_percent: number }[];
};
export type UserSettings = { display_timezone: string | null; business_timezone: string; effective_display_timezone: string };
export const SettingsApi = {
  timezones: () => api<{ timezone: string; countries: string[] }[]>("/settings/timezones"),
  me: () => api<UserSettings>("/me/settings"),
  /** null resets to the organisation (business) time zone. */
  updateMe: (display_timezone: string | null) =>
    api<UserSettings>("/me/settings", { method: "PATCH", body: JSON.stringify({ display_timezone }), idempotent: true }),
};
export const AccountApi = {
  /** GET/PATCH /mobile/account/customer-profile (form customer_profile). */
  customerProfile: () => api<CustomerProfile>("/mobile/account/customer-profile"),
  updateCustomerProfile: (payload: Record<string, unknown>) =>
    api<CustomerProfile>("/mobile/account/customer-profile", {
      method: "PATCH",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
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
  label?: string;
  title?: string;
  value: string;
  tone?: "neutral" | "success" | "warning" | "danger";
};
export type WorkspaceModule = {
  key: string;
  label?: string;
  title?: string;
  description?: string;
  /** "*" in workspace.permissions grants every module. */
  permission?: string;
};
export type WorkspaceDashboard = {
  title: string;
  subtitle: string;
  metrics: WorkspaceMetric[];
  modules: WorkspaceModule[];
};
export type WorkspaceModuleData = {
  title?: string;
  label?: string;
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
    /** Present once the backend reports email verification (Phase 9.6). */
    email_verified_at?: string | null;
    /** Set when registration activated the account with verification lifted. */
    contacts_verified?: boolean;
  };
  workspaces: Workspace[];
};
/**
 * What POST /auth/mobile/otp/verify actually returns: token fields sitting
 * directly alongside user/workspaces, not nested under a bootstrap key. This
 * type previously declared bootstrap: SessionBootstrap plus
 * refresh_expires_in/session_id fields the backend has never sent — nobody
 * had run the compiled app against the real API to notice. Every login
 * crashed on "Cannot read properties of undefined (reading 'workspaces')"
 * because verify.tsx read the nonexistent auth.bootstrap.
 */
export type AuthTokens = {
  access_token: string;
  refresh_token: string;
  expires_in: number;
} & SessionBootstrap;
export type DemoAccount = {
  label: string;
  full_name: string;
  phone_e164: string;
  role_code: string;
  /** Only present while the server is in demo mode. */
  password?: string;
};

export type VerificationChannel = "whatsapp" | "sms" | "email";
export type OtpChannel = "whatsapp" | "sms";

export type RegisterPayload = {
  full_name: string;
  phone_e164: string;
  email?: string;
  password: string;
  password_confirmation: string;
  locale: "en" | "fr";
  terms_version: string;
  verification_channel?: VerificationChannel;
};

/**
 * POST /public/accounts. With contact verification lifted (server default)
 * the account is active immediately and the response carries the same
 * token + bootstrap payload as OTP verify. When verification is required it
 * carries a challenge instead and the app continues on the verify screen.
 */
export type RegisterResult = Partial<AuthTokens> & {
  id?: string;
  status?: string;
  verification_required?: boolean;
  challenge_id?: string;
  verification_channel?: VerificationChannel;
};

const deviceInfo = async () => ({
  fingerprint: await TokenVault.deviceId(),
  name: `OpesInsure ${Platform.OS}`,
  platform: Platform.OS,
});

/** Stores the pair from any endpoint that returns the OTP-verify shape. */
const keepTokens = async (data: AuthTokens) => {
  await TokenVault.save(data.access_token, data.refresh_token);
  return data;
};

export const hasTokens = (data: RegisterResult): data is AuthTokens =>
  typeof data.access_token === "string" &&
  typeof data.refresh_token === "string" &&
  !!data.user &&
  Array.isArray(data.workspaces);

export const AuthApi = {
  register: async (payload: RegisterPayload) => {
    const data = await api<RegisterResult>("/public/accounts", {
      method: "POST",
      body: JSON.stringify({ ...payload, phone: payload.phone_e164 }),
      anonymous: true,
      idempotent: true,
    });
    if (hasTokens(data)) await keepTokens(data);
    return data;
  },
  /** Primary sign-in: phone + password. Same payload as OTP verify. */
  passwordLogin: async (phone_e164: string, password: string) =>
    keepTokens(
      await api<AuthTokens>("/auth/mobile/password-login", {
        method: "POST",
        body: JSON.stringify({
          phone: phone_e164,
          phone_e164,
          password,
          device: await deviceInfo(),
        }),
        anonymous: true,
        idempotent: true,
      }),
    ),
  forgotPassword: (phone_e164: string, channel?: OtpChannel) =>
    api<{ challenge_id: string; delivery_status: string; expires_in: number }>(
      "/auth/mobile/password/forgot",
      {
        method: "POST",
        body: JSON.stringify({ phone: phone_e164, phone_e164, channel }),
        anonymous: true,
        idempotent: true,
      },
    ),
  resetPassword: async (
    phone_e164: string,
    challenge_id: string,
    code: string,
    password: string,
  ) =>
    keepTokens(
      await api<AuthTokens>("/auth/mobile/password/reset", {
        method: "POST",
        body: JSON.stringify({
          phone: phone_e164,
          phone_e164,
          challenge_id,
          code,
          password,
          password_confirmation: password,
          device: await deviceInfo(),
        }),
        anonymous: true,
        idempotent: true,
      }),
    ),
  requestOtp: (phone_e164: string, channel?: OtpChannel) =>
    api<{
      challenge_id: string;
      delivery_status: "QUEUED";
      expires_in: number;
    }>("/auth/mobile/otp/request", {
      method: "POST",
      body: JSON.stringify(channel ? { phone_e164, channel } : { phone_e164 }),
      anonymous: true,
      idempotent: true,
    }),
  verifyOtp: async (challenge_id: string, phone_e164: string, code: string) =>
    keepTokens(
      await api<AuthTokens>("/auth/mobile/otp/verify", {
        method: "POST",
        body: JSON.stringify({
          challenge_id,
          phone_e164,
          code,
          device: await deviceInfo(),
        }),
        anonymous: true,
        idempotent: true,
      }),
    ),
  /** POST /me/email/verification — sends a verification link/code. */
  requestEmailVerification: () =>
    api<{ sent: boolean }>("/me/email/verification", {
      method: "POST",
      idempotent: true,
    }),
  /**
   * Demo credentials the SERVER is seeded with. The bundled demo dataset has
   * its own personas, but those exist only inside the in-app demo adapter — a
   * build pointed at a real API must offer the accounts that API actually has.
   * The endpoint only exists while the server has demo mode on, so a 404 here
   * is the normal answer in production and simply hides the affordance.
   */
  demoAccounts: async (): Promise<{ otp: string; password?: string | null; accounts: DemoAccount[] } | null> => {
    try {
      // data.password is the shared demo password (top level, not per account).
      return await api<{ otp: string; password?: string | null; accounts: DemoAccount[] }>("/public/demo-accounts", {
        anonymous: true,
      });
    } catch {
      return null;
    }
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
  carrier?: { id?: string; party?: { display_name?: string } } | null;
  product?: { id?: string; name?: string; line_code?: string } | null;
  decline_reason_code?: string | null;
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
  risk_asset_id?: string | null;
  referral_reason?: string | null;
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
    offer_id?: string;
    coverage_snapshot?: Record<string, unknown>;
    coverage_starts_at?: string;
    coverage_ends_at?: string;
  };
  disclosure_schema?: { questions?: unknown[] };
  quote_offer_id?: string;
  submitted_at?: string | null;
  decided_at?: string | null;
  created_at?: string;
  offer?: (QuoteOffer & { quote?: Quote }) | null;
  documents?: ProposalDocumentLink[];
  /** Server-listed requirements (when exposed). */
  required_documents?: ProposalRequirement[];
  underwriting_case?: {
    status?: string;
    decisions?: {
      decision: string;
      reason_code?: string;
      notes?: string;
      decided_at?: string;
    }[];
  } | null;
  payments?: Payment[];
  /** Counter-offer terms, when the insurer revised the premium. */
  counteroffer?: { total_minor?: number; premium_minor?: number; notes?: string } | null;
};
export type ProposalRequirement = {
  code: string;
  label?: string;
  name?: unknown;
  mandatory?: boolean;
};
export type ProposalDocumentLink = {
  id: string;
  document_id: string;
  requirement_code: string;
  status: string;
  review_notes?: string | null;
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
  created_at?: string;
  updated_at?: string;
  provider_reference?: string | null;
  policy_id?: string | null;
};
export type Policy = {
  id: string;
  policy_number: string;
  status: string;
  coverage_starts_at: string;
  coverage_ends_at: string;
  carrier_id: string;
  certificates?: unknown[];
  proposal_id?: string | null;
  party_id?: string;
  premium_minor?: number | null;
  currency?: string;
  issued_at?: string | null;
  certificate_number?: string | null;
  previous_policy_id?: string | null;
  terms_snapshot?: Proposal["terms_snapshot"] | null;
  carrier?: { id?: string; party?: { display_name?: string } } | null;
};
/** download_url is absent until a signed PDF exists - never open blindly. */
export type PolicyCertificate = {
  id: string;
  label?: string;
  serial_number?: string;
  status?: string;
  issued_at?: string;
  download_url?: string | null;
  expires_at?: string;
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
  payment: Pick<Payment, "id" | "proposal_id" | "status"> | null;
  policy:
    | (Pick<Policy, "id" | "policy_number" | "status"> &
        Partial<Pick<Policy, "coverage_starts_at" | "coverage_ends_at" | "issued_at" | "certificate_number">>)
    | null;
  coverage_starts_at?: string | null;
  coverage_ends_at?: string | null;
  carrier_name?: string | null;
  product_name?: string | null;
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
export const InvitationApi = {
  /** POST /invitations/accept — bearer-authenticated; the invitation must be
   * addressed to the signed-in user's phone or email. */
  accept: (token: string) =>
    api<{ membership_id: string; tenant_id: string; role_code: string }>("/invitations/accept", {
      method: "POST",
      body: JSON.stringify({ token }),
      idempotent: true,
    }),
};
export type SupportContacts = {
  email: string | null;
  phone: string | null;
  whatsapp: string | null;
  whatsapp_url: string | null;
  partner_email: string | null;
};
let supportContactsCache: Promise<SupportContacts | null> | null = null;
/** GET /public/support-contacts — managed in the admin panel. Cached in
 * memory for the app session; a failure is not cached so it retries. */
export const SupportContactsApi = {
  get: () => {
    if (!supportContactsCache)
      supportContactsCache = api<SupportContacts>("/public/support-contacts", {
        anonymous: true,
      }).catch(() => {
        supportContactsCache = null;
        return null;
      });
    return supportContactsCache;
  },
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
  list: () => api<any>("/mobile/claims"),
  show: (id: string) => api<Claim>(`/mobile/claims/${id}`),
  /** Body built from form claim_fnol (policy_id, incident_at, incident_type, incident_location, description, …). */
  create: (payload: {
    policy_id: string;
    incident_at: string;
    incident_location?: string;
    description: string;
    [key: string]: unknown;
  }) =>
    api<Claim>("/mobile/claims", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  uploadEvidence: (id: string, form: FormData) =>
    api<ClaimEvidence>(`/mobile/claims/${id}/evidence`, {
      method: "POST",
      body: form,
      idempotent: true,
      timeoutMs: 45000,
    }),
  submitDeclaration: (id: string) =>
    api<Claim>(`/mobile/claims/${id}/incident`, {
      method: "PUT",
      body: JSON.stringify({ declaration_confirmed: true }),
      idempotent: true,
    }),
  appeal: (id: string, reason: string) =>
    api<Claim>(`/mobile/claims/${id}/appeals`, {
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
      stepUpPurpose: "CLAIM_SETTLEMENT_DECISION",
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
      stepUpPurpose: "COMMISSION_WITHDRAWAL",
    }),
  offlineQueue: () => api<OfflineFieldItem[]>("/mobile/agent/offline-queue"),
  retryOffline: (id: string) =>
    api<OfflineFieldItem>(`/mobile/agent/offline-queue/${id}/retry`, {
      method: "POST",
      idempotent: true,
    }),
};
export type BrokerClient = {
  id: string;
  full_name: string;
  phone_e164: string;
  city: string;
  origin_locked: boolean;
  policies: number;
  outstanding_minor: number;
  renewal_due_at?: string | null;
};
export type BrokerProduction = {
  id: string;
  policy_number: string;
  customer_name: string;
  carrier_name: string;
  premium_minor: number;
  status: string;
  issued_at: string;
};
export type BrokerComplianceItem = {
  id: string;
  label: string;
  status: string;
  due_at: string;
  severity: string;
};
export type BrokerPublication = {
  id: string;
  product_name: string;
  status: string;
  channel: string;
  submitted_at: string;
};
export const BrokerApi = {
  dashboard: () =>
    api<{ metrics: { label: string; value: string; tone?: string }[] }>(
      "/mobile/broker/dashboard",
    ),
  clients: () => api<BrokerClient[]>("/mobile/broker/clients"),
  client: (id: string) => api<BrokerClient>(`/mobile/broker/clients/${id}`),
  production: () => api<BrokerProduction[]>("/mobile/broker/production"),
  renewals: () => api<AgentRenewal[]>("/mobile/broker/renewals"),
  receivables: () =>
    api<
      {
        id: string;
        customer_name: string;
        amount_minor: number;
        currency: "XAF";
        status: string;
        due_at: string;
      }[]
    >("/mobile/broker/receivables"),
  compliance: () => api<BrokerComplianceItem[]>("/mobile/broker/compliance"),
  publications: () =>
    api<BrokerPublication[]>("/mobile/broker/marketplace-publications"),
  togglePublication: (id: string, enabled: boolean) =>
    api<BrokerPublication>(`/mobile/broker/marketplace-publications/${id}`, {
      method: "PATCH",
      body: JSON.stringify({ enabled }),
      idempotent: true,
    }),
};
export type CarrierReferral = {
  id: string;
  quote_id: string;
  customer_name: string;
  product: string;
  reason: string;
  status: string;
  premium_minor: number;
  submitted_at: string;
  decision_note?: string;
};
export type CarrierQueueItem = {
  id: string;
  reference: string;
  subject: string;
  status: string;
  priority: string;
  submitted_at: string;
};
export type CarrierSettlement = {
  id: string;
  period: string;
  gross_premium_minor: number;
  net_payable_minor: number;
  currency: "XAF";
  status: string;
};
export const CarrierApi = {
  dashboard: () =>
    api<{ metrics: { label: string; value: string; tone?: string }[] }>(
      "/mobile/carrier/dashboard",
    ),
  referrals: () => api<CarrierReferral[]>("/mobile/carrier/referrals"),
  referral: (id: string) =>
    api<CarrierReferral>(`/mobile/carrier/referrals/${id}`),
  decideReferral: (
    id: string,
    decision: "APPROVE" | "DECLINE" | "MORE_INFORMATION",
    note: string,
  ) =>
    api<CarrierReferral>(`/mobile/carrier/referrals/${id}/decision`, {
      method: "POST",
      body: JSON.stringify({ decision, note }),
      idempotent: true,
    }),
  issuance: () => api<CarrierQueueItem[]>("/mobile/carrier/issuance"),
  claims: () => api<CarrierQueueItem[]>("/mobile/carrier/claims"),
  settlements: () => api<CarrierSettlement[]>("/mobile/carrier/settlements"),
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
      idempotencyKey: payload.idempotency_key,
    }),
  initiatePayment: (id: string, idempotencyKey?: string) =>
    api<Payment>(`/payments/${id}/initiate`, {
      method: "POST",
      idempotent: true,
      idempotencyKey,
    }),
  payment: (id: string) => api<Payment>(`/payments/${id}`),
  purchaseStatus: (proposalId: string) =>
    api<PurchaseStatus>(`/mobile/purchases/${proposalId}/status`),
  /** @deprecated tenant-wide staff list - use WalletApi (owned policies). */
  policies: () => api<any>("/policies"),
  policy: (id: string) => api<Policy>(`/policies/${id}`),
};

export type ProposalSummary = Pick<Proposal, "id" | "proposal_number" | "status"> &
  Partial<Proposal> & { product_name?: string; carrier_name?: string };
export const ProposalsApi = {
  /** GET /mobile/proposals when the backend exposes it; callers fall back. */
  list: (page = 1) => apiPage<ProposalSummary>("/mobile/proposals", page),
  show: (id: string) => api<Proposal>(`/proposals/${id}`),
  /**
   * Two steps: register the file (POST /mobile/documents), then link it to
   * the proposal requirement (POST /proposals/{id}/documents).
   */
  async uploadDocument(
    id: string,
    input: { requirement_code: string; mime_type: string; file_base64: string },
  ) {
    const doc = await api<{ id: string }>("/mobile/documents", {
      method: "POST",
      body: JSON.stringify({
        category: `PROPOSAL_${input.requirement_code}`.slice(0, 48),
        mime_type: input.mime_type,
        file_base64: input.file_base64,
      }),
      timeoutMs: 60000,
      idempotent: true,
    });
    return api<ProposalDocumentLink>(`/proposals/${id}/documents`, {
      method: "POST",
      body: JSON.stringify({
        document_id: doc.id,
        requirement_code: input.requirement_code,
      }),
      idempotent: true,
    });
  },
};

export type RiskSchemaPayload = {
  line_code: string;
  steps: { key: string; title: string; fields: unknown[] }[];
};
export const CatalogueApi = {
  riskSchema: (lineCode: string) =>
    api<RiskSchemaPayload>(
      `/mobile/catalogue/lines/${encodeURIComponent(lineCode.toUpperCase())}/risk-schema`,
      { networkRetries: 0, timeoutMs: 8000 },
    ),
};

/** Cameroon vehicle master data (public read, authenticated manual-entry review). */
export const VehiclesApi = {
  makes: (q: string, opts: { chinese?: boolean; segment?: string; limit?: number } = {}) => {
    const params = new URLSearchParams();
    if (q.trim()) params.set("q", q.trim());
    if (opts.chinese) params.set("chinese", "1");
    if (opts.segment) params.set("segment", opts.segment);
    params.set("limit", String(opts.limit ?? 30));
    return api<unknown>(`/public/vehicles/makes?${params.toString()}`, { anonymous: true, envelope: true, timeoutMs: 8000 });
  },
  models: (makeCode: string, q = "") =>
    api<unknown>(`/public/vehicles/makes/${encodeURIComponent(makeCode)}/models${q.trim() ? `?q=${encodeURIComponent(q.trim())}` : ""}`, { anonymous: true, envelope: true, timeoutMs: 8000 }),
  reference: () => api<unknown>("/public/vehicles/reference", { anonymous: true, envelope: true, timeoutMs: 8000 }),
  /** CUST-007: empty list until the master has generations for the model. */
  generations: (modelCode: string) =>
    api<unknown>(`/public/vehicles/models/${encodeURIComponent(modelCode)}/generations`, { anonymous: true, envelope: true, timeoutMs: 8000 }),
  variants: (modelCode: string, generationCode: string, year?: string) =>
    api<unknown>(
      `/public/vehicles/models/${encodeURIComponent(modelCode)}/generations/${encodeURIComponent(generationCode)}/variants${year ? `?year=${encodeURIComponent(year)}` : ""}`,
      { anonymous: true, envelope: true, timeoutMs: 8000 },
    ),
  /** Canonical "not listed" intake (REQ-DUP-013): data {status, value: {make, model} | null, review: {id, …} | null}. */
  suggest: (payload: { domain: "vehicle"; list: "makes" | "models"; text: string; parent?: string; attributes?: Record<string, string | number> }) =>
    api<{ status: string; value: { make: string; model: string | null } | null; review: { id: string; status: string } | null }>("/master-data/suggestions", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
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
/** Old shape used reference/confirmed_at; new adds receipt_number/issued_at/download_url. */
export type PaymentReceipt = {
  id: string;
  payment_id?: string;
  receipt_number?: string;
  issued_at?: string;
  reference?: string | null;
  confirmed_at?: string | null;
  requested_at?: string | null;
  provider?: string;
  payer_phone_e164?: string;
  status?: string;
  amount_minor: number;
  currency: "XAF";
  download_url?: string | null;
};
export type RefundRequest = {
  id: string;
  payment_id?: string;
  reason?: string;
  reason_code?: string;
  amount_minor?: number;
  status: string;
  created_at?: string;
};
export type WalletDocument = {
  id: string;
  label?: string;
  type?: string;
  status?: string;
  issued_at?: string;
  download_url?: string | null;
};
export type WalletPolicy = Policy & {
  carrier_name?: string | null;
  product_name?: string | null;
  documents?: WalletDocument[];
  delivery?: StickerDelivery | null;
  insured_object?: string | Record<string, unknown> | null;
  risk_asset?: { id?: string; label?: string; registration_number?: string } | null;
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
  /** POST /mobile/assets with the server contract {type, display_name, external_reference, facts}. */
  createVehicle: (payload: { display_name: string; registration_number?: string; facts: Record<string, unknown> }) =>
    api<RiskAsset>("/mobile/assets", {
      method: "POST",
      body: JSON.stringify({
        type: "VEHICLE",
        display_name: payload.display_name,
        external_reference: payload.registration_number || null,
        facts: payload.facts,
      }),
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
  list: (page = 1) => apiPage<Payment>("/mobile/payments", page),
  show: (id: string) => api<Payment>(`/mobile/payments/${id}`),
  retry: (id: string) =>
    api<Payment>(`/mobile/payments/${id}/retry`, {
      method: "POST",
      idempotent: true,
    }),
  receipt: (id: string) =>
    api<PaymentReceipt>(`/mobile/payments/${id}/receipt`),
  /**
   * New contract: {reason, amount_minor?, reason_code?}. notes and
   * idempotency_key are still sent for the older validator. The same key
   * goes in the header so a retried submit cannot open two refunds.
   */
  refund: (
    id: string,
    payload: {
      reason: string;
      notes: string;
      reason_code: string;
      amount_minor: number;
      idempotency_key: string;
    },
  ) =>
    api<RefundRequest>(`/mobile/payments/${id}/refunds`, {
      method: "POST",
      body: JSON.stringify(payload),
      idempotencyKey: payload.idempotency_key,
      stepUpPurpose: "PAYMENT_REFUND_REQUEST",
    }),
};
export const WalletApi = {
  list: (page = 1) => apiPage<WalletPolicy>("/mobile/wallet", page),
  /** Every owned policy (walks pages; capped to keep the app responsive). */
  async all(maxPages = 5) {
    const items: WalletPolicy[] = [];
    for (let page = 1; page <= maxPages; page++) {
      const result = await apiPage<WalletPolicy>("/mobile/wallet", page);
      items.push(...result.items);
      if (!result.info.hasMore) break;
    }
    return items;
  },
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
  history: (page = 1) => apiPage<CustomerQuoteSummary>("/mobile/quotes", page),
  show: (id: string) =>
    api<QuoteResult & { summary?: CustomerQuoteSummary }>(
      `/mobile/quotes/${id}`,
    ),
  /** Returns {quote, offers} today; {quote_id, next_path} in older builds. */
  resume: (id: string) =>
    api<Partial<QuoteResult> & { quote_id?: string; next_path?: string | null }>(
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
  /** Canonical create (REQ-DUP-014): POST /policies/{id}/service-requests. */
  create: ({ policy_id, ...payload }: { policy_id: string; type: string; reason: string }) =>
    api<PolicyServiceCase>(`/policies/${encodeURIComponent(policy_id)}/service-requests`, {
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

export type SyncReceipt = {
  operation_id: string;
  status: "APPLIED" | "DUPLICATE";
  server_version: number;
  synchronized_at: string;
};

export const SyncApi = {
  status: () => api<{ server_time: string; minimum_client_version: string }>("/mobile/sync/status"),
  apply: (operation: OfflineOperation) =>
    api<SyncReceipt>("/mobile/sync/operations", {
      method: "POST",
      body: JSON.stringify(operation),
      idempotent: true,
      timeoutMs: 30000,
    }),
};

export type RuntimeBootstrap = {
  /** Optional {name, demo_mode, banner}: a banner (DEMO, STAGING, …) is shown when set. */
  environment?: { name?: string; demo_mode?: boolean; banner?: string | null } | null;
  release: {
    minimum_version: string;
    force_update: boolean;
    store_url: string | null;
  };
  maintenance: {
    active: boolean;
    message: string | null;
    ends_at: string | null;
  };
  services: {
    key: string;
    status: "OPERATIONAL" | "DEGRADED" | "UNAVAILABLE";
    message?: string;
  }[];
  security: {
    step_up_ttl_seconds: number;
    device_risk_action: "ALLOW" | "LIMIT" | "BLOCK";
    /** Optional server overrides for the app lock (seconds). */
    relock_grace_seconds?: number | null;
    session_idle_timeout_seconds?: number | null;
  };
  /** Optional Play/App Store legal links; the app falls back to the
   * insurance.opesdatacenter.tech defaults when absent. */
  legal?: {
    privacy_policy_url?: string | null;
    account_deletion_url?: string | null;
    terms_url?: string | null;
  };
};
export const RuntimeApi = {
  bootstrap: (params: {
    version: string;
    build: string;
    channel: string;
  }) =>
    api<RuntimeBootstrap>(
      `/mobile/runtime/bootstrap?version=${encodeURIComponent(params.version)}&build=${encodeURIComponent(params.build)}&channel=${encodeURIComponent(params.channel)}`,
      { anonymous: true, timeoutMs: 8000 },
    ),
  telemetry: (payload: {
    event: string;
    correlation_id: string;
    app_version: string;
    release_channel: string;
    attributes: Record<string, unknown>;
  }) =>
    api<void>("/mobile/runtime/telemetry", {
      method: "POST",
      body: JSON.stringify(payload),
      anonymous: true,
      idempotent: true,
      timeoutMs: 5000,
    }),
};
// Attaches the auth header when a session exists (so the report is
// attributed) but never requires one — this must also work from the
// pre-auth screens, matching RuntimeApi's telemetry() endpoint.
export const IssueReportApi = {
  report: (payload: { route: string; note: string }) =>
    api<{ accepted: boolean; id: string }>("/mobile/issue-reports", {
      method: "POST",
      body: JSON.stringify({
        ...payload,
        platform: Platform.OS,
        app_version: environmentConfig.appVersion,
      }),
      timeoutMs: 8000,
    }),
};
export const StepUpApi = {
  request: (purpose: string) =>
    api<{ challenge_id: string; delivery_hint: string; expires_in: number }>(
      "/mobile/security/step-up/request",
      {
        method: "POST",
        body: JSON.stringify({ purpose }),
        idempotent: true,
      },
    ),
  verify: async (challenge_id: string, purpose: string, code: string) => {
    const grant = await api<StepUpGrant>("/mobile/security/step-up/verify", {
      method: "POST",
      body: JSON.stringify({ challenge_id, purpose, code }),
      idempotent: true,
    });
    await StepUpVault.save(grant);
    return grant;
  },
};

export type DeviceRiskResult = {
  assessment_id: string;
  action: "ALLOW" | "LIMIT" | "BLOCK";
  reasons: string[];
  expires_at: string;
};
export const DeviceSecurityApi = {
  nonce: () =>
    api<{ nonce: string; expires_at: string }>(
      "/mobile/security/device-attestation/nonce",
      { method: "POST", idempotent: true },
    ),
  assess: (payload: {
    nonce: string;
    platform: "ANDROID" | "IOS";
    provider: "PLAY_INTEGRITY" | "APP_ATTEST" | "UNAVAILABLE_MANAGED_RUNTIME";
    attestation_token: string | null;
    app_version: string;
  }) =>
    api<DeviceRiskResult>("/mobile/security/device-attestation/assess", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
      timeoutMs: 20000,
    }),
};
