import { create } from "zustand";
import { AppState } from "react-native";
import { api } from "@/api/client";
import { useSession } from "@/store/session";
import { Capabilities, capabilityAllows, gateWithCapability, parseCapabilities } from "@/lib/capabilities";

/**
 * Cached GET /mobile/capabilities for the active workspace. Refreshed on
 * sign-in, workspace switch and app foreground; cleared on sign-out. A failed
 * or missing answer leaves `caps` null so every gate keeps its current rule.
 */
type CapabilityState = {
  caps: Capabilities | null;
  key: string | null;
  refresh: () => Promise<void>;
  clear: () => void;
};

let inflight: Promise<void> | null = null;

export const useCapabilities = create<CapabilityState>((set, get) => ({
  caps: null,
  key: null,
  async refresh() {
    const { status, activeWorkspace } = useSession.getState();
    if (status !== "authenticated" || !activeWorkspace) {
      get().clear();
      return;
    }
    const key = activeWorkspace.membership_id;
    if (inflight) return inflight;
    inflight = (async () => {
      try {
        const raw = await api<unknown>("/mobile/capabilities", { timeoutMs: 10000, networkRetries: 0 });
        // Ignore an answer for a workspace the user already left.
        if (useSession.getState().activeWorkspace?.membership_id === key) set({ caps: parseCapabilities(raw), key });
      } catch {
        // Older backend (404) / offline: drop stale caps for another workspace only.
        if (get().key !== key) set({ caps: null, key: null });
      } finally {
        inflight = null;
      }
    })();
    return inflight;
  },
  clear: () => set({ caps: null, key: null }),
}));

// Sign-in / workspace switch / sign-out.
useSession.subscribe((state, prev) => {
  const ws = state.status === "authenticated" ? state.activeWorkspace?.membership_id ?? null : null;
  const before = prev.status === "authenticated" ? prev.activeWorkspace?.membership_id ?? null : null;
  if (ws === before) return;
  if (!ws) useCapabilities.getState().clear();
  else {
    if (useCapabilities.getState().key !== ws) useCapabilities.getState().clear();
    void useCapabilities.getState().refresh();
  }
});
AppState.addEventListener("change", (next) => {
  if (next === "active" && useSession.getState().status === "authenticated") void useCapabilities.getState().refresh();
});

/** true/false when the server's capability says so; null when unknown. */
export const useCapability = (module: string, action?: string) =>
  useCapabilities((s) => capabilityAllows(s.caps, module, action));

/** Existing gate narrowed by the capability (never widened). */
export const useCapabilityGate = (fallback: boolean, module: string | undefined, action?: string) =>
  useCapabilities((s) => gateWithCapability(fallback, s.caps, module, action));
void useCapabilities.getState().refresh();
