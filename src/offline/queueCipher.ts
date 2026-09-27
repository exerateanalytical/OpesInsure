import CryptoJS from "crypto-js";

/**
 * Encrypt-then-MAC envelope for the offline queue file (OPS-08).
 * AES-256-CBC + HMAC-SHA256 with independent 32-byte keys. The caller
 * supplies the key (kept in SecureStore) and a fresh random IV, so this
 * module never needs a JS random source. Pure: unit-tested under node.
 *
 * Envelope: "oq1:" + ivHex + ":" + ciphertextBase64 + ":" + macHex
 */
const PREFIX = "oq1";
export const QUEUE_KEY_BYTES = 64; // 32 enc + 32 mac
export const QUEUE_IV_BYTES = 16;

const hex = (h: string) => CryptoJS.enc.Hex.parse(h);

function split(keyHex: string) {
  if (!/^[0-9a-f]{128}$/i.test(keyHex)) throw new Error("QUEUE_KEY_INVALID");
  return { enc: hex(keyHex.slice(0, 64)), mac: hex(keyHex.slice(64)) };
}

export function sealQueue(plaintext: string, keyHex: string, ivHex: string): string {
  if (!/^[0-9a-f]{32}$/i.test(ivHex)) throw new Error("QUEUE_IV_INVALID");
  const { enc, mac } = split(keyHex);
  const ct = CryptoJS.AES.encrypt(plaintext, enc, {
    iv: hex(ivHex),
    mode: CryptoJS.mode.CBC,
    padding: CryptoJS.pad.Pkcs7,
  }).ciphertext.toString(CryptoJS.enc.Base64);
  const body = `${PREFIX}:${ivHex.toLowerCase()}:${ct}`;
  return `${body}:${CryptoJS.HmacSHA256(body, mac).toString(CryptoJS.enc.Hex)}`;
}

/** Returns null when the envelope is malformed or was tampered with. */
export function openQueue(envelope: string, keyHex: string): string | null {
  const parts = envelope.split(":");
  if (parts.length !== 4 || parts[0] !== PREFIX) return null;
  const [, ivHex, ct, tag] = parts as [string, string, string, string];
  const { enc, mac } = split(keyHex);
  const expected = CryptoJS.HmacSHA256(`${PREFIX}:${ivHex}:${ct}`, mac).toString(CryptoJS.enc.Hex);
  if (!constantTimeEqual(expected, tag.toLowerCase())) return null;
  try {
    const params = CryptoJS.lib.CipherParams.create({ ciphertext: CryptoJS.enc.Base64.parse(ct) });
    return CryptoJS.AES.decrypt(params, enc, {
      iv: hex(ivHex),
      mode: CryptoJS.mode.CBC,
      padding: CryptoJS.pad.Pkcs7,
    }).toString(CryptoJS.enc.Utf8);
  } catch {
    return null;
  }
}

function constantTimeEqual(a: string, b: string) {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

export const bytesToHex = (bytes: Uint8Array) =>
  Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");

/** Pending-count nudge: warn well before the hard cap so agents sync in time. */
export function queueHealth(count: number, max: number) {
  const ratio = max > 0 ? count / max : 1;
  return { count, max, level: ratio >= 1 ? "full" : ratio >= 0.8 ? "near_cap" : count > 0 ? "pending" : "empty" } as const;
}
