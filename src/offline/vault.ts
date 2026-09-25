import * as Crypto from "expo-crypto";
import * as SecureStore from "@/security/secureStore";
import { OfflineOperation, SyncSettings } from "@/offline/types";
import { DEVICE_ONLY, SecureJson } from "@/security/secureJson";
import { localizeErrorCode } from "@/api/client";

/**
 * Device-encrypted offline queue. Stored through SecureJson, which splits
 * the value into ~1.8 KB SecureStore chunks (WHEN_UNLOCKED_THIS_DEVICE_ONLY),
 * so the queue is no longer capped at one 6000-byte key. A hard cap on the
 * number of operations and on the total size keeps the keystore healthy;
 * callers get OFFLINE_QUEUE_FULL and show a message instead of crashing.
 */
const queueKey = "opesinsure.offline.queue.v2";
const legacyQueueKey = "opesinsure.offline.queue.v1";
const settingsKey = "opesinsure.offline.settings.v1";
const lastSyncKey = "opesinsure.offline.last_sync.v1";
export const MAX_QUEUE_OPERATIONS = 50;
/** Total encoded size across all chunks (≈ 110 SecureStore items). */
export const maxSecurePayloadBytes = 200_000;
const defaults: SyncSettings = {
  low_data_mode: true,
  wifi_only_uploads: false,
  compress_images: true,
};

export class OfflineQueueFullError extends Error {
  code = "OFFLINE_QUEUE_FULL";
  constructor() {
    super(localizeErrorCode("OFFLINE_QUEUE_FULL") ?? "Too many actions are waiting to sync.");
    this.name = "OfflineQueueFullError";
  }
}

async function readQueue(): Promise<OfflineOperation[]> {
  const queue = await SecureJson.read<OfflineOperation[] | null>(queueKey, null);
  if (queue) return queue;
  // One-time migration from the single-key v1 queue.
  const legacy = await SecureStore.getItemAsync(legacyQueueKey).catch(() => null);
  if (!legacy) return [];
  try {
    const parsed = JSON.parse(legacy) as OfflineOperation[];
    await SecureJson.write(queueKey, parsed);
    await SecureStore.deleteItemAsync(legacyQueueKey);
    return parsed;
  } catch {
    return [];
  }
}

async function writeQueue(queue: OfflineOperation[]) {
  if (queue.length > MAX_QUEUE_OPERATIONS) throw new OfflineQueueFullError();
  if (new TextEncoder().encode(JSON.stringify(queue)).length > maxSecurePayloadBytes)
    throw new OfflineQueueFullError();
  await SecureJson.write(queueKey, queue);
}

export const OfflineVault = {
  list: readQueue,
  async enqueue(
    input: Omit<
      OfflineOperation,
      "id" | "state" | "attempts" | "created_at" | "updated_at"
    >,
  ) {
    const queue = await readQueue();
    const now = new Date().toISOString();
    const item: OfflineOperation = {
      ...input,
      id: Crypto.randomUUID(),
      state: "PENDING",
      attempts: 0,
      created_at: now,
      updated_at: now,
    };
    await writeQueue([item, ...queue]);
    return item;
  },
  async replace(queue: OfflineOperation[]) {
    await writeQueue(queue);
  },
  async remove(id: string) {
    await writeQueue((await readQueue()).filter((item) => item.id !== id));
  },
  settings: () => SecureJson.read<SyncSettings>(settingsKey, defaults),
  async saveSettings(settings: SyncSettings) {
    await SecureJson.write(settingsKey, settings);
  },
  lastSync: () => SecureStore.getItemAsync(lastSyncKey),
  async setLastSync(value: string) {
    await SecureStore.setItemAsync(lastSyncKey, value, DEVICE_ONLY);
  },
  async clearSensitiveData() {
    await Promise.all([
      SecureJson.remove(queueKey),
      SecureStore.deleteItemAsync(legacyQueueKey),
      SecureStore.deleteItemAsync(lastSyncKey),
    ]);
  },
};
