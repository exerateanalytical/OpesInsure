import * as Crypto from "expo-crypto";
import * as FileSystem from "expo-file-system/legacy";
import * as SecureStore from "@/security/secureStore";
import { OfflineOperation, SyncSettings } from "@/offline/types";
import { DEVICE_ONLY, SecureJson } from "@/security/secureJson";
import { localizeErrorCode } from "@/api/client";
import { bytesToHex, openQueue, QUEUE_IV_BYTES, QUEUE_KEY_BYTES, sealQueue } from "@/offline/queueCipher";

/**
 * Device-encrypted offline queue (OPS-08). Only a random 64-byte key lives in
 * SecureStore (WHEN_UNLOCKED_THIS_DEVICE_ONLY); the queue itself is an
 * AES-256-CBC + HMAC-SHA256 envelope in the app's private documents
 * directory, so a full field day no longer rewrites dozens of keystore
 * chunks per action. Older queues (v2 chunked SecureJson, v1 single key)
 * migrate on first read. Where no file system exists (web) the v2 SecureJson
 * store is kept. Callers get OFFLINE_QUEUE_FULL at the cap.
 */
const queueKey = "opesinsure.offline.queue.v2";
const legacyQueueKey = "opesinsure.offline.queue.v1";
const cipherKeyKey = "opesinsure.offline.queue_key.v3";
const queueFileName = "opesinsure-offline-queue.v3.enc";
const settingsKey = "opesinsure.offline.settings.v1";
const lastSyncKey = "opesinsure.offline.last_sync.v1";
/** A field day for a busy agent (~150 actions) with headroom. */
export const MAX_QUEUE_OPERATIONS = 300;
/** Encoded queue size limit (file-backed; media is never queued inline). */
export const maxSecurePayloadBytes = 2_000_000;
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

function queueFile(): string | null {
  try {
    const dir = FileSystem.documentDirectory;
    return dir ? `${dir}${queueFileName}` : null;
  } catch {
    return null;
  }
}

async function cipherKey(create: boolean): Promise<string | null> {
  const existing = await SecureStore.getItemAsync(cipherKeyKey).catch(() => null);
  if (existing || !create) return existing;
  const key = bytesToHex(await Crypto.getRandomBytesAsync(QUEUE_KEY_BYTES));
  await SecureStore.setItemAsync(cipherKeyKey, key, DEVICE_ONLY);
  return key;
}

async function readFileQueue(path: string): Promise<OfflineOperation[] | null> {
  const info = await FileSystem.getInfoAsync(path).catch(() => null);
  if (!info?.exists) return null;
  const key = await cipherKey(false);
  if (!key) return [];
  const plain = openQueue(await FileSystem.readAsStringAsync(path), key);
  if (!plain) return [];
  try {
    const parsed = JSON.parse(plain) as unknown;
    return Array.isArray(parsed) ? (parsed as OfflineOperation[]) : [];
  } catch {
    return [];
  }
}

async function writeFileQueue(path: string, queue: OfflineOperation[]) {
  const key = (await cipherKey(true)) as string;
  const iv = bytesToHex(await Crypto.getRandomBytesAsync(QUEUE_IV_BYTES));
  const tmp = `${path}.tmp`;
  await FileSystem.writeAsStringAsync(tmp, sealQueue(JSON.stringify(queue), key, iv));
  await FileSystem.moveAsync({ from: tmp, to: path });
}

async function readLegacyQueues(): Promise<OfflineOperation[] | null> {
  const v2 = await SecureJson.read<OfflineOperation[] | null>(queueKey, null);
  if (v2) return v2;
  const legacy = await SecureStore.getItemAsync(legacyQueueKey).catch(() => null);
  if (!legacy) return null;
  try {
    return JSON.parse(legacy) as OfflineOperation[];
  } catch {
    return null;
  }
}

async function readQueue(): Promise<OfflineOperation[]> {
  const path = queueFile();
  if (path) {
    const stored = await readFileQueue(path);
    if (stored) return stored;
  }
  const legacy = await readLegacyQueues();
  if (!legacy) return [];
  // One-time migration: v1/v2 keystore queue -> encrypted file (or v2 on web).
  try {
    if (path) {
      await writeFileQueue(path, legacy);
      await SecureJson.remove(queueKey);
    } else {
      await SecureJson.write(queueKey, legacy);
    }
    await SecureStore.deleteItemAsync(legacyQueueKey).catch(() => undefined);
  } catch {
    // Keep the old copy; the next read retries the migration.
  }
  return legacy;
}

async function writeQueue(queue: OfflineOperation[]) {
  if (queue.length > MAX_QUEUE_OPERATIONS) throw new OfflineQueueFullError();
  if (new TextEncoder().encode(JSON.stringify(queue)).length > maxSecurePayloadBytes)
    throw new OfflineQueueFullError();
  const path = queueFile();
  if (path) await writeFileQueue(path, queue);
  else await SecureJson.write(queueKey, queue);
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
      SecureStore.deleteItemAsync(cipherKeyKey).catch(() => undefined),
      (async () => {
        const path = queueFile();
        if (path) await FileSystem.deleteAsync(path, { idempotent: true }).catch(() => undefined);
      })(),
      SecureStore.deleteItemAsync(lastSyncKey),
    ]);
  },
};
