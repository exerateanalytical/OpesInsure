import * as Crypto from "expo-crypto";
import * as SecureStore from "expo-secure-store";
import { OfflineOperation, SyncSettings } from "@/offline/types";

const queueKey = "opesinsure.offline.queue.v1";
const settingsKey = "opesinsure.offline.settings.v1";
const lastSyncKey = "opesinsure.offline.last_sync.v1";
const maxSecurePayloadBytes = 6000;
const defaults: SyncSettings = {
  low_data_mode: true,
  wifi_only_uploads: false,
  compress_images: true,
};

async function readJson<T>(key: string, fallback: T): Promise<T> {
  const value = await SecureStore.getItemAsync(key);
  if (!value) return fallback;
  try {
    return JSON.parse(value) as T;
  } catch {
    return fallback;
  }
}

async function writeJson(key: string, value: unknown) {
  const encoded = JSON.stringify(value);
  if (new TextEncoder().encode(encoded).length > maxSecurePayloadBytes)
    throw new Error("OFFLINE_SECURE_PAYLOAD_TOO_LARGE");
  await SecureStore.setItemAsync(key, encoded, {
    keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
  });
}

export const OfflineVault = {
  list: () => readJson<OfflineOperation[]>(queueKey, []),
  async enqueue(
    input: Omit<
      OfflineOperation,
      "id" | "state" | "attempts" | "created_at" | "updated_at"
    >,
  ) {
    const queue = await this.list();
    const now = new Date().toISOString();
    const item: OfflineOperation = {
      ...input,
      id: Crypto.randomUUID(),
      state: "PENDING",
      attempts: 0,
      created_at: now,
      updated_at: now,
    };
    await writeJson(queueKey, [item, ...queue]);
    return item;
  },
  async replace(queue: OfflineOperation[]) {
    await writeJson(queueKey, queue);
  },
  async remove(id: string) {
    await writeJson(
      queueKey,
      (await this.list()).filter((item) => item.id !== id),
    );
  },
  settings: () => readJson<SyncSettings>(settingsKey, defaults),
  async saveSettings(settings: SyncSettings) {
    await writeJson(settingsKey, settings);
  },
  lastSync: () => SecureStore.getItemAsync(lastSyncKey),
  async setLastSync(value: string) {
    await SecureStore.setItemAsync(lastSyncKey, value);
  },
  async clearSensitiveData() {
    await Promise.all([
      SecureStore.deleteItemAsync(queueKey),
      SecureStore.deleteItemAsync(lastSyncKey),
    ]);
  },
};
