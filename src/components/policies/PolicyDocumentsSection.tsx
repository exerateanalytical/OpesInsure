import React, { useCallback, useEffect, useState } from "react";
import { Linking, Pressable, Text, View } from "react-native";
import { Archive, FileText, History } from "lucide-react-native";
import { Button, Card, StatusChip } from "@/components/ui";
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
import { colors } from "@/theme/tokens";

/**
 * Policy "Documents" tab: documents grouped by stage (Policy pack,
 * Certificates, Servicing, Claims, Financial) with status badge, language,
 * issue date and verification code; replaced / revoked / superseded ones
 * only under History; one "Download policy pack" (ZIP) action. Customer
 * uploads are listed apart and never presented as insurer-issued.
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

  const row = (d: IssuedDocument) => (
    <Pressable key={d.id} accessibilityRole="button" onPress={() => void Linking.openURL(d.download_url)} style={{ paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: "#E5E7EB", gap: 4 }}>
      <View style={[ps.row, { justifyContent: "space-between" }]}>
        <View style={[ps.row, { flex: 1 }]}>
          <FileText size={16} color={colors.blue600} />
          <Text style={[ps.title, { fontSize: 15, flex: 1 }]}>{documentTitle(d, language)}</Text>
        </View>
        <StatusChip label={td(statusKey(d.status), d.status)} tone={statusTone(d.status)} />
      </View>
      <Text style={ps.meta}>
        {[d.document_number, d.language ? t(`docLanguage_${d.language}` as CopyKey) : null, d.issued_at ? t("docIssuedOn", { date: date(d.issued_at) }) : null].filter(Boolean).join(" · ")}
      </Text>
      {d.verification_code ? <Text style={ps.meta}>{t("docVerificationCode", { code: d.verification_code })}</Text> : null}
      {d.is_carrier_original ? <Text style={ps.meta}>{t("docCarrierOriginal")}</Text> : null}
      {d.replaced_by ? <Text style={ps.meta}>{t("docReplacedBy", { ref: d.replaced_by })}</Text> : null}
    </Pressable>
  );

  return (
    <Card>
      <Text style={ps.title}>{t("docTabTitle")}</Text>
      <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
        {DOCUMENT_GROUPS.map((g) => {
          const count = currentOnly(data?.groups.find((x) => x.group === g)?.documents ?? []).length;
          return (
            <Pressable key={g} accessibilityRole="tab" accessibilityState={{ selected: g === group }} onPress={() => setGroup(g)}
              style={{ paddingHorizontal: 12, paddingVertical: 6, borderRadius: 16, backgroundColor: g === group ? colors.blue600 : "#EEF2F7" }}>
              <Text style={{ color: g === group ? "#FFFFFF" : "#1F2937", fontWeight: "600" }}>{t(groupKey(g))}{count ? ` (${count})` : ""}</Text>
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
        <View style={{ marginTop: 8 }}>
          <Text style={[ps.title, { fontSize: 15 }]}>{t("docYourUploads")}</Text>
          <Text style={ps.meta}>{t("docYourUploadsHint")}</Text>
          {data.evidence.map((e) => (
            <Text key={e.id} style={ps.meta}>{td(e.category, e.category)}{e.uploaded_at ? ` · ${date(e.uploaded_at)}` : ""}</Text>
          ))}
        </View>
      ) : null}
    </Card>
  );
}
