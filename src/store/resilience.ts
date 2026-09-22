import { create } from "zustand";
import * as Network from "expo-network";
import { ApiError, SyncApi } from "@/api/client";
import { OfflineVault } from "@/offline/vault";
import { OfflineOperation, SyncSettings, SyncSummary } from "@/offline/types";

type ResilienceState = {
  online: boolean;
  syncing: boolean;
  queue: OfflineOperation[];
  settings: SyncSettings;
  summary: SyncSummary;
  hydrate: () => Promise<void>;
  refreshNetwork: () => Promise<boolean>;
  syncNow: () => Promise<void>;
  retry: (id: string) => Promise<void>;
  discard: (id: string) => Promise<void>;
  updateSettings: (settings: SyncSettings) => Promise<void>;
};

const summarize = (
  queue: OfflineOperation[],
  last_synced_at: string | null,
): SyncSummary => ({
  pending: queue.filter((x) => x.state === "PENDING").length,
  failed: queue.filter((x) => x.state === "FAILED").length,
  conflicts: queue.filter((x) => x.state === "CONFLICT").length,
  last_synced_at,
});

export const useResilience = create<ResilienceState>((set, get) => ({
  online: true,
  syncing: false,
  queue: [],
  settings: {
    low_data_mode: true,
    wifi_only_uploads: false,
    compress_images: true,
  },
  summary: { pending: 0, failed: 0, conflicts: 0, last_synced_at: null },
  async hydrate() {
    const [queue, settings, last] = await Promise.all([
      OfflineVault.list(),
      OfflineVault.settings(),
      OfflineVault.lastSync(),
    ]);
    set({ queue, settings, summary: summarize(queue, last) });
    await get().refreshNetwork();
  },
  async refreshNetwork() {
    const network = await Network.getNetworkStateAsync();
    const online =
      network.isConnected !== false && network.isInternetReachable !== false;
    set({ online });
    return online;
  },
  async syncNow() {
    if (get().syncing || !(await get().refreshNetwork())) return;
    set({ syncing: true });
    let queue = [...get().queue];
    for (const operation of queue.filter((x) => x.state !== "CONFLICT")) {
      const current = { ...operation, state: "SYNCING" as const };
      queue = queue.map((x) => (x.id === current.id ? current : x));
      set({ queue });
      try {
        await SyncApi.apply(current);
        queue = queue.filter((x) => x.id !== current.id);
      } catch (error) {
        const apiError = error as ApiError;
        queue = queue.map((x) =>
          x.id === current.id
            ? {
                ...current,
                state: apiError.status === 409 ? "CONFLICT" : "FAILED",
                attempts: current.attempts + 1,
                updated_at: new Date().toISOString(),
                error_code: apiError.code ?? "SYNC_FAILED",
              }
            : x,
        );
      }
      await OfflineVault.replace(queue);
    }
    const last = new Date().toISOString();
    await OfflineVault.setLastSync(last);
    set({ queue, syncing: false, summary: summarize(queue, last) });
  },
  async retry(id) {
    const queue = get().queue.map((x) =>
      x.id === id
        ? { ...x, state: "PENDING" as const, error_code: undefined }
        : x,
    );
    await OfflineVault.replace(queue);
    set({ queue, summary: summarize(queue, get().summary.last_synced_at) });
    await get().syncNow();
  },
  async discard(id) {
    await OfflineVault.remove(id);
    const queue = get().queue.filter((x) => x.id !== id);
    set({ queue, summary: summarize(queue, get().summary.last_synced_at) });
  },
  async updateSettings(settings) {
    await OfflineVault.saveSettings(settings);
    set({ settings });
  },
}));
