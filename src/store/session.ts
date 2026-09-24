import { create } from "zustand";
import { AuthApi, SessionBootstrap, TokenVault, Workspace } from "@/api/client";
import { Language } from "@/i18n/strings";
import { OfflineVault } from "@/offline/vault";

export type SessionStatus =
  "booting" | "anonymous" | "authenticating" | "authenticated" | "error";
type SessionState = {
  status: SessionStatus;
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
  invalidate: () => Promise<void>;
  clearError: () => void;
};

export const useSession = create<SessionState>((set, get) => ({
  status: "booting",
  bootstrap: null,
  activeWorkspace: null,
  language: "en",
  error: null,
  async hydrate() {
    set({ status: "booting", error: null });
    const token = await TokenVault.access();
    if (!token) {
      set({ status: "anonymous" });
      return;
    }
    try {
      const bootstrap = await AuthApi.session();
      const tenant = await TokenVault.tenant();
      let activeWorkspace =
        bootstrap.workspaces.find((w) => w.tenant_id === tenant) ?? null;
      if (!activeWorkspace && bootstrap.workspaces.length === 1) {
        activeWorkspace = bootstrap.workspaces[0] ?? null;
        if (activeWorkspace) await TokenVault.setTenant(activeWorkspace.tenant_id);
      }
      set({
        status: "authenticated",
        bootstrap,
        activeWorkspace,
        language: bootstrap.user.locale,
        error: null,
      });
    } catch (error) {
      await TokenVault.clear();
      set({
        status: "anonymous",
        bootstrap: null,
        activeWorkspace: null,
        error: error instanceof Error ? error.message : null,
      });
    }
  },
  async completeAuthentication(bootstrap) {
    const first =
      bootstrap.workspaces.length === 1
        ? (bootstrap.workspaces[0] ?? null)
        : null;
    if (first) await TokenVault.setTenant(first.tenant_id);
    set({
      status: "authenticated",
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
    set({ bootstrap, activeWorkspace: next, status: "authenticated" });
    return bootstrap;
  },
  setLanguage: (language) => set({ language }),
  async signOut() {
    try {
      await AuthApi.logout();
    } finally {
      await OfflineVault.clearSensitiveData();
      set({
        status: "anonymous",
        bootstrap: null,
        activeWorkspace: null,
        error: null,
      });
    }
  },
  async invalidate() {
    await TokenVault.clear();
    set({
      status: "anonymous",
      bootstrap: null,
      activeWorkspace: null,
      error: "SESSION_EXPIRED",
    });
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
