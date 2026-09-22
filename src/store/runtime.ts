import { create } from "zustand";
import { RuntimeApi, RuntimeBootstrap } from "@/api/client";
import { environmentConfig, productionConfigurationIssues } from "@/config/environment";

export type RuntimeGate = "checking" | "ready" | "maintenance" | "update_required" | "configuration_error";
type RuntimeState = {
  gate: RuntimeGate;
  bootstrap: RuntimeBootstrap | null;
  issues: string[];
  check: () => Promise<void>;
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

export const useRuntime = create<RuntimeState>((set) => ({
  gate: "checking",
  bootstrap: null,
  issues: [],
  async check() {
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
      set({ gate: "ready", bootstrap: null, issues: [] });
    }
  },
}));
