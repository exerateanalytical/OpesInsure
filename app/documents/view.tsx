import React, { createElement, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ActivityIndicator, Linking, Platform, StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { WebView } from "react-native-webview";
import * as FileSystem from "expo-file-system/legacy";
import * as Sharing from "expo-sharing";
import { Download, ExternalLink, Share2 } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { EmptyState, ErrorState } from "@/components/StatePanel";
import { api } from "@/api/client";
import { QuoteWorkflowApi } from "@/api/workflow";
import { environmentConfig } from "@/config/environment";
import { useTranslation } from "@/i18n";
import {
  apiPathFor,
  cacheKey,
  detectKind,
  formatBytes,
  imageMime,
  needsBearer,
  pageIndicator,
  parseViewerSource,
  quoteNotReady,
  safeFileName,
  viewerHtml,
  type DocumentKind,
} from "@/lib/documentViewer";
import { loadNativePdf } from "@/components/documents/nativePdf";
import { ZoomableImage } from "@/components/documents/ZoomableImage";
import { colors, radius, space, type } from "@/theme/tokens";

const toBase64 = (blob: Blob) =>
  new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error("READ_FAILED"));
    reader.onloadend = () => resolve(String(reader.result ?? "").replace(/^data:[^,]*,/, ""));
    reader.readAsDataURL(blob);
  });

const cacheDir = () => `${FileSystem.cacheDirectory ?? FileSystem.documentDirectory ?? ""}documents/`;

const base64ToBytes = (b64: string) => {
  const bin = atob(b64);
  const buf = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
  return buf;
};

/**
 * In-app document viewer: fetches the document (with the bearer token when it
 * is one of our API endpoints) and renders it natively: PDFs with
 * react-native-pdf (fit-to-width, pinch zoom, "3 / 7" page indicator), images
 * with a native zoomable Image. A copy is cached so the document reopens
 * offline. Builds without the native PDF module (OTA JS on an older APK) fall
 * back to the pdf.js WebView; the web preview uses the browser's PDF iframe.
 * Save to device (Android folder picker) and Share are kept.
 */
