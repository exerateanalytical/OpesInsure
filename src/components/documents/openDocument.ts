import { router } from "expo-router";
import { viewerParams, type ViewerSource } from "@/lib/documentViewer";

/**
 * Opens a PDF in the in-app viewer (app/documents/view.tsx) instead of
 * handing it to the system browser, which on Android cannot show PDFs and
 * cannot send the bearer token our document endpoints require.
 */
export function openDocument(source: ViewerSource, title: string, fileName?: string) {
  router.push({ pathname: "/documents/view", params: viewerParams(source, title, fileName) });
}

/** Convenience for the common "signed or API https URL" case. */
export function openDocumentUrl(url: string, title: string, fileName?: string) {
  openDocument({ kind: "url", url }, title, fileName);
}
