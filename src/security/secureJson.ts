import * as SecureStore from "@/security/secureStore";
import { chunkKey, countKey, joinChunks, splitChunks } from "@/lib/chunking";

/**
 * Device-bound, chunked JSON storage on top of SecureStore. Every item uses
 * WHEN_UNLOCKED_THIS_DEVICE_ONLY, so nothing migrates through iCloud/device
 * backups. Used for PII caches (session bootstrap, profile extras) and the
 * offline queue, which used to be capped at one 6000-byte key.
 */
export const DEVICE_ONLY = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };

export const SecureJson = {
  async read<T>(key: string, fallback: T): Promise<T> {
    try {
      const count = Number(await SecureStore.getItemAsync(countKey(key)));
      if (!Number.isInteger(count) || count <= 0) return fallback;
      const parts = await Promise.all(
        Array.from({ length: count }, (_, i) => SecureStore.getItemAsync(chunkKey(key, i))),
      );
      const raw = joinChunks(parts);
      return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch {
      return fallback;
    }
  },
  async write(key: string, value: unknown) {
    const parts = splitChunks(JSON.stringify(value));
    const previous = Number(await SecureStore.getItemAsync(countKey(key))) || 0;
    for (let i = 0; i < parts.length; i++)
      await SecureStore.setItemAsync(chunkKey(key, i), parts[i] ?? "", DEVICE_ONLY);
    await SecureStore.setItemAsync(countKey(key), String(parts.length), DEVICE_ONLY);
    for (let i = parts.length; i < previous; i++)
      await SecureStore.deleteItemAsync(chunkKey(key, i));
  },
  async remove(key: string) {
    const count = Number(await SecureStore.getItemAsync(countKey(key)).catch(() => null)) || 0;
    await SecureStore.deleteItemAsync(countKey(key)).catch(() => undefined);
    for (let i = 0; i < count; i++)
      await SecureStore.deleteItemAsync(chunkKey(key, i)).catch(() => undefined);
  },
};
