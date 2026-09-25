import { create } from "zustand";
import { RuntimeApi, RuntimeBootstrap } from "@/api/client";
import { environmentConfig, productionConfigurationIssues } from "@/config/environment";
import { runtimeCheckDue } from "@/lib/appLock";

export type RuntimeGate = "checking" | "ready" | "maintenance" | "update_required" | "configuration_error";
type RuntimeState = {
  gate: RuntimeGate;
  bootstrap: RuntimeBootstrap | null;
  issues: string[];
  lastCheckedAt: number | null;
  check: () => Promise<void>;
  /** Foreground check, throttled (no background polling). */
  checkIfDue: () => Promise<void>;
};

const versionParts = (value: string) => value.split(".").map((part) => Number(part) || 0);
const olderThan = (current: string, minimum: string) => {
  const left = versionParts(current);
  const right = versionParts(minimum);
  for (let index = 0; index < 3; index += 1) {
    if ((left[index] ?? 0) < (right[index] ?? 0)) return true;
    if ((left[index] ?? 0) > (right[index] ?? 0)) return false;
  }
  return false;
};

export const useRuntime = create<RuntimeState>((set, get) => ({
  gate: "checking",
  bootstrap: null,
  issues: [],
  lastCheckedAt: null,
  async checkIfDue() {
    // A gate other than ready (maintenance, update) is re-checked every time.
    if (get().gate === "ready" && !runtimeCheckDue(get().lastCheckedAt, Date.now())) return;
    await get().check();
  },
  async check() {
    set({ lastCheckedAt: Date.now() });
    const issues = productionConfigurationIssues();
    if (issues.length) {
      set({ gate: "configuration_error", issues, bootstrap: null });
      return;
    }
    try {
      const bootstrap = await RuntimeApi.bootstrap({
        version: environmentConfig.appVersion,
        build: environmentConfig.buildVersion,
        channel: environmentConfig.releaseChannel,
      });
      const gate = bootstrap.maintenance.active
        ? "maintenance"
        : bootstrap.release.force_update || olderThan(environmentConfig.appVersion, bootstrap.release.minimum_version)
          ? "update_required"
          : "ready";
      set({ gate, bootstrap, issues: [] });
    } catch {
      // A transient bootstrap failure does not assert a false maintenance state.
      // Keep the last good bootstrap (lock policy, legal links) and allow a
      // re-check on the next foreground.
      set({ gate: "ready", bootstrap: get().bootstrap, issues: [], lastCheckedAt: null });
    }
  },
}));