export default function DocumentView() {
  const { t } = useTranslation();
  const { source: rawSource, title, fileName: rawName } = useLocalSearchParams<{ source?: string; title?: string; fileName?: string }>();
  const source = useMemo(() => parseViewerSource(rawSource), [rawSource]);
  const heading = title || t("docViewerTitle");
  const fileName = safeFileName(rawName || title);
  const [base64, setBase64] = useState<string | null>(null);
  const [bytes, setBytes] = useState<number | null>(null);
  const [pages, setPages] = useState<number | null>(null);
  const [page, setPage] = useState(1);
  const [kind, setKind] = useState<DocumentKind>("pdf");
  const [fileUri, setFileUri] = useState<string | null>(null);
  const [offline, setOffline] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [renderError, setRenderError] = useState<string | null>(null);
  const [busy, setBusy] = useState<"save" | "share" | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const NativePdf = useMemo(() => loadNativePdf(), []);

  // `t` is a new function every render; keep it out of load's deps or the PDF is refetched forever.
  const tRef = useRef(t);
  tRef.current = t;
  const load = useCallback(async () => {
    setError(null);
    setRenderError(null);
    setBase64(null);
    setFileUri(null);
    setOffline(false);
    setPage(1);
    if (!source) {
      setError(new Error(tRef.current("docViewerBadLink")));
      return;
    }
    const cachedUri = Platform.OS === "web" ? null : `${cacheDir()}${cacheKey(source)}`;
    /** Keep a local copy: the native renderer reads files, and the document reopens offline. */
    const persist = async (b64: string) => {
      if (!cachedUri) return;
      try {
        await FileSystem.makeDirectoryAsync(cacheDir(), { intermediates: true }).catch(() => undefined);
        await FileSystem.writeAsStringAsync(cachedUri, b64, { encoding: FileSystem.EncodingType.Base64 });
        setFileUri(cachedUri);
      } catch {
        setFileUri(null);
      }
    };
    try {
      let b64: string;
      let mime: string | null = null;
      if (source.kind === "quote") {
        const doc = await QuoteWorkflowApi.document(source.quoteId);
        b64 = doc.dataUri.replace(/^data:[^,]*,/, "");
        mime = doc.dataUri.match(/^data:([^;,]*)/)?.[1] ?? null;
        setBytes(doc.bytes);
      } else {
        const accept = "application/pdf, image/*;q=0.8";
        let blob: Blob;
        if (needsBearer(source.url, environmentConfig.apiBaseUrl)) {
          blob = await api<Blob>(apiPathFor(source.url, environmentConfig.apiBaseUrl), { raw: true, headers: { Accept: accept }, timeoutMs: 45000 });
        } else {
          const res = await fetch(source.url, { headers: { Accept: accept } });
          if (!res.ok) throw new Error(`HTTP_${res.status}`);
          blob = await res.blob();
        }
        setBytes(blob.size);
        mime = blob.type || null;
        b64 = await toBase64(blob);
      }
      setKind(detectKind(b64, mime));
      await persist(b64);
      setBase64(b64);
    } catch (e) {
      // Offline (or the signed link expired): reopen the last saved copy, if any.
      if (cachedUri) {
        try {
          const info = await FileSystem.getInfoAsync(cachedUri);
          if (info.exists) {
            const b64 = await FileSystem.readAsStringAsync(cachedUri, { encoding: FileSystem.EncodingType.Base64 });
            setKind(detectKind(b64));
            setBytes(info.size ?? null);
            setFileUri(cachedUri);
            setOffline(true);
            setBase64(b64);
            return;
          }
        } catch {
          // fall through to the error state
        }
      }
      setError(e);
    }
  }, [source]);

  useEffect(() => {
    void load();
  }, [load]);

  const mimeType = kind === "image" && base64 ? imageMime(base64) : "application/pdf";
  const outName = kind === "image" ? fileName.replace(/\.pdf$/i, mimeType === "image/png" ? ".png" : ".jpg") : fileName;

  const writeToCache = async () => {
    const dir = FileSystem.cacheDirectory ?? FileSystem.documentDirectory ?? "";
    const uri = `${dir}${outName}`;
    await FileSystem.writeAsStringAsync(uri, base64 ?? "", { encoding: FileSystem.EncodingType.Base64 });
    return uri;
  };

  /** Web: the document as a File (same bytes the native viewer shares). */
  const webFile = () => new File([base64ToBytes(base64 ?? "")], outName, { type: mimeType });
  const webDownload = () => {
    const url = URL.createObjectURL(webFile());
    const a = document.createElement("a");
    a.href = url;
    a.download = outName;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
    setNotice(t("docViewerSaved", { name: outName }));
  };

  const share = async () => {
    if (!base64) return;
    setBusy("share");
    setNotice(null);
    try {
      if (Platform.OS === "web") {
        const file = webFile();
        const nav = navigator as Navigator & { canShare?: (d: { files: File[] }) => boolean };
        if (nav.share && nav.canShare?.({ files: [file] })) await nav.share({ files: [file], title: heading });
        else webDownload();
        return;
      }
      const uri = await writeToCache();
      if (await Sharing.isAvailableAsync()) await Sharing.shareAsync(uri, { mimeType, dialogTitle: heading, UTI: kind === "image" ? "public.image" : "com.adobe.pdf" });
      else setNotice(t("docViewerShareUnavailable"));
    } catch {
      setNotice(t("docViewerSaveFailed"));
    } finally {
      setBusy(null);
    }
  };

  /** Android: user picks a folder (Downloads) and the file is written there. iOS: the share sheet's "Save to Files". */
  const save = async () => {
    if (!base64) return;
    setBusy("save");
    setNotice(null);
    try {
      if (Platform.OS === "web") return webDownload();
      if (Platform.OS === "android") {
        const perm = await FileSystem.StorageAccessFramework.requestDirectoryPermissionsAsync();
        if (!perm.granted) return;
        const target = await FileSystem.StorageAccessFramework.createFileAsync(perm.directoryUri, outName.replace(/\.(pdf|png|jpg)$/i, ""), mimeType);
        await FileSystem.writeAsStringAsync(target, base64, { encoding: FileSystem.EncodingType.Base64 });
        setNotice(t("docViewerSaved", { name: outName }));
      } else {
        await share();
      }
    } catch {
      setNotice(t("docViewerSaveFailed"));
    } finally {
      setBusy(null);
    }
  };

  const useNative = !!NativePdf && kind === "pdf" && !!fileUri;
  /** pdf.js WebView: only the fallback for builds that lack the native PDF module. */
  const html = useMemo(
    () => (base64 && kind === "pdf" && !useNative && Platform.OS !== "web" ? viewerHtml(base64, { background: colors.neutral50, accent: colors.blue600 }) : null),
    [base64, kind, useNative],
  );
  const webUrl = useMemo(() => {
    if (Platform.OS !== "web" || !base64) return null;
    try {
      return URL.createObjectURL(new Blob([base64ToBytes(base64)], { type: mimeType }));
    } catch {
      return null;
    }
  }, [base64, mimeType]);
  useEffect(
    () => () => {
      if (webUrl) URL.revokeObjectURL(webUrl);
    },
    [webUrl],
  );
  const ready = !!base64;
  const meta = [pages ? t("docViewerPages", { count: pages }) : null, formatBytes(bytes), offline ? t("docViewerOffline") : null].filter(Boolean).join(" · ");

  const hint = (text: string) => (
    <View style={styles.pad}>
      <Card>
        <Text style={styles.loadingText}>{text}</Text>
      </Card>
    </View>
  );

  const renderDocument = () => {
    if (kind === "image" && base64) {
      return <ZoomableImage uri={fileUri ?? `data:${mimeType};base64,${base64}`} accessibilityLabel={heading} />;
    }
    if (kind !== "pdf") return hint(t("docViewerRenderFailed"));
    if (Platform.OS === "web") {
      return webUrl
        ? createElement("iframe", { src: webUrl, title: heading, style: { flex: 1, width: "100%", height: "100%", border: 0, backgroundColor: colors.neutral50 } })
        : hint(t("docViewerWebHint"));
    }
    if (useNative && NativePdf && fileUri) {
      return (
        <NativePdf
          source={{ uri: fileUri, cache: false }}
          style={styles.web}
          fitPolicy={0}
          spacing={10}
          minScale={1}
          maxScale={5}
          enableAntialiasing
          trustAllCerts={false}
          onLoadComplete={(n) => setPages(n)}
          onPageChanged={(p, n) => {
            setPage(p);
            setPages(n);
          }}
          onError={(e) => setRenderError(String((e as { message?: string } | null)?.message ?? e ?? "RENDER_FAILED"))}
          renderActivityIndicator={() => <ActivityIndicator size="large" color={colors.blue600} />}
        />
      );
    }
    return html ? (
      <WebView
        originWhitelist={["*"]}
        source={{ html, baseUrl: "https://insurance.opesdatacenter.tech/" }}
        style={styles.web}
        javaScriptEnabled
        domStorageEnabled={false}
        allowFileAccess={false}
        allowUniversalAccessFromFileURLs={false}
        setSupportMultipleWindows={false}
        onShouldStartLoadWithRequest={(req) => req.url.startsWith("about:") || req.url.startsWith("data:") || req.url.includes("insurance.opesdatacenter.tech")}
        onMessage={(e) => {
          try {
            const m = JSON.parse(e.nativeEvent.data) as { type: string; count?: number; message?: string };
            if (m.type === "pages" && m.count) setPages(m.count);
            if (m.type === "error") setRenderError(m.message ?? "RENDER_FAILED");
          } catch {
            // ignore foreign messages
          }
        }}
      />
    ) : null;
  };

  return (
    <Screen scroll={false} style={styles.body}>
      <View style={styles.header}>
        <AppHeader title={heading} subtitle={meta || undefined} back />
      </View>
      {error ? (
        <View style={styles.pad}>
          {quoteNotReady(source, error) ? (
            <EmptyState title={t("ctQuotePdfPendingTitle")} message={t("ctQuotePdfPendingBody")} action={t("retry")} onPress={() => void load()} />
          ) : (
            <ErrorState error={error} onRetry={() => void load()} />
          )}
          {source?.kind === "url" ? (
            <Button label={t("docViewerOpenExternal")} icon={ExternalLink} variant="secondary" onPress={() => void Linking.openURL(source.url).catch(() => undefined)} />
          ) : null}
        </View>
      ) : !ready ? (
        <View style={styles.loading} accessibilityRole="progressbar" accessibilityLabel={t("docViewerLoading")}>
          <ActivityIndicator size="large" color={colors.blue600} />
          <Text style={styles.loadingText}>{t("docViewerLoading")}</Text>
        </View>
      ) : (
        <View style={styles.viewer}>
          {renderDocument()}
          {useNative && pages && pages > 1 ? (
            <View pointerEvents="none" style={styles.pageBadge} accessibilityLiveRegion="polite" accessibilityLabel={t("docViewerPageOf", { page, total: pages })}>
              <Text style={styles.pageBadgeText}>{pageIndicator(page, pages)}</Text>
            </View>
          ) : null}
          {renderError ? <Text accessibilityRole="alert" style={styles.renderError}>{t("docViewerRenderFailed")}</Text> : null}
        </View>
      )}
      {ready ? (
        <View style={styles.actions}>
          {notice ? <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={styles.notice}>{notice}</Text> : null}
          <View style={styles.actionRow}>
            <View style={styles.flex}>
              <Button label={t("docViewerSave")} icon={Download} loading={busy === "save"} disabled={!!busy} onPress={() => void save()} />
            </View>
            <View style={styles.flex}>
              <Button label={t("docViewerShare")} icon={Share2} variant="secondary" loading={busy === "share"} disabled={!!busy} onPress={() => void share()} />
            </View>
          </View>
        </View>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  body: { paddingHorizontal: 0, gap: 0 },
  header: { paddingHorizontal: space.x5 },
  pad: { paddingHorizontal: space.x5, gap: space.x4, paddingTop: space.x4 },
  flex: { flex: 1 },
  viewer: { flex: 1, marginTop: space.x3 },
  web: { flex: 1, backgroundColor: colors.neutral50 },
  loading: { flex: 1, alignItems: "center", justifyContent: "center", gap: space.x3, padding: space.x6 },
  loadingText: { ...type.body, color: colors.neutral600, textAlign: "center" },
  renderError: { ...type.meta, color: colors.dangerText, textAlign: "center", padding: space.x3 },
  pageBadge: {
    position: "absolute",
    top: space.x3,
    alignSelf: "center",
    backgroundColor: "rgba(15,21,53,0.72)",
    borderRadius: radius.pill,
    paddingHorizontal: space.x3,
    paddingVertical: space.x1,
  },
  pageBadgeText: { ...type.meta, color: "#FFFFFF", fontVariant: ["tabular-nums"] },
  actions: { paddingHorizontal: space.x5, paddingTop: space.x3, gap: space.x2, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.neutral200, backgroundColor: colors.neutral50 },
  actionRow: { flexDirection: "row", gap: space.x3 },
  notice: { ...type.meta, color: colors.successText, textAlign: "center" },
});
