/**
 * Pure helpers for storing a JSON value across several SecureStore items.
 * SecureStore warns above ~2 KB per item (and some Android keystores fail
 * well before that), so large values are split into fixed-size chunks.
 * No imports: this file is unit-tested directly under node.
 */
export const SECURE_CHUNK_CHARS = 1800;

export function splitChunks(value: string, size = SECURE_CHUNK_CHARS): string[] {
  if (size <= 0) throw new Error("CHUNK_SIZE_INVALID");
  if (value.length === 0) return [""];
  const out: string[] = [];
  for (let i = 0; i < value.length; i += size) out.push(value.slice(i, i + size));
  return out;
}

/** Joins chunks back; any missing chunk means the value is corrupt → null. */
export function joinChunks(parts: (string | null | undefined)[]): string | null {
  if (parts.some((p) => typeof p !== "string")) return null;
  return (parts as string[]).join("");
}

export const chunkKey = (key: string, index: number) => `${key}.c${index}`;
export const countKey = (key: string) => `${key}.n`;
