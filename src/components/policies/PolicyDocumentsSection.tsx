import React, { useCallback, useEffect, useState } from "react";
import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { Archive, ChevronRight, Download, Eye, FileBadge, FileText, History, Lock, QrCode, Share2, ShieldCheck, Star } from "lucide-react-native";
import { Button, StatusChip, ripple } from "@/components/ui";
import { Banner, SectionHeading, TintedIcon } from "@/components/design";
import { purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { PolicyDocumentsApi } from "@/api/policyDocuments";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import {
  awaitingCount,
  currentOnly,
  DOCUMENT_GROUPS,
  DocumentGroup,
  documentTitle,
  groupKey,
  IssuedDocument,
  PolicyDocumentsPayload,
  statusKey,
  statusTone,
} from "@/lib/policyDocuments";
import { colors, radius, space, type } from "@/theme/tokens";

/** The certificate / attestation is the document law enforcement asks for: highlighted as primary. */
const isPrimaryDocument = (d: IssuedDocument) => /CERT|ATTEST/i.test(d.document_type_code);

/**
 * Policy "Documents" section: documents grouped by stage (Policy pack,
 * Certificates, Servicing, Claims, Financial) as cards with status badge,
 * language, issue date and verification code, and View / Download / Share /
 * Verify QR actions; replaced / revoked / superseded ones only under History;
 * one "Download policy pack" (ZIP) action. Customer uploads are listed apart
 * and never presented as insurer-issued.
 */
export function PolicyDocumentsSection({ policyId }: { policyId: string }) {
  const { t, td, date, language } = useTranslation();
  const [data, setData] = useState<PolicyDocumentsPayload | null>(null);
  const [group, setGroup] = useState<DocumentGroup>("POLICY_PACK");
  const [showHistory, setShowHistory] = useState(false);
  const [error, setError] = useState(false);
  const [packBusy, setPackBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      setError(false);
      setData(await PolicyDocumentsApi.list(policyId));
    } catch {
      setError(true);
    }
  }, [policyId]);

  useEffect(() => {
    void load();
  }, [load]);

  const downloadPack = async () => {
    setPackBusy(true);
    try {
      const { url } = await PolicyDocumentsApi.packUrl(policyId);
      await Linking.openURL(url);
    } catch {
      setError(true);
    } finally {
      setPackBusy(false);
    }
  };

  const current = data?.groups.find((g) => g.group === group);
  const docs = currentOnly(current?.documents ?? []);
  const history = current?.history ?? [];
  const awaiting = data ? awaitingCount(data) : 0;
  const totalCurrent = data ? data.groups.reduce((n, g) => n + currentOnly(g.documents).length, 0) : 0;
  const primaryId = docs.find(isPrimaryDocument)?.id ?? null;

  const open = (d: IssuedDocument) => openDocumentUrl(d.download_url, documentTitle(d, language), d.document_number ?? undefined);
  const verify = (d: IssuedDocument) => {
    if (d.verification_url) void Linking.openURL(d.verification_url).catch(() => open(d));
  };

  const action = (Icon: typeof Eye, label: string, onPress: () => void, last = false) => (
    <Pressable key={label} accessibilityRole="button" accessibilityLabel={label} onPress={onPress} android_ripple={ripple()} style={({ pressed }) => [st.action, !last && st.actionDivider, pressed && st.pressed]}>
      <Icon size={18} color={colors.blue600} />
      <Text style={st.actionText} numberOfLines={1}>{label}</Text>
    </Pressable>
  );

  const row = (d: IssuedDocument) => {
    const primary = d.id === primaryId;
    const title = documentTitle(d, language);
    const meta = [d.document_number, d.language ? t(`docLanguage_${d.language}` as CopyKey) : null].filter(Boolean).join(" · ");
    const actions = [
      action(Eye, t("docActionView"), () => open(d)),
      action(Download, t("docActionDownload"), () => open(d)),
      // Sharing happens from the in-app viewer (share sheet with the signed PDF).
      action(Share2, t("docActionShare"), () => open(d), !d.verification_url),
      ...(d.verification_url ? [action(QrCode, t("docActionVerify"), () => verify(d), true)] : []),
    ];
    return (
      <View key={d.id} style={[st.docCard, primary && st.docCardPrimary]}>
        <View style={st.docTop}>
          <TintedIcon icon={primary ? FileBadge : /STICKER|VIGNETTE/i.test(d.document_type_code) ? ShieldCheck : FileText} tint={primary ? "gold" : "blue"} size={56} />
          <View style={st.flex}>
            <View style={st.docTitleRow}>
              <Text style={[st.docTitle, st.flex]}>{title}</Text>
              <StatusChip label={td(statusKey(d.status), d.status)} tone={statusTone(d.status)} />
            </View>
            {primary ? (
              <View style={st.primaryChip}>
                <Star size={12} color={colors.gold600} fill={colors.gold500} />
                <Text style={st.primaryChipText}>{t("docPrimary")}</Text>
              </View>
            ) : null}
            {d.issued_at ? <Text style={st.docMeta}>{t("docIssuedOn", { date: date(d.issued_at) })}</Text> : null}
            {meta ? <Text style={st.docMetaSmall}>{meta}</Text> : null}
            {d.verification_code ? <Text style={st.docMetaSmall}>{t("docVerificationCode", { code: d.verification_code })}</Text> : null}
            {d.is_carrier_original ? <Text style={st.docMetaSmall}>{t("docCarrierOriginal")}</Text> : null}
            {d.replaced_by ? <Text style={st.docMetaSmall}>{t("docReplacedBy", { ref: d.replaced_by })}</Text> : null}
          </View>
          <Pressable accessibilityRole="button" accessibilityLabel={title} onPress={() => open(d)} hitSlop={6} style={({ pressed }) => [st.chevron, pressed && st.pressed]}>
            <ChevronRight size={18} color={colors.navy900} />
          </Pressable>
        </View>
        <View style={st.actions}>{actions}</View>
      </View>
    );
  };

  return (
    <View style={st.section}>
      <SectionHeading
        title={t("docTabTitle")}
        right={
          data ? (
            <View style={st.countRow}>
              <FileText size={16} color={colors.blue600} />
              <Text style={st.countText}>{t("docAvailable", { count: totalCurrent })}</Text>
            </View>
          ) : null
        }
      />
      <View accessibilityRole="tablist" style={st.chips}>
        {DOCUMENT_GROUPS.map((g) => {
          const count = currentOnly(data?.groups.find((x) => x.group === g)?.documents ?? []).length;
          const on = g === group;
          return (
            <Pressable key={g} accessibilityRole="tab" accessibilityState={{ selected: on }} onPress={() => setGroup(g)} android_ripple={ripple(on)} style={({ pressed }) => [st.chip, on && st.chipOn, pressed && st.pressed]}>
              <Text style={[st.chipText, on && st.chipTextOn]}>{t(groupKey(g))}{count ? ` (${count})` : ""}</Text>
            </Pressable>
          );
        })}
      </View>
      {error ? <Text style={ps.meta}>{t("docLoadError")}</Text> : null}
      {!data && !error ? <Text style={ps.meta}>{t("docLoading")}</Text> : null}
      {data && !docs.length ? <Text style={ps.meta}>{t("docEmptyGroup")}</Text> : null}
      {docs.map(row)}
      {awaiting ? <Text style={ps.meta}>{t("docAwaitingCarrier", { count: awaiting })}</Text> : null}
      {history.length ? (
        <Button label={showHistory ? t("docHideHistory") : t("docShowHistory", { count: history.length })} icon={History} variant="secondary" onPress={() => setShowHistory((v) => !v)} />
      ) : null}
      {showHistory ? history.map(row) : null}
      {data?.pack_download_url ? <Button label={t("docDownloadPack")} icon={Archive} loading={packBusy} onPress={() => void downloadPack()} /> : null}
      {data?.evidence.length ? (
        <View style={st.uploads}>
          <Text style={[ps.title, { fontSize: 15 }]}>{t("docYourUploads")}</Text>
          <Text style={ps.meta}>{t("docYourUploadsHint")}</Text>
          {data.evidence.map((e) => (
            <Text key={e.id} style={ps.meta}>{td(e.category, e.category)}{e.uploaded_at ? ` · ${date(e.uploaded_at)}` : ""}</Text>
          ))}
        </View>
      ) : null}
      {data ? <Banner icon={Lock} tint="blue" title={t("docSecureTitle")} body={t("docSecureBody")} right={<TintedIcon icon={ShieldCheck} tint="gold" size={40} />} /> : null}
    </View>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  section: { gap: space.x3 },
  countRow: { flexDirection: "row", alignItems: "center", gap: 6 },
  countText: { ...type.meta, color: colors.navy900 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  chip: { paddingHorizontal: 12, minHeight: 40, justifyContent: "center", borderRadius: radius.pill, backgroundColor: colors.neutral100, overflow: "hidden" },
  chipOn: { backgroundColor: colors.blue600 },
  chipText: { ...type.label, color: colors.neutral950 },
  chipTextOn: { color: colors.white },
  docCard: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3 },
  docCardPrimary: { borderColor: colors.gold500, borderWidth: 1.5, backgroundColor: colors.gold50 },
  docTop: { flexDirection: "row", gap: space.x3, alignItems: "flex-start" },
  docTitleRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x2 },
  docTitle: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  primaryChip: { flexDirection: "row", alignItems: "center", gap: 4, alignSelf: "flex-start", backgroundColor: colors.gold100, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 4, marginTop: 6 },
  primaryChipText: { ...type.caption, color: colors.gold600 },
  docMeta: { ...type.body, color: colors.neutral600, marginTop: 6 },
  docMetaSmall: { ...type.meta, color: colors.neutral600, marginTop: 2 },
  chevron: { width: 36, height: 36, borderRadius: 18, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white, alignItems: "center", justifyContent: "center" },
  actions: { flexDirection: "row", borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x2 },
  action: { flex: 1, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 6, minHeight: 40, overflow: "hidden" },
  actionDivider: { borderRightWidth: 1, borderRightColor: colors.neutral200 },
  actionText: { ...type.label, color: colors.navy950, fontSize: 13 },
  uploads: { marginTop: 8, gap: 4 },
});
