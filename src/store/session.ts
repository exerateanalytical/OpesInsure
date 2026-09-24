import { create } from "zustand";
import AsyncStorage from "@react-native-async-storage/async-storage";
import * as Localization from "expo-localization";
import { ApiError, AuthApi, SessionBootstrap, TokenVault, Workspace } from "@/api/client";
import { CustomerApi } from "@/api/customer";
import { Preferences } from "@/store/preferences";
import { Language } from "@/i18n/strings";
import { OfflineVault } from "@/offline/vault";

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

/** Last good bootstrap, so an offline cold start keeps the user signed in. */
const CACHE_KEY = "opesinsure.session_cache";
const cacheBootstrap = async (bootstrap: SessionBootstrap | null) => {
  try {
    if (bootstrap) await AsyncStorage.setItem(CACHE_KEY, JSON.stringify(bootstrap));
    else await AsyncStorage.removeItem(CACHE_KEY);
  } catch {
    // Cache is best effort.
  }
};
const cachedBootstrap = async (): Promise<SessionBootstrap | null> => {
  try {
    const raw = await AsyncStorage.getItem(CACHE_KEY);
    const parsed = raw ? (JSON.parse(raw) as SessionBootstrap) : null;
    return parsed?.user && Array.isArray(parsed.workspaces) ? parsed : null;
  } catch {
    return null;
  }
};

/** Only a rejected credential ends the session; timeouts, DNS failures and
 * 5xx answers leave the stored tokens alone. api() has already attempted a
 * refresh-token rotation before it reports 401. */
export const isCredentialFailure = (error: unknown) =>
  error instanceof ApiError && (error.status === 401 || error.code === "SESSION_EXPIRED");

const deviceLanguage = (): Language => {
  try {
    return Localization.getLocales()[0]?.languageCode === "fr" ? "fr" : "en";
  } catch {
    return "en";
  }
};

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
  language: deviceLanguage(),
  error: null,
  async hydrate() {
    set({ status: "booting", error: null });
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
        language: bootstrap.user.locale,
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
          language: cached.user.locale,
          error: null,
        });
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
      language: bootstrap.user.locale,
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
  setLanguage: (language) => set({ language }),
  async signOut() {
    try {
      await AuthApi.logout();
    } finally {
      await OfflineVault.clearSensitiveData();
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
