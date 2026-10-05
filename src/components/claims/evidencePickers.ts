import * as ImagePicker from "expo-image-picker";
import * as DocumentPicker from "expo-document-picker";
import { withoutRelock } from "@/lib/appLock";
import { videoMime } from "@/lib/evidenceUpload";

/** Longest video a customer can record or pick as claim evidence. */
export const MAX_VIDEO_SECONDS = 60;

export type EvidenceSource = "photo" | "video" | "library" | "document";
export type EvidenceAsset = { uri: string; mimeType?: string | null; name?: string | null; size?: number | null };
/** A picked file waiting for the customer's confirmation before it is uploaded. */
export type PickedEvidence = { asset: EvidenceAsset; kind: "PHOTO" | "VIDEO" | "DOCUMENT"; name: string | null; size: number | null };

/**
 * Camera photo / video, gallery or document picker for claim evidence (claim evidence screen and the
 * new-claim wizard). Returns the picked file, null when cancelled, or "CAMERA_DENIED".
 */
export async function pickEvidence(source: EvidenceSource): Promise<PickedEvidence | null | "CAMERA_DENIED"> {
  if (source === "document") {
    const result = await withoutRelock(() =>
      DocumentPicker.getDocumentAsync({ type: ["application/pdf", "image/jpeg", "image/png"], copyToCacheDirectory: true, multiple: false }),
    );
    const asset = result.assets?.[0];
    if (result.canceled || !asset) return null;
    return { asset: { uri: asset.uri, mimeType: asset.mimeType, name: asset.name, size: asset.size }, kind: "DOCUMENT", name: asset.name ?? null, size: asset.size ?? null };
  }
  if (source === "photo" || source === "video") {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) return "CAMERA_DENIED";
  }
  const options: ImagePicker.ImagePickerOptions = {
    mediaTypes: source === "photo" ? ["images"] : source === "video" ? ["videos"] : ["images", "videos"],
    quality: 0.8,
    videoMaxDuration: MAX_VIDEO_SECONDS,
  };
  const result =
    source === "library"
      ? await withoutRelock(() => ImagePicker.launchImageLibraryAsync(options))
      : await withoutRelock(() => ImagePicker.launchCameraAsync(options));
  const asset = result.assets?.[0];
  if (result.canceled || !asset) return null;
  const video = source === "video" || asset.type === "video";
  return {
    // The real type is kept (a .mov stays video/quicktime); the upload derives it from the name when missing.
    asset: { uri: asset.uri, mimeType: asset.mimeType ?? (video ? videoMime(null, asset.fileName, asset.uri) : "image/jpeg"), name: asset.fileName ?? null, size: asset.fileSize ?? null },
    kind: video ? "VIDEO" : "PHOTO",
    name: asset.fileName ?? null,
    size: asset.fileSize ?? null,
  };
}
