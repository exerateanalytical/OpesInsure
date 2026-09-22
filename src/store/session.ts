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
  setLanguage: (language: Language) => void;
  signOut: () => Promise<void>;
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
      const activeWorkspace =
        bootstrap.workspaces.find((w) => w.tenant_id === tenant) ?? null;
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
  clearError: () => set({ error: null }),
}));

export const roleToPortal = (role: string) => {
  if (role === "CUSTOMER") return "customer";
  if (["FREELANCE_AGENT", "AGENT"].includes(role)) return "agent";
  if (role === "BROKER_STAFF") return "broker_staff";
  if (role === "BROKER_ADMIN") return "broker_admin";
  if (role.includes("CARRIER")) return "carrier";
  if (
    [
      "SYSTEM_ADMIN",
      "PLATFORM_ADMIN",
      "FINANCE_OPERATOR",
      "COMPLIANCE_OFFICER",
      "SUPPORT_OPERATOR",
    ].includes(role)
  )
    return "platform_admin";
  return null;
};
