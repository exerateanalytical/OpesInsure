import * as Crypto from "expo-crypto";
import * as DocumentPicker from "expo-document-picker";
import * as ImagePicker from "expo-image-picker";
import { api, apiPage, ApiError } from "@/api/client";
import { translateNow } from "@/i18n";
import { withoutRelock } from "@/lib/appLock";
import { base64ToBytes, bytesToHex, normalizeUploadMime, type UploadMime } from "@/lib/proposalDocuments";

export type PickedFile = { base64: string; mime: UploadMime };
export type PickSource = "camera" | "library" | "file";

/** Pages of GET /mobile/documents searched for an already-stored identical file. */
const DUPLICATE_SEARCH_PAGES = 10;

async function readAsBase64(uri: string): Promise<string> {
  const blob = await (await fetch(uri)).blob();
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error(translateNow("prFileUnreadable")));
    reader.onloadend = () => resolve(String(reader.result ?? "").replace(/^data:[^,]*,/, ""));
    reader.readAsDataURL(blob);
  });
}

/**
 * Camera / photo library return a JPEG (HEIC is converted by the picker at quality < 1, which also keeps
 * phone photos well under the server's size limit); "file" takes a PDF or image from storage. Null = cancelled.
 */
export async function pickUpload(source: PickSource): Promise<PickedFile | null> {
  if (source === "file") {
    const picked = await withoutRelock(() => DocumentPicker.getDocumentAsync({ type: ["image/jpeg", "image/png", "application/pdf"], copyToCacheDirectory: true }));
    const asset = picked.canceled ? null : picked.assets?.[0];
    if (!asset) return null;
    const mime = normalizeUploadMime(asset.mimeType, asset.name);
    if (!mime) throw new Error(translateNow("docUnsupportedType"));
    return { base64: await readAsBase64(asset.uri), mime };
  }
  if (source === "camera") {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) throw new Error(translateNow("cameraPermissionNeeded"));
  }
  const options: ImagePicker.ImagePickerOptions = { mediaTypes: ["images"], quality: 0.6, base64: true };
  const result = source === "camera" ? await withoutRelock(() => ImagePicker.launchCameraAsync(options)) : await withoutRelock(() => ImagePicker.launchImageLibraryAsync(options));
  const asset = result.canceled ? null : result.assets?.[0];
  if (!asset?.base64) return null;
  return { base64: asset.base64, mime: asset.mimeType === "image/png" ? "image/png" : "image/jpeg" };
}

/** The customer's own stored document with this exact content (the server refuses a second copy with 409). */
async function findStoredCopy(base64: string): Promise<string | null> {
  const sha = bytesToHex(await Crypto.digest(Crypto.CryptoDigestAlgorithm.SHA256, base64ToBytes(base64)));
  for (let page = 1; page <= DUPLICATE_SEARCH_PAGES; page++) {
    const res = await apiPage<{ id: string; sha256?: string | null }>("/mobile/documents", page);
    const hit = res.items.find((d) => d.sha256?.toLowerCase() === sha);
    if (hit) return hit.id;
    if (!res.info.hasMore) break;
  }
  return null;
}

/**
 * POST /mobile/documents, or — when this exact file was already sent (e.g. the ID photo used for identity
 * verification) — the id of the stored copy, so it can be linked again instead of failing as a duplicate.
 */
export async function storeDocument(category: string, file: PickedFile): Promise<string> {
  try {
    const doc = await api<{ id: string }>("/mobile/documents", {
      method: "POST",
      body: JSON.stringify({ category: category.slice(0, 48), mime_type: file.mime, file_base64: file.base64 }),
      timeoutMs: 60000,
      idempotent: true,
    });
    return doc.id;
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) {
      const existing = await findStoredCopy(file.base64).catch(() => null);
      if (existing) return existing;
    }
    throw e;
  }
}
