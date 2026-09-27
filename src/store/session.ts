import { create } from "zustand";
import { AppState } from "react-native";
import AsyncStorage from "@react-native-async-storage/async-storage";
import * as Localization from "expo-localization";
import { ApiError, AuthApi, SessionBootstrap, TokenVault, Workspace } from "@/api/client";
import { CustomerApi } from "@/api/customer";
import { Preferences } from "@/store/preferences";
import { Language } from "@/i18n/strings";
import { OfflineVault } from "@/offline/vault";
import { SecureJson } from "@/security/secureJson";
import { PaymentAttemptKeys, RecentProposals } from "@/store/insurance";
import { LANGUAGE_CHOICE_KEY, normalizeLanguage, resolveLanguage } from "@/lib/languageChoice";
import { hydrateStartStatus, statusAfterNetworkFailure } from "@/lib/navigationContinuity";

export type SessionStatus =
  "booting" | "anonymous" | "authenticating" | "authenticated" | "error";
type SessionState = {
  status: SessionStatus;
  /** True when the session was restored from the device cache while the
   * server was unreachable (offline launch). */
  offline: boolean;
  bootstrap: SessionBootstrap | null;
  activeWorkspace: Workspace | null;
  language: Language;
  error: string | null;
  hydrate: () => Promise<void>;
  completeAuthentication: (bootstrap: SessionBootstrap) => Promise<void>;
  selectWorkspace: (workspace: Workspace) => Promise<void>;
  refreshWorkspaces: (preferTenant?: string) => Promise<SessionBootstrap>;
  setLanguage: (language: Language) => void;
  signOut: () => Promise<void>;
  signOutEverywhere: () => Promise<void>;
  invalidate: () => Promise<void>;
  clearError: () => void;
};

/** Last good bootstrap, so an offline cold start keeps the user signed in.
 * It holds PII (name, phone, workspaces), so it lives in device-only
 * SecureStore chunks, never in plain AsyncStorage. */
const CACHE_KEY = "opesinsure.session_cache.v2";
const LEGACY_CACHE_KEY = "opesinsure.session_cache";
const cacheBootstrap = async (bootstrap: SessionBootstrap | null) => {
  try {
    // Drop the pre-1.3 plain-text copy whatever happens.
    await AsyncStorage.removeItem(LEGACY_CACHE_KEY).catch(() => undefined);
    if (bootstrap) await SecureJson.write(CACHE_KEY, bootstrap);
    else await SecureJson.remove(CACHE_KEY);
  } catch {
    // Cache is best effort.
  }
};
const cachedBootstrap = async (): Promise<SessionBootstrap | null> => {
  const parsed = await SecureJson.read<SessionBootstrap | null>(CACHE_KEY, null);
  return parsed?.user && Array.isArray(parsed.workspaces) ? parsed : null;
};

/** Only a rejected credential ends the session; timeouts, DNS failures and
 * 5xx answers leave the stored tokens alone. api() has already attempted a
 * refresh-token rotation before it reports 401. */
export const isCredentialFailure = (error: unknown) =>
  error instanceof ApiError && (error.status === 401 || error.code === "SESSION_EXPIRED");

/** Device / browser language tags: expo-localization on native (it reads
 * navigator.languages on web), with navigator.language as a web fallback. */
const deviceTags = (): string[] => {
  const tags: string[] = [];
  try {
    for (const l of Localization.getLocales()) {
      if (l.languageTag) tags.push(l.languageTag);
      else if (l.languageCode) tags.push(l.languageCode);
    }
  } catch {
    // fall through to navigator
  }
  const nav = (globalThis as { navigator?: { language?: string; languages?: readonly string[] } }).navigator;
  if (nav?.languages) tags.push(...nav.languages);
  if (nav?.language) tags.push(nav.language);
  return tags;
};
/** Explicit in-app choice (null = follow the device). */
let explicitLanguage: Language | null = null;
const loadExplicitLanguage = async () => {
  try {
    explicitLanguage = normalizeLanguage(await AsyncStorage.getItem(LANGUAGE_CHOICE_KEY));
  } catch {
    explicitLanguage = null;
  }
  return explicitLanguage;
};
const currentLanguage = (): Language => resolveLanguage(explicitLanguage, deviceTags());

