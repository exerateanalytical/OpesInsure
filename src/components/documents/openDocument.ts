import { Linking } from "react-native";
import { router } from "expo-router";
import { environmentConfig } from "@/config/environment";
import { isAllowedDocumentUrl, viewerParams, type ViewerSource } from "@/lib/documentViewer";

/**
 * Opens a PDF in the in-app viewer (app/documents/view.tsx) instead of
 * handing it to the system browser, which on Android cannot show PDFs and
 * cannot send the bearer token our document endpoints require.
 */
export function openDocument(source: ViewerSource, title: string, fileName?: string) {
  router.push({ pathname: "/documents/view", params: viewerParams(source, title, fileName) });
}

/**
 * Convenience for the common "signed or API https URL" case. The viewer only
 * accepts our API host; any other https link (e.g. an insurer's own product
 * sheet) opens in the system browser instead.
 */
export function openDocumentUrl(url: string, title: string, fileName?: string) {
  if (isAllowedDocumentUrl(url, environmentConfig.apiBaseUrl)) {
    openDocument({ kind: "url", url }, title, fileName);
    return;
  }
  if (/^https:\/\//i.test(url)) void Linking.openURL(url).catch(() => undefined);
}
