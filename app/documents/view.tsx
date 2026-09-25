import React, { useCallback, useEffect, useMemo, useState } from "react";
import { ActivityIndicator, Linking, Platform, StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { WebView } from "react-native-webview";
import * as FileSystem from "expo-file-system/legacy";
import * as Sharing from "expo-sharing";
import { Download, ExternalLink, Share2 } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { ErrorState } from "@/components/StatePanel";
import { api } from "@/api/client";
import { QuoteWorkflowApi } from "@/api/workflow";
import { environmentConfig } from "@/config/environment";
import { useTranslation } from "@/i18n";
import { apiPathFor, formatBytes, needsBearer, parseViewerSource, safeFileName, viewerHtml } from "@/lib/documentViewer";
import { colors, space, type } from "@/theme/tokens";

const toBase64 = (blob: Blob) =>
  new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error("READ_FAILED"));
    reader.onloadend = () => resolve(String(reader.result ?? "").replace(/^data:[^,]*,/, ""));
    reader.readAsDataURL(blob);
  });

/**
 * In-app PDF viewer: fetches the document (with the bearer token when it is
 * one of our API endpoints), renders every page responsively with pdf.js in a
 * WebView, and offers Save to device (Android folder picker) and Share.
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
  const [error, setError] = useState<unknown>(null);
  const [renderError, setRenderError] = useState<string | null>(null);
  const [busy, setBusy] = useState<"save" | "share" | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    setRenderError(null);
    setBase64(null);
    if (!source) {
      setError(new Error(t("docViewerBadLink")));
      return;
    }
    try {
      if (source.kind === "quote") {
        const doc = await QuoteWorkflowApi.document(source.quoteId);
        setBase64(doc.dataUri.replace(/^data:[^,]*,/, ""));
        setBytes(doc.bytes);
        return;
      }
      let blob: Blob;
      if (needsBearer(source.url, environmentConfig.apiBaseUrl)) {
        blob = await api<Blob>(apiPathFor(source.url, environmentConfig.apiBaseUrl), { raw: true, headers: { Accept: "application/pdf" }, timeoutMs: 45000 });
      } else {
        const res = await fetch(source.url, { headers: { Accept: "application/pdf" } });
        if (!res.ok) throw new Error(`HTTP_${res.status}`);
        blob = await res.blob();
      }
      setBytes(blob.size);
      setBase64(await toBase64(blob));
    } catch (e) {
      setError(e);
    }
  }, [source, t]);

  useEffect(() => {
    void load();
  }, [load]);

  const writeToCache = async () => {
    const dir = FileSystem.cacheDirectory ?? FileSystem.documentDirectory ?? "";
    const uri = `${dir}${fileName}`;
    await FileSystem.writeAsStringAsync(uri, base64 ?? "", { encoding: FileSystem.EncodingType.Base64 });
    return uri;
  };

  const share = async () => {
    if (!base64) return;
    setBusy("share");
    setNotice(null);
    try {
      const uri = await writeToCache();
      if (await Sharing.isAvailableAsync()) await Sharing.shareAsync(uri, { mimeType: "application/pdf", dialogTitle: heading, UTI: "com.adobe.pdf" });
      else setNotice(t("docViewerShareUnavailable"));
    } catch {
      setNotice(t("docViewerSaveFailed"));
    } finally {
      setBusy(null);
    }
  };

  /** Android: user picks a folder (Downloads) and the PDF is written there. iOS: the share sheet's "Save to Files". */
  const save = async () => {
    if (!base64) return;
    setBusy("save");
    setNotice(null);
    try {
      if (Platform.OS === "android") {
        const perm = await FileSystem.StorageAccessFramework.requestDirectoryPermissionsAsync();
        if (!perm.granted) return;
        const target = await FileSystem.StorageAccessFramework.createFileAsync(perm.directoryUri, fileName.replace(/\.pdf$/i, ""), "application/pdf");
        await FileSystem.writeAsStringAsync(target, base64, { encoding: FileSystem.EncodingType.Base64 });
        setNotice(t("docViewerSaved", { name: fileName }));
      } else {
        await share();
      }
    } catch {
      setNotice(t("docViewerSaveFailed"));
    } finally {
      setBusy(null);
    }
  };

  const html = useMemo(() => (base64 ? viewerHtml(base64, { background: colors.neutral50, accent: colors.blue600 }) : null), [base64]);
  const meta = [pages ? t("docViewerPages", { count: pages }) : null, formatBytes(bytes)].filter(Boolean).join(" · ");

  return (
    <Screen scroll={false} style={styles.body}>
      <View style={styles.header}>
        <AppHeader title={heading} subtitle={meta || undefined} back />
      </View>
      {error ? (
        <View style={styles.pad}>
          <ErrorState error={error} onRetry={() => void load()} />
          {source?.kind === "url" ? (
            <Button label={t("docViewerOpenExternal")} icon={ExternalLink} variant="secondary" onPress={() => void Linking.openURL(source.url).catch(() => undefined)} />
          ) : null}
        </View>
      ) : !html ? (
        <View style={styles.loading} accessibilityRole="progressbar" accessibilityLabel={t("docViewerLoading")}>
          <ActivityIndicator size="large" color={colors.blue600} />
          <Text style={styles.loadingText}>{t("docViewerLoading")}</Text>
        </View>
      ) : Platform.OS === "web" ? (
        <View style={styles.pad}>
          <Card>
            <Text style={styles.loadingText}>{t("docViewerWebHint")}</Text>
          </Card>
        </View>
      ) : (
        <View style={styles.viewer}>
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
          {renderError ? <Text accessibilityRole="alert" style={styles.renderError}>{t("docViewerRenderFailed")}</Text> : null}
        </View>
      )}
      {html ? (
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
  actions: { paddingHorizontal: space.x5, paddingTop: space.x3, gap: space.x2, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.neutral200, backgroundColor: colors.neutral50 },
  actionRow: { flexDirection: "row", gap: space.x3 },
  notice: { ...type.meta, color: colors.successText, textAlign: "center" },
});