const pickWorkspace = async (bootstrap: SessionBootstrap) => {
  const tenant = await TokenVault.tenant();
  let activeWorkspace =
    bootstrap.workspaces.find((w) => w.tenant_id === tenant) ?? null;
  if (!activeWorkspace && bootstrap.workspaces.length === 1) {
    activeWorkspace = bootstrap.workspaces[0] ?? null;
    if (activeWorkspace) await TokenVault.setTenant(activeWorkspace.tenant_id);
  }
  return activeWorkspace;
};

const anonymousState = {
  status: "anonymous" as const,
  offline: false,
  bootstrap: null,
  activeWorkspace: null,
};

export const useSession = create<SessionState>((set, get) => ({
  status: "booting",
  offline: false,
  bootstrap: null,
  activeWorkspace: null,
  language: currentLanguage(),
  error: null,
  async hydrate() {
    // A signed-in user is refreshed silently: flipping to "booting" would
    // drop every guarded screen and lose the user's place (see
    // src/lib/navigationContinuity.ts).
    const previous = get().status;
    set({ status: hydrateStartStatus(previous), error: null });
    await loadExplicitLanguage();
    set({ language: currentLanguage() });
    const [token, refresh] = await Promise.all([TokenVault.access(), TokenVault.refresh()]);
    if (!token && !refresh) {
      set({ ...anonymousState });
      return;
    }
    try {
      const bootstrap = await AuthApi.session();
      const activeWorkspace = await pickWorkspace(bootstrap);
      await cacheBootstrap(bootstrap);
      set({
        status: "authenticated",
        offline: false,
        bootstrap,
        activeWorkspace,
        language: currentLanguage(),
        error: null,
      });
    } catch (error) {
      if (isCredentialFailure(error)) {
        await TokenVault.clear();
        await cacheBootstrap(null);
        set({ ...anonymousState, error: "SESSION_EXPIRED" });
        return;
      }
      // Network / timeout / server error: keep the tokens and restore the
      // last known session so an offline launch stays signed in.
      const cached = await cachedBootstrap();
      if (cached) {
        set({
          status: "authenticated",
          offline: true,
          bootstrap: cached,
          activeWorkspace: await pickWorkspace(cached),
          language: currentLanguage(),
          error: null,
        });
        return;
      }
      if (statusAfterNetworkFailure(previous) === "authenticated") {
        set({ offline: true, error: null });
        return;
      }
      set({
        status: "error",
        offline: true,
        bootstrap: null,
        activeWorkspace: null,
        error: error instanceof Error ? error.message : "NETWORK_UNAVAILABLE",
      });
    }
  },
  async completeAuthentication(bootstrap) {
    const first =
      bootstrap.workspaces.length === 1
        ? (bootstrap.workspaces[0] ?? null)
        : null;
    if (first) await TokenVault.setTenant(first.tenant_id);
    await cacheBootstrap(bootstrap);
    set({
      status: "authenticated",
      offline: false,
      bootstrap,
      activeWorkspace: first,
      language: currentLanguage(),
      error: null,
    });
  },
  async selectWorkspace(workspace) {
    const allowed = get().bootstrap?.workspaces.some(
      (w) => w.membership_id === workspace.membership_id,
    );
    if (!allowed) throw new Error("Workspace is not assigned to this account.");
    await TokenVault.setTenant(workspace.tenant_id);
    set({ activeWorkspace: workspace });
  },
  async refreshWorkspaces(preferTenant) {
    const bootstrap = await AuthApi.session();
    const current = get().activeWorkspace;
    const next =
      (preferTenant
        ? bootstrap.workspaces.find((w) => w.tenant_id === preferTenant)
        : undefined) ??
      bootstrap.workspaces.find(
        (w) => w.membership_id === current?.membership_id,
      ) ??
      (bootstrap.workspaces.length === 1 ? bootstrap.workspaces[0] : null) ??
      null;
    if (next) await TokenVault.setTenant(next.tenant_id);
    await cacheBootstrap(bootstrap);
    set({ bootstrap, activeWorkspace: next, status: "authenticated", offline: false });
    return bootstrap;
  },
  setLanguage: (language) => {
    explicitLanguage = language;
    AsyncStorage.setItem(LANGUAGE_CHOICE_KEY, language).catch(() => undefined);
    set({ language });
  },
  async signOut() {
    try {
      await AuthApi.logout();
    } finally {
      await OfflineVault.clearSensitiveData();
      await PaymentAttemptKeys.clear().catch(() => undefined);
      await RecentProposals.clear();
      await cacheBootstrap(null);
      await Preferences.clearPersonal();
      set({ ...anonymousState, error: null });
    }
  },
  /** POST /auth/mobile/logout-all, then the normal local sign-out. Throws
   * (without signing out) when the server could not revoke the sessions. */
  async signOutEverywhere() {
    await CustomerApi.logoutAll();
    await TokenVault.clear();
    await OfflineVault.clearSensitiveData();
    await PaymentAttemptKeys.clear().catch(() => undefined);
    await RecentProposals.clear();
    await cacheBootstrap(null);
    await Preferences.clearPersonal();
    set({ ...anonymousState, error: null });
  },
  async invalidate() {
    await TokenVault.clear();
    await cacheBootstrap(null);
    set({ ...anonymousState, error: "SESSION_EXPIRED" });
  },
  clearError: () => set({ error: null }),
}));

