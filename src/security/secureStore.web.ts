/**
 * Browser-preview stand-in for expo-secure-store (which throws on web).
 * sessionStorage keeps demo tokens for the tab only; nothing here ships in
 * the Android/iOS bundles (Metro picks the .web.ts file for web alone).
 */
export const WHEN_UNLOCKED_THIS_DEVICE_ONLY = "WHEN_UNLOCKED_THIS_DEVICE_ONLY";
export const AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY = "AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY";
export type SecureStoreOptions = { keychainAccessible?: string; requireAuthentication?: boolean };

const store = (): Storage | null => {
  try {
    return typeof sessionStorage !== "undefined" ? sessionStorage : null;
  } catch {
    return null;
  }
};
const memory = new Map<string, string>();

export async function getItemAsync(key: string): Promise<string | null> {
  const s = store();
  return s ? s.getItem(key) : (memory.get(key) ?? null);
}
export async function setItemAsync(key: string, value: string, _options?: SecureStoreOptions): Promise<void> {
  const s = store();
  if (s) s.setItem(key, value);
  else memory.set(key, value);
}
export async function deleteItemAsync(key: string, _options?: SecureStoreOptions): Promise<void> {
  const s = store();
  if (s) s.removeItem(key);
  else memory.delete(key);
}
export async function isAvailableAsync(): Promise<boolean> {
  return true;
}