export type Portal =
  | "customer"
  | "agent"
  | "broker_admin"
  | "broker_staff"
  | "carrier"
  | "platform_admin"
  | "compliance"
  | "finance"
  | "claims";

/** Maps the backend's tenant_memberships.role_code values (see
 * database/seeders) to a mobile portal. Unknown codes return null, which the
 * app renders as the "Access not available" screen — never a blank page. */
export const roleToPortal = (role: string | null | undefined): Portal | null => {
  switch ((role ?? "").toUpperCase()) {
    case "CUSTOMER":
      return "customer";
    case "AGENT":
    case "FREELANCE_AGENT":
      return "agent";
    case "BROKER_ADMIN":
    case "BROKER":
      return "broker_admin";
    case "BROKER_STAFF":
      return "broker_staff";
    case "CARRIER_ADMIN":
    case "CARRIER_STAFF":
    case "CARRIER":
      return "carrier";
    case "PLATFORM_ADMIN":
    case "SYSTEM_ADMIN":
      return "platform_admin";
    case "COMPLIANCE_ADMIN":
    case "COMPLIANCE_OFFICER":
      return "compliance";
    case "FINANCE_ADMIN":
    case "FINANCE_MANAGER":
    case "FINANCE_OPERATOR":
    case "FINANCE_OFFICER":
      return "finance";
    case "CLAIMS_MANAGER":
    case "CLAIMS_OFFICER":
      return "claims";
    default:
      return null;
  }
};

export const WORKSPACE_PORTALS: Portal[] = [
  "platform_admin",
  "compliance",
  "finance",
  "claims",
];

/** The route a workspace lands on. Unknown roles go to the access screen. */
export const portalRoute = (workspace: Workspace | null | undefined) => {
  const portal = roleToPortal(workspace?.role_code);
  switch (portal) {
    case "customer":
      return "/(customer)/(tabs)" as const;
    case "agent":
      return "/agent" as const;
    case "broker_admin":
    case "broker_staff":
      return "/broker" as const;
    case "carrier":
      return "/carrier" as const;
    case null:
      return "/access-denied" as const;
    default:
      return { pathname: "/workspace/[role]" as const, params: { role: portal } };
  }
};

/** Where a session should land: sign-in when anonymous, the role picker when
 * several workspaces exist and none is chosen, otherwise the portal. */
export const sessionHome = (state: {
  status: SessionStatus;
  bootstrap: SessionBootstrap | null;
  activeWorkspace: Workspace | null;
}) => {
  if (state.status !== "authenticated") return null;
  if (state.activeWorkspace) return portalRoute(state.activeWorkspace);
  return "/(auth)/role" as const;
};

/** Re-check the device language when the app returns to the foreground
 * (the user may have changed it in system settings). */
AppState.addEventListener("change", (next) => {
  if (next !== "active") return;
  const language = currentLanguage();
  if (useSession.getState().language !== language) useSession.setState({ language });
});
